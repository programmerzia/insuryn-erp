<?php

declare(strict_types=1);

use App\Modules\Distribution\Application\Compensation\CompensationRuleRequest;
use App\Modules\Distribution\Application\Compensation\CompensationSchemeService;
use App\Modules\Distribution\Application\CreateProducer;
use App\Modules\Distribution\Application\Hierarchy\HierarchyService;
use App\Modules\Distribution\Application\Licences\LicenceService;
use App\Modules\Distribution\Application\Licences\RecordLicence;
use App\Modules\Distribution\Application\ProducerService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Distribution design note §2 (slice D5) end to end: the Phase 1A calculator is replaced by the compensation engine. A product version's scheme
 * decides; the hierarchy snapshot on the premium's date decides who is above the seller, and is stored on every entry (INVARIANT: later tree
 * changes never change a payout); ineligible producers get a compliance exception while the policy and the allocation go through; cancellation
 * claws back per beneficiary. The Commission subledger keeps posting COMMISSION_EARNED / COMMISSION_CLAWBACK per entry.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $admin = $this->world['admin'];
    $day = fn (string $d): CarbonImmutable => CarbonImmutable::parse($d);

    [$this->scheme, $this->product, $this->fa, $this->um, $this->um2, $this->bm] = ($this->in)(function () use ($admin, $day): array {
        DB::table('tax_rates')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'jurisdiction' => 'BD', 'tax_type' => 'AIT_COMMISSION',
            'rate_bp' => 500, 'inclusive' => false, 'withholding' => true, 'effective_from' => '2026-01-01']);
        $schemes = app(CompensationSchemeService::class);
        $scheme = $schemes->createScheme('LIFE-AGENCY', 'Life agency', 'commission', $day('2026-01-01'), null,
            ['allowed_producer_types' => ['agent'], 'caps' => [['policy_year_from' => 1, 'policy_year_to' => 1, 'max_total_bp' => 3500]]], $admin, 'BD', 'AIT_COMMISSION');
        app(HierarchyService::class)->defineLevels($scheme, [['code' => 'FA', 'rank' => 1, 'label' => 'FA'], ['code' => 'UM', 'rank' => 2, 'label' => 'UM'], ['code' => 'BM', 'rank' => 3, 'label' => 'BM']], $admin);
        $catalogue = app(ProductCatalogue::class);
        $product = $catalogue->createProduct('LIFE-END', 'Endowment', 'life', $admin);
        foreach ([
            ['product_id' => $product->id, 'producer_type' => 'agent', 'level_code' => 'FA', 'rate_bp' => 2500],
            ['product_id' => $product->id, 'producer_type' => 'agent', 'level_code' => 'FA', 'rate_bp' => 500, 'policy_year_from' => 2, 'policy_year_to' => 99],
            ['product_id' => $product->id, 'level_code' => 'UM', 'override_rate_bp' => 500],
            ['product_id' => $product->id, 'level_code' => 'BM', 'override_rate_bp' => 200],
        ] as $rule) {
            $schemes->addRule($scheme, CompensationRuleRequest::fromArray(['basis' => 'premium_received', 'policy_year_from' => 1, 'policy_year_to' => 1, 'effective_from' => '2026-01-01', ...$rule]), $admin);
        }
        $catalogue->addVersion($product->id, ['effective_from' => '2026-01-01', 'term_months' => 12, 'earning_method' => 'monthly',
            'tax_profile' => ['inclusive' => true], 'compensation_scheme_id' => $scheme], $admin);

        $producer = function (string $code, ?string $parent, string $level) use ($admin, $day): string {
            DB::table('parties')->insert(['id' => $party = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'kind' => 'individual', 'display_name' => $code, 'status' => 'active']);
            $id = app(ProducerService::class)->create(new CreateProducer($party, $code, 'agent', $this->ctx['branch_id'], joinedOn: $day('2026-01-01')), $admin)->id;
            app(HierarchyService::class)->place($id, $parent, $level, $day('2026-01-01'), $admin);
            app(LicenceService::class)->record(new RecordLicence($id, "IDRA-{$code}", 'life', $day('2026-01-01'), $day('2027-12-31')), $admin);

            return $id;
        };
        $bm = $producer('BM-1', null, 'BM');
        $um = $producer('UM-1', $bm, 'UM');
        $um2 = $producer('UM-2', $bm, 'UM');
        $fa = $producer('FA-1', $um, 'FA');

        return [$scheme, $product->id, $fa, $um, $um2, $bm];
    });

    $this->issue = fn (string $on = '2026-09-01', int $installments = 2): array => ($this->in)(function () use ($on, $installments): array {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->product, $this->world['policyholder_id'],
            $this->fa, CarbonImmutable::parse($on), 12_000_000, 'BDT', $installments), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse($on), $this->world['admin']);

        return [$policy->id, DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->pluck('id')->map(fn ($id): string => (string) $id)->all()];
    });
    $this->receive = fn (string $installment, int $amount, string $on): mixed => ($this->in)(fn () => app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'],
        $this->ctx['branch_id'], null, 'bank_transfer', $amount, 'BDT', CarbonImmutable::parse($on), null, 'ref', [new AllocationLine($installment, $amount)]), $this->world['admin']));
    $this->entries = fn (): array => ($this->in)(fn (): array => DB::table('commission_entries as e')->join('producers as p', 'p.id', '=', 'e.agent_id')
        ->orderBy('e.created_at')->orderBy('e.id')->get(['p.code', 'e.kind', 'e.beneficiary_role', 'e.level_code', 'e.rate_bp', 'e.amount_minor', 'e.withholding_minor', 'e.status'])
        ->map(fn (object $e): array => [(string) $e->code, (string) $e->kind, (string) $e->beneficiary_role, $e->level_code, (int) $e->rate_bp, (int) $e->amount_minor, (int) $e->withholding_minor, (string) $e->status])->all());
});

it('pays the seller and every level above it from the scheme, with the snapshot of that day', function (): void {
    [, $installments] = ($this->issue)();
    ($this->receive)($installments[0], 6_000_000, '2026-09-10');

    expect(($this->entries)())->toBe([
        ['FA-1', 'earned', 'direct', 'FA', 2500, 1_500_000, 75_000, 'accrued'],
        ['UM-1', 'earned', 'override', 'UM', 500, 300_000, 15_000, 'accrued'],
        ['BM-1', 'earned', 'override', 'BM', 200, 120_000, 6_000, 'accrued'],
    ])
        ->and(($this->in)(fn () => DB::table('accounting_events')->where('event_type', 'COMMISSION_EARNED')->count()))->toBe(3)
        ->and(($this->in)(fn () => DB::table('commission_entries')->whereNotNull('rule_id')->where('scheme_id', $this->scheme)->count()))->toBe(3)
        ->and(($this->in)(fn () => array_column(json_decode((string) DB::table('commission_entries')->where('beneficiary_role', 'direct')->value('hierarchy_snapshot'), true), 'code')))
        ->toBe(['FA-1', 'UM-1', 'BM-1']);
});

it('never changes a payout when the tree changes later, and pays the new manager afterwards', function (): void {
    [, $installments] = ($this->issue)();
    ($this->receive)($installments[0], 6_000_000, '2026-09-10');
    ($this->in)(fn () => app(HierarchyService::class)->place($this->fa, $this->um2, 'FA', CarbonImmutable::parse('2026-10-01'), $this->world['admin']));
    ($this->receive)($installments[1], 6_000_000, '2026-10-05');

    $overrides = ($this->in)(fn (): array => DB::table('commission_entries as e')->join('producers as p', 'p.id', '=', 'e.agent_id')->where('e.level_code', 'UM')
        ->orderBy('e.earned_on')->get(['p.code', 'e.earned_on', 'e.hierarchy_snapshot'])->map(fn (object $e): array => [(string) $e->code, (string) $e->earned_on,
            array_column(json_decode((string) $e->hierarchy_snapshot, true), 'code')])->all());
    expect($overrides)->toBe([
        ['UM-1', '2026-09-10', ['FA-1', 'UM-1', 'BM-1']],
        ['UM-2', '2026-10-05', ['FA-1', 'UM-2', 'BM-1']],
    ]);
});

it('skips an ineligible manager with a compliance exception, and pays nothing on an ineligible seller while the premium is still allocated', function (): void {
    [$policyId, $installments] = ($this->issue)();
    ($this->in)(fn () => DB::table('producers')->where('id', $this->um)->update(['status' => 'suspended']));
    ($this->receive)($installments[0], 6_000_000, '2026-09-10');

    expect(array_column(($this->entries)(), 0))->toBe(['FA-1', 'BM-1'])
        ->and(($this->in)(fn () => DB::table('compliance_exceptions')->get(['producer_id', 'policy_id', 'reason_code'])->map(fn (object $e): array => (array) $e)->all()))
        ->toBe([['producer_id' => $this->um, 'policy_id' => $policyId, 'reason_code' => 'PRODUCER_NOT_ACTIVE']]);

    ($this->in)(fn () => DB::table('producer_licences')->where('producer_id', $this->fa)->update(['expires_on' => '2026-09-30']));
    ($this->receive)($installments[1], 6_000_000, '2026-10-05');
    expect(count(($this->entries)()))->toBe(2)
        ->and(($this->in)(fn () => DB::table('compliance_exceptions')->where('producer_id', $this->fa)->value('reason_code')))->toBe('LICENCE_INVALID')
        ->and(($this->in)(fn () => (int) DB::table('installments')->where('id', $installments[1])->value('paid_minor')))->toBe(6_000_000);
});

it('claws back per beneficiary when the policy is cancelled', function (): void {
    [$policyId, $installments] = ($this->issue)();
    ($this->receive)($installments[0], 6_000_000, '2026-09-10');
    ($this->receive)($installments[1], 6_000_000, '2026-09-10');
    ($this->in)(fn () => app(PolicyLifecycle::class)->cancel($policyId, CarbonImmutable::parse('2026-12-01'), 'sold', $this->world['admin']));

    $clawbacks = ($this->in)(fn (): array => DB::table('commission_entries as e')->join('producers as p', 'p.id', '=', 'e.agent_id')->where('e.kind', 'clawback')->orderBy('p.code')
        ->pluck('e.amount_minor', 'p.code')->map(fn ($a): int => (int) $a)->all());
    $earned = ($this->in)(fn (): array => DB::table('commission_entries as e')->join('producers as p', 'p.id', '=', 'e.agent_id')->where('e.kind', 'earned')->groupBy('p.code')
        ->selectRaw('p.code, sum(e.amount_minor) as total')->pluck('total', 'p.code')->map(fn ($a): int => (int) $a)->all());

    expect(array_keys($clawbacks))->toBe(['BM-1', 'FA-1', 'UM-1'])
        ->and($clawbacks['FA-1'] / $earned['FA-1'])->toEqualWithDelta($clawbacks['UM-1'] / $earned['UM-1'], 0.0001)
        ->and(($this->in)(fn () => DB::table('accounting_events')->where('event_type', 'COMMISSION_CLAWBACK')->count()))->toBe(3);
});

it('pays renewal rates in later policy years, and nothing under a salaried scheme', function (): void {
    [$policyId, $installments] = ($this->issue)('2026-01-15', 1);
    ($this->receive)($installments[0], 12_000_000, '2026-01-20');
    ($this->in)(fn () => DB::table('policies')->where('id', $policyId)->update(['status' => 'expired']));
    $renewal = ($this->in)(fn () => app(PolicyLifecycle::class)->renew($policyId, $this->world['admin']));
    ($this->in)(fn () => app(PolicyLifecycle::class)->issue($renewal->id, CarbonImmutable::parse('2026-12-20'), $this->world['admin']));
    $renewalInstallment = ($this->in)(fn (): string => (string) DB::table('installments')->where('policy_id', $renewal->id)->value('id'));
    ($this->receive)($renewalInstallment, 12_000_000, '2027-01-20');

    expect(array_slice(($this->entries)(), 3))->toBe([['FA-1', 'earned', 'direct', 'FA', 500, 600_000, 30_000, 'accrued']]);

    ($this->in)(fn () => DB::table('compensation_schemes')->update(['mode' => 'salary_incentive']));
    [, $salaried] = ($this->issue)('2026-09-01', 1);
    ($this->receive)($salaried[0], 12_000_000, '2026-09-10');
    expect(count(($this->entries)()))->toBe(4)->and(($this->in)(fn () => DB::table('compliance_exceptions')->count()))->toBe(0);
});

it('holds commission under a persistency condition as conditional, unposted', function (): void {
    ($this->in)(fn () => DB::table('compensation_rules')->where('override_rate_bp', 0)->where('policy_year_from', 1)->update(['min_persistency_bp' => 8000]));
    [, $installments] = ($this->issue)();
    ($this->receive)($installments[0], 6_000_000, '2026-09-10');

    expect(array_column(($this->entries)(), 7))->toBe(['conditional', 'accrued', 'accrued'])
        ->and(($this->in)(fn () => DB::table('accounting_events')->where('event_type', 'COMMISSION_EARNED')->count()))->toBe(2);
});

it('earns commission on written premium when the policy is issued', function (): void {
    ($this->in)(fn () => app(CompensationSchemeService::class)->addRule($this->scheme, CompensationRuleRequest::fromArray(['basis' => 'premium_written', 'policy_year_from' => 1,
        'policy_year_to' => 1, 'effective_from' => '2026-01-01', 'product_id' => $this->product, 'producer_type' => 'agent', 'level_code' => 'FA', 'rate_bp' => 100]), $this->world['admin']));
    [$policyId] = ($this->issue)();

    $entry = ($this->in)(fn () => DB::table('commission_entries')->first(['base_minor', 'amount_minor', 'policy_transaction_id', 'receipt_allocation_id', 'earned_on']));
    expect([(int) $entry?->base_minor, (int) $entry?->amount_minor, $entry?->receipt_allocation_id, $entry?->earned_on])->toBe([12_000_000, 120_000, null, '2026-09-01'])
        ->and($entry?->policy_transaction_id)->toBe(($this->in)(fn () => DB::table('policy_transactions')->where('policy_id', $policyId)->value('id')));
});
