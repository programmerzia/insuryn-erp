<?php

declare(strict_types=1);

use App\Http\Help\HelpContent;
use App\Models\User;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Authorization\RoleTemplates;
use App\Modules\Platform\Money\MinorUnits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Gap fixes GA-01 and GA-24: nobody could request the refund of a cancelled policy (no template held receipt.refund_request), and a cancellation
 * offered no next step. The branch manager template now requests refunds; after cancelling, the confirmation offers "Request the refund of X" (the
 * refund form arrives filled in) or, when the customer still owes premium earned before the cancellation, "Collect X" (the receipt form arrives filled in).
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->user = fn (array $permissions): User => ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    $this->issued = fn (): string => ($this->in)(function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 2), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return $policy->id;
    });
    $this->payInFull = fn (string $policyId) => ($this->in)(function () use ($policyId): void {
        $lines = [];
        $total = 0;
        foreach (DB::table('installments')->where('policy_id', $policyId)->orderBy('no')->get(['id', 'amount_minor']) as $i) {
            $lines[] = new AllocationLine((string) $i->id, (int) $i->amount_minor);
            $total += (int) $i->amount_minor;
        }
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', $total, 'BDT',
            CarbonImmutable::parse('2026-09-02'), null, 'TT 1', $lines), $this->world['admin']);
    });
});

it('gives receipt.refund_request to the branch manager, never to a template that also releases refunds, and to existing tenants by migration', function (): void {
    $templates = RoleTemplates::all();
    expect(array_keys(array_filter($templates, fn (array $t): bool => in_array('receipt.refund_request', $t['permissions'], true))))->toBe(['branch_manager'])
        ->and(array_filter($templates, fn (array $t): bool => in_array('receipt.refund_request', $t['permissions'], true) && in_array('receipt.refund_release', $t['permissions'], true)))->toBe([]);

    seedRoleTemplates($this->ctx['tenant_id']);
    $holders = fn (): array => ($this->in)(fn (): array => DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')
        ->where('rp.permission_code', 'receipt.refund_request')->where('r.code', 'not like', 'test-%')->orderBy('r.code')->pluck('r.code')->all());
    ($this->in)(fn () => DB::table('role_permissions')->where('permission_code', 'receipt.refund_request')->whereIn('role_id', DB::table('roles')->where('code', 'branch_manager')->select('id'))->delete());
    expect($holders())->toBe([]);

    $migration = require database_path('migrations/2026_09_30_000071_grant_refund_request_to_branch_manager.php');
    $migration->up();
    $migration->up();
    expect($holders())->toBe(['branch_manager']);
});

it('offers the refund after cancelling a paid policy and opens the refund request filled in', function (): void {
    $policyId = ($this->issued)();
    ($this->payInFull)($policyId);
    $manager = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], RoleTemplates::all()['branch_manager']['permissions'])));

    $response = actingAs($manager)->post("/policies/{$policyId}/cancel", ['cancel_date' => '2026-10-15', 'reason' => 'Vehicle sold'], $this->headers);
    $due = ($this->in)(fn (): int => (int) json_decode((string) DB::table('policy_transactions')->where('policy_id', $policyId)->where('type', 'cancellation')->value('amounts'), true)['refund_due']);
    $amount = MinorUnits::format($due, 'BDT');
    expect($due)->toBeGreaterThan(0);
    $response->assertSessionHasNoErrors()->assertSessionHas('status', 'Policy cancelled.')
        ->assertSessionHas('next', ['label' => "Request the refund of {$amount}", 'url' => "/refunds?policy={$policyId}", 'prompt' => "The customer is owed {$amount}. Request the refund?"]);

    actingAs($manager)->get("/refunds?policy={$policyId}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('refunds/Index')
        ->where('can.request', true)->where('prefill', ['policy_id' => $policyId, 'amount' => $amount, 'reason' => 'Policy cancelled']));
    actingAs($manager)->get('/refunds', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', null));

    actingAs($manager)->post('/refunds', ['policy_id' => $policyId, 'amount' => $amount, 'reason' => 'Policy cancelled'], $this->headers)->assertSessionHasNoErrors();
    // Everything refundable is requested: the offer and the prefill are gone, and the manager cannot release their own request.
    actingAs($manager)->get("/refunds?policy={$policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', null)->where('can.release', false));
});

it('offers to collect the earned premium still owed after cancelling an unpaid policy, with the receipt prefilled', function (): void {
    $policyId = ($this->issued)();
    $officer = ($this->user)(['receipt.create', 'policy.cancel']);

    actingAs($officer)->post("/policies/{$policyId}/cancel", ['cancel_date' => '2026-10-15', 'reason' => 'Customer request'], $this->headers)->assertSessionHasNoErrors();
    $owed = ($this->in)(fn (): int => (int) DB::table('installments')->where('policy_id', $policyId)->sum(DB::raw('amount_minor - paid_minor - cancelled_minor')));
    $amount = MinorUnits::format($owed, 'BDT');
    expect($owed)->toBeGreaterThan(0)->and(($this->in)(fn (): string => (string) DB::table('policies')->where('id', $policyId)->value('status')))->toBe('cancelled');

    // Cancelling again is refused and offers nothing.
    actingAs($officer)->post("/policies/{$policyId}/cancel", ['cancel_date' => '2026-10-15', 'reason' => 'again'], $this->headers)->assertSessionHasErrors('form')->assertSessionMissing('next');

    // The same policy again, cancelled the same day, owes the same earned premium.
    $other = ($this->issued)();
    actingAs($officer)->post("/policies/{$other}/cancel", ['cancel_date' => '2026-10-15', 'reason' => 'Customer request'], $this->headers)
        ->assertSessionHas('next', ['label' => "Collect {$amount}", 'url' => "/receipts/create?policy={$other}", 'prompt' => "The customer still owes {$amount} of premium earned before the cancellation."]);

    actingAs($officer)->get("/receipts/create?policy={$other}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('prefill.amount', $amount));
    actingAs($officer)->get("/policies/{$other}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('actions.record_receipt', true)->where('today', '2026-10-15'));

    // Someone who may neither request refunds nor record receipts is offered nothing.
    $third = ($this->issued)();
    actingAs(($this->user)(['policy.cancel']))->post("/policies/{$third}/cancel", ['cancel_date' => '2026-10-15', 'reason' => 'Customer request'], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionMissing('next');
});

it('captions the unearned premium a cancellation releases as premium for cover not given, in English and Bangla', function (): void {
    foreach (HelpContent::LOCALES as $locale) {
        $events = app(HelpContent::class)->eventCaptions($locale);
        expect(array_keys($events))->toBe(['POLICY_CANCELLED:unearned_premium']);
        foreach ($events as $caption) {
            expect(mb_strlen($caption['debit']))->toBeLessThanOrEqual(80)->and($caption['debit'])->not->toBe(app(HelpContent::class)->roleCaptions($locale)['unearned_premium']['debit']);
        }
    }
    $admin = ($this->in)(fn (): User => User::query()->findOrFail((string) $this->world['admin']));
    actingAs($admin)->getJson('/help/roles', $this->headers)->assertOk()
        ->assertJsonPath('captions.POLICY_CANCELLED:unearned_premium.debit', 'Premium for cover not given, taken off the bill or returned to the customer')
        ->assertJsonPath('captions.unearned_premium.debit', 'Cover has been provided, so this premium is no longer owed to the customer');
});
