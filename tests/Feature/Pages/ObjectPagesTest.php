<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * UX brief §6.2 object pages (slice U8): policy, claim and receipt pages carry the object's story as plain sentences from the audit trail
 * ("Reserve increased to 350,000.00 by …, 7 Sep 2026"), the journals it posted (loaded after the page), and the raw audit rows.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->admin = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $this->world['admin']));
    [$this->policyId, $this->claimId, $this->receiptId] = asTenant($this->ctx['tenant_id'], function (): array {
        $admin = $this->world['admin'];
        DB::table('users')->where('id', $admin)->update(['name' => 'Rafiq Islam']);
        $lifecycle = app(PolicyLifecycle::class);
        $policy = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            null, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT'), $admin);
        $lifecycle->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $admin);
        $lifecycle->endorse($policy->id, CarbonImmutable::parse('2026-10-01'), 100_000, 'Extra driver', $admin);
        $receipt = app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 13_000_000, 'BDT',
            CarbonImmutable::parse('2026-09-05'), null, 'TT 1', [new AllocationLine((string) DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->value('id'), 12_000_000)]), $admin);
        $claims = app(ClaimService::class);
        $claim = $claims->register($policy->id, CarbonImmutable::parse('2026-09-05'), 'Rear collision', $admin, CarbonImmutable::parse('2026-09-06'));
        $claims->reserve($claim->id, 30_000_000, 'Initial', $admin, CarbonImmutable::parse('2026-09-06'));
        $claims->reserve($claim->id, 35_000_000, 'Surveyor report', $admin, CarbonImmutable::parse('2026-09-07'));
        app(ClaimPaymentService::class)->approve($claim->id, 10_000_000, $this->world['policyholder_id'], userWithPermissions($this->ctx['tenant_id'], ['claim.approve']), CarbonImmutable::parse('2026-09-08'));

        return [$policy->id, $claim->id, $receipt->id];
    });
    $sentences = fn ($timeline): array => array_column((array) json_decode((string) json_encode($timeline), true), 'sentence');
    $this->sentences = $sentences;
});

it('tells a policy\'s story and lists what it posted', function (): void {
    actingAs($this->admin)->get("/policies/{$this->policyId}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('policies/Show')
        ->where('timeline', fn ($timeline): bool => ($this->sentences)($timeline) === [
            'Endorsed: premium up by 1,000.00 (Extra driver) by Rafiq Islam',
            'Issued by Rafiq Islam',
            'Quoted at 120,000.00 by Rafiq Islam',
        ])
        ->missing('accounting')
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload
            ->where('accounting', function ($journals): bool {
                $byEvent = array_column((array) json_decode((string) json_encode($journals), true), null, 'event');

                return isset($byEvent['POLICY_ENDORSED'], $byEvent['PREMIUM_RECEIVED'])
                    && $byEvent['POLICY_ISSUED']['lines'][0] === ['account' => '1100', 'name' => 'Premium Receivable', 'debit' => '120,000.00', 'credit' => null];
            })
            ->where('audit.0.action', 'Policy endorsed')->where('audit.0.by', 'Rafiq Islam')->where('audit.0.reason', 'Extra driver')));
});

it('tells a claim\'s story in plain sentences', function (): void {
    actingAs($this->admin)->get("/claims/{$this->claimId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('timeline', fn ($timeline): bool => str_starts_with(($this->sentences)($timeline)[0] ?? '', 'Payment of 100,000.00 approved by ') && array_slice(($this->sentences)($timeline), 1) === [
            'Reserve increased to 350,000.00 (Surveyor report) by Rafiq Islam',
            'Reserve set to 300,000.00 (Initial) by Rafiq Islam',
            'Registered by Rafiq Islam: Rear collision',
        ])
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload->where('accounting', fn ($journals): bool => in_array('CLAIM_RESERVE_ADJUSTED',
            array_column((array) json_decode((string) json_encode($journals), true), 'event'), true))));
});

it('tells a receipt\'s story', function (): void {
    actingAs($this->admin)->get("/receipts/{$this->receiptId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('timeline.0.sentence', 'Recorded 130,000.00 by Rafiq Islam: 120,000.00 allocated, 10,000.00 held in suspense')
        ->where('timeline.0.date', fn (string $date): bool => $date !== '')
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload->where('accounting', fn ($journals): bool => count((array) json_decode((string) json_encode($journals), true)) === 2)));
});
