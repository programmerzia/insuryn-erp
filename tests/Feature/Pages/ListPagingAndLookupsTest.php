<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Gap audit GA-40: lists no longer stop silently at their page size — policies, claims, receipts, parties and claim payments are paged (`?page=`) with
 * their total, capped lists say how many rows there are, and the selects that loaded whole tables (payer, new producer's party, refund policy, agent)
 * are lookups.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->world = seedInsuranceWorld($this->ctx);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->admin = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->whereKey((string) $this->world['admin'])->firstOrFail());
    asTenant($this->ctx['tenant_id'], function (): void {
        $admin = (string) $this->world['admin'];
        $lifecycle = app(PolicyLifecycle::class);
        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $day) {
            $policy = $lifecycle->issue($lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'], null,
                CarbonImmutable::parse($day), 1_200_000, 'BDT', 1), $admin)->id, CarbonImmutable::parse($day), $admin);
            app(ClaimService::class)->register($policy->id, CarbonImmutable::parse($day), "Loss {$day}", $admin, CarbonImmutable::parse($day));
            app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 100_000, 'BDT', CarbonImmutable::parse($day), null, "ref {$day}", []), $admin);
        }
    });
});

it('pages the long lists and says how many rows there are', function (): void {
    config(['erp.ui.list_page_size' => 2]);
    foreach (['/policies' => ['policies/Index', 'policies'], '/claims' => ['claims/Index', 'claims'], '/receipts' => ['receipts/Index', 'receipts'], '/parties' => ['parties/Index', 'parties']] as $url => [$component, $prop]) {
        $total = (int) actingAs($this->admin)->get($url, $this->headers)->assertOk()->inertiaProps("{$prop}.total");
        expect($total)->toBeGreaterThanOrEqual(2, $url);
        actingAs($this->admin)->get($url, $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component($component)
            ->has("{$prop}.data", 2)->where("{$prop}.current_page", 1)->where("{$prop}.last_page", intdiv($total + 1, 2)));
        actingAs($this->admin)->get("{$url}?page=2&f.status=x", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
            ->where("{$prop}.current_page", 2)->has("{$prop}.data", min(2, $total - 2)));
    }
});

it('says how many rows the capped lists hold', function (): void {
    $refunds = userWithPermissions($this->ctx['tenant_id'], ['receipt.refund_request']);
    actingAs(asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail($refunds)))->get('/refunds', $this->headers)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('refundsTotal', 0)->where('refundableCount', 0)->missing('refundable'));
    actingAs($this->admin)->get('/commission', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('statementsTotal', 0));
    actingAs($this->admin)->get('/quotations', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('quotationsTotal'));
    actingAs($this->admin)->get('/cover-notes', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('coverNotesTotal', 0));
    actingAs($this->admin)->get("/distribution/producers/{$this->world['agent_id']}", $this->headers)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('compensation.entries_total', 0));
    $receipt = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('receipts')->value('id'));
    actingAs($this->admin)->get("/receipts/{$receipt}/allocate", $this->headers)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('candidatesTotal', 3)->has('candidates', 3));
});

it('looks up the new producer\'s party and the policy to refund instead of listing whole tables', function (): void {
    asTenant($this->ctx['tenant_id'], fn () => DB::table('parties')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'kind' => 'organization',
        'display_name' => 'Delta Brokers', 'tax_id' => 'TIN-DB-1', 'status' => 'active']));
    actingAs($this->admin)->get('/distribution/producers', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->missing('parties'));
    $manager = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['agent.manage'])));
    actingAs($manager)->getJson('/lookup/party?q=delta', $this->headers)->assertOk()->assertJsonPath('results.0.label', 'Delta Brokers')->assertJsonPath('results.0.detail', 'Organization · TIN TIN-DB-1');
    // The world's agent is a producer already, so its party is not offered again.
    expect(array_column((array) actingAs($manager)->getJson('/lookup/party?q=Jamal', $this->headers)->json('results'), 'label'))->toBe([]);
    actingAs(asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['claim.register']))))
        ->getJson('/lookup/party?q=delta', $this->headers)->assertForbidden();

    $policy = asTenant($this->ctx['tenant_id'], function (): string {
        $admin = (string) $this->world['admin'];
        $lifecycle = app(PolicyLifecycle::class);
        $policy = $lifecycle->issue($lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'], null,
            CarbonImmutable::parse('2026-09-01'), 1_200_000, 'BDT', 1), $admin)->id, CarbonImmutable::parse('2026-09-01'), $admin);
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 1_200_000, 'BDT', CarbonImmutable::parse('2026-09-02'), null, 'premium',
            [new App\Modules\Insurance\Collections\Application\AllocationLine((string) DB::table('installments')->where('policy_id', $policy->id)->value('id'), 1_200_000)]), $admin);
        $lifecycle->cancel($policy->id, CarbonImmutable::parse('2026-09-14'), 'sold', $admin);

        return $policy->id;
    });
    $requester = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['receipt.refund_request'])));
    $number = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('policies')->where('id', $policy)->value('number'));
    actingAs($requester)->getJson('/lookup/refundable?q='.urlencode(substr($number, -6)), $this->headers)->assertOk()->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.id', $policy)->assertJsonPath('results.0.label', "{$number} · Rahima Akter")
        ->assertJsonPath('results.0.detail', fn (string $detail): bool => str_ends_with($detail, ' refundable'));
    actingAs($requester)->getJson('/lookup/refundable?q=nobody', $this->headers)->assertJsonCount(0, 'results');
    actingAs($requester)->get("/refunds?policy={$policy}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('refundableCount', 1)
        ->where('prefill.label', "{$number} · Rahima Akter"));
});
