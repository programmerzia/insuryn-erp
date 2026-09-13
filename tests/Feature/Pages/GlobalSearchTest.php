<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ChequeDetails;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * UX brief §4 command palette "find": policy, claim and receipt numbers, customers, cheque references, journals, and period actions such as
 * "lock period Sep 2026" (slice U3). Results only come from areas the user may open.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    asTenant($this->ctx['tenant_id'], function (): void {
        $admin = $this->world['admin'];
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT'), $admin);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $admin);
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cheque', 12_000_000, 'BDT',
            CarbonImmutable::parse('2026-09-03'), null, 'first premium', [new AllocationLine((string) DB::table('installments')->value('id'), 12_000_000)],
            new ChequeDetails('88231', 'Sonali Bank', CarbonImmutable::parse('2026-09-02'))), $admin);
        app(ClaimService::class)->register($policy->id, CarbonImmutable::parse('2026-09-05'), 'Rear collision', $admin, CarbonImmutable::parse('2026-09-06'));
    });
});

it('refuses guests and needs two characters', function (): void {
    getJson('/search?q=POL', $this->headers)->assertUnauthorized();
    actingAs(($this->userWith)(['policy.create']))->getJson('/search?q=P', $this->headers)->assertOk()->assertExactJson(['results' => []]);
});

it('finds policies, customers, claims and cheques only in areas the user may open', function (): void {
    $clerk = ($this->userWith)(['policy.create']);
    $claims = ($this->userWith)(['claim.register']);
    $cashier = ($this->userWith)(['receipt.create']);

    $policyNumber = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('policies')->value('number'));
    $claimNumber = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('claims')->value('number'));

    actingAs($clerk)->getJson('/search?q='.urlencode(substr($policyNumber, -6)), $this->headers)->assertOk()
        ->assertJsonPath('results.0.kind', 'policy')->assertJsonPath('results.0.label', $policyNumber)
        ->assertJsonPath('results.0.detail', 'Rahima Akter · Issued')->assertJsonPath('results.0.href', fn (string $href): bool => str_starts_with($href, '/policies/'));
    $short = preg_replace('/^([A-Z]+)-\d{4}-0*(\d+)$/', '$1-$2', $policyNumber); // POL-2026-000001 typed as POL-1
    actingAs($clerk)->getJson('/search?q='.urlencode((string) $short), $this->headers)->assertJsonPath('results.0.label', $policyNumber);
    actingAs($clerk)->getJson('/search?q=rahima', $this->headers)->assertJsonFragment(['kind' => 'customer', 'label' => 'Rahima Akter']);
    actingAs($clerk)->getJson('/search?q='.urlencode($claimNumber), $this->headers)->assertJsonMissing(['kind' => 'claim']);

    actingAs($claims)->getJson('/search?q='.urlencode($claimNumber), $this->headers)
        ->assertJsonFragment(['kind' => 'claim', 'label' => $claimNumber, 'detail' => 'Rear collision · Registered']);
    actingAs($claims)->getJson('/search?q=88231', $this->headers)->assertJsonPath('results', []);

    actingAs($cashier)->getJson('/search?q=88231', $this->headers)->assertJsonPath('results.0.kind', 'receipt')
        ->assertJsonPath('results.0.detail', 'Cheque 88231 · Sonali Bank · 120,000.00');
});

it('offers period actions to those who run the close', function (): void {
    $controller = ($this->userWith)(['periods.lock']);
    $clerk = ($this->userWith)(['policy.create']);

    actingAs($controller)->getJson('/search?q='.urlencode('lock period sep 2026'), $this->headers)->assertOk()
        ->assertJsonPath('results.0.kind', 'action')->assertJsonPath('results.0.label', 'Lock period Sep 2026')->assertJsonPath('results.0.href', '/close');
    actingAs($clerk)->getJson('/search?q='.urlencode('lock period sep 2026'), $this->headers)->assertJsonPath('results', []);
});
