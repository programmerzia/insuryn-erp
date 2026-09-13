<?php

declare(strict_types=1);

use App\Modules\Distribution\Application\Advances\AdvanceService;
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
use App\Modules\Insurance\Commission\Application\CommissionPayoutService;
use App\Modules\Insurance\Commission\Application\CommissionStatementRun;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Platform\Authorization\SodViolation;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Distribution design note §2 step 6 (slice D6): the monthly statement run turns accruals + bonuses + clawbacks − withholding − advance recovery
 * into one statement per producer; approval and payment are done by different people (commission.approve ✕ commission.pay, CONTEXT.md #9);
 * payout goes to payroll (producers on payroll) or accounts payable. Conditional commission waits for persistency.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $admin = $this->world['admin'];
    $day = fn (string $d): CarbonImmutable => CarbonImmutable::parse($d);
    $this->approver = userWithPermissions($this->ctx['tenant_id'], ['commission.approve']);
    $this->payer = userWithPermissions($this->ctx['tenant_id'], ['commission.pay']);

    [$this->scheme, $this->product, $this->fa, $this->um] = ($this->in)(function () use ($admin, $day): array {
        $schemes = app(CompensationSchemeService::class);
        $scheme = $schemes->createScheme('LIFE-AGENCY', 'Life agency', 'commission', $day('2026-01-01'), null, ['allowed_producer_types' => ['agent']], $admin);
        app(HierarchyService::class)->defineLevels($scheme, [['code' => 'FA', 'rank' => 1, 'label' => 'FA'], ['code' => 'UM', 'rank' => 2, 'label' => 'UM']], $admin);
        $product = app(ProductCatalogue::class)->createProduct('LIFE-END', 'Endowment', 'life', $admin);
        foreach ([['producer_type' => 'agent', 'level_code' => 'FA', 'rate_bp' => 2000], ['level_code' => 'UM', 'override_rate_bp' => 500]] as $rule) {
            $schemes->addRule($scheme, CompensationRuleRequest::fromArray(['basis' => 'premium_received', 'policy_year_from' => 1, 'policy_year_to' => 99, 'effective_from' => '2026-01-01',
                'product_id' => $product->id, ...$rule]), $admin);
        }
        app(ProductCatalogue::class)->addVersion($product->id, ['effective_from' => '2026-01-01', 'term_months' => 12, 'earning_method' => 'monthly', 'tax_profile' => ['inclusive' => true],
            'compensation_scheme_id' => $scheme], $admin);
        $producer = function (string $code, ?string $parent, string $level, ?string $employeeId) use ($admin, $day): string {
            DB::table('parties')->insert(['id' => $party = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'kind' => 'individual', 'display_name' => $code, 'status' => 'active']);
            $id = app(ProducerService::class)->create(new CreateProducer($party, $code, 'agent', $this->ctx['branch_id'], employeeId: $employeeId, joinedOn: $day('2026-01-01')), $admin)->id;
            app(HierarchyService::class)->place($id, $parent, $level, $day('2026-01-01'), $admin);
            app(LicenceService::class)->record(new RecordLicence($id, "IDRA-{$code}", 'life', $day('2026-01-01'), $day('2027-12-31')), $admin);

            return $id;
        };
        $um = $producer('UM-1', null, 'UM', (string) Str::uuid7());   // on payroll
        $fa = $producer('FA-1', $um, 'FA', null);                     // independent agent → accounts payable

        return [$scheme, $product->id, $fa, $um];
    });

    $this->sell = function (int $premium, string $on, string $received): string {
        return ($this->in)(function () use ($premium, $on, $received): string {
            $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->product, $this->world['policyholder_id'],
                $this->fa, CarbonImmutable::parse($on), $premium, 'BDT', 1), $this->world['admin']);
            app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse($on), $this->world['admin']);
            app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', $premium, 'BDT',
                CarbonImmutable::parse($received), null, 'ref', [new AllocationLine((string) DB::table('installments')->where('policy_id', $policy->id)->value('id'), $premium)]), $this->world['admin']);

            return $policy->id;
        });
    };
    $this->run = fn (string $periodEnd): array => ($this->in)(fn (): array => app(CommissionStatementRun::class)->prepare($this->ctx['entity_id'], CarbonImmutable::parse($periodEnd), $this->approver));
    $this->statement = fn (string $producer): ?object => ($this->in)(fn () => DB::table('commission_statements')->where('agent_id', $producer)->orderByDesc('created_at')->first());
});

it('prepares one draft statement per producer for the month, with the design note §2 step 6 split, and rebuilds drafts on rerun', function (): void {
    ($this->sell)(10_000_000, '2026-09-01', '2026-09-05');
    ($this->sell)(5_000_000, '2026-09-10', '2026-10-02'); // received in October: not in September's run

    expect(($this->run)('2026-09-30'))->toHaveCount(2);
    ($this->run)('2026-09-30');

    $fa = ($this->statement)($this->fa);
    expect(($this->in)(fn () => DB::table('commission_statements')->count()))->toBe(2)
        ->and([$fa?->status, $fa?->period_end, (int) $fa?->earned_minor, (int) $fa?->override_minor, (int) $fa?->clawback_minor, (int) $fa?->net_minor, $fa?->paid_via])
        ->toBe(['draft', '2026-09-30', 2_000_000, 0, 0, 2_000_000, 'ap'])
        ->and([(int) ($this->statement)($this->um)?->override_minor, ($this->statement)($this->um)?->paid_via])->toBe([500_000, 'payroll'])
        ->and(($this->in)(fn () => DB::table('commission_entries')->whereNotNull('statement_id')->count()))->toBe(2);
});

it('recovers advances when the statement is approved', function (): void {
    ($this->in)(fn () => app(AdvanceService::class)->issue($this->fa, 1_500_000, ['type' => 'percent_of_net', 'bp' => 5000], CarbonImmutable::parse('2026-08-15'), $this->payer));
    expect(($this->in)(fn () => DB::table('accounting_events')->where('event_type', 'PRODUCER_ADVANCE_ISSUED')->count()))->toBe(1)
        ->and(thrownBy(fn () => ($this->in)(fn () => app(AdvanceService::class)->issue($this->fa, 0, ['type' => 'full'], CarbonImmutable::parse('2026-08-15'), $this->payer)), BusinessRuleViolation::class)->reasonCode)
        ->toBe('ADVANCE_INVALID');

    ($this->sell)(10_000_000, '2026-09-01', '2026-09-05');
    ($this->run)('2026-09-30');
    $draft = ($this->statement)($this->fa);
    expect([(int) $draft?->earned_minor, (int) $draft?->advances_recovered_minor, (int) $draft?->net_minor])->toBe([2_000_000, 1_000_000, 1_000_000]) // half of the net, per the advance's rule
        ->and(($this->in)(fn () => DB::table('accounting_events')->where('event_type', 'PRODUCER_ADVANCE_RECOVERED')->count()))->toBe(0);                // nothing moves on a draft

    ($this->in)(fn () => app(CommissionStatementRun::class)->approve((string) $draft?->id, $this->approver, CarbonImmutable::parse('2026-10-01')));
    expect(($this->in)(fn () => [(int) DB::table('producer_advances')->value('balance_minor'), DB::table('accounting_events')->where('event_type', 'PRODUCER_ADVANCE_RECOVERED')->count(),
        DB::table('commission_entries')->where('statement_id', $draft?->id)->value('status'), DB::table('commission_statements')->where('id', $draft?->id)->value('status')]))
        ->toBe([500_000, 1, 'approved', 'approved']);
});

it('routes payouts to accounts payable or payroll, with a message for each', function (): void {
    ($this->sell)(10_000_000, '2026-09-01', '2026-09-05');
    ($this->run)('2026-09-30');
    $holdsBoth = userWithPermissions($this->ctx['tenant_id'], ['commission.approve', 'commission.pay']);
    foreach ([$this->fa, $this->um] as $producer) {
        $id = (string) ($this->statement)($producer)?->id;
        ($this->in)(fn () => app(CommissionStatementRun::class)->approve($id, $holdsBoth, CarbonImmutable::parse('2026-10-01')));
        expect(thrownBy(fn () => ($this->in)(fn () => app(CommissionPayoutService::class)->pay($id, null, $holdsBoth, CarbonImmutable::parse('2026-10-02'))), SodViolation::class)->reasonCode)
            ->toBe('SOD_CONFLICT'); // the approver never pays, even holding both permissions
        ($this->in)(fn () => app(CommissionPayoutService::class)->pay($id, null, $this->payer, CarbonImmutable::parse('2026-10-02')));
    }

    expect(($this->in)(fn () => DB::table('accounting_events')->whereIn('event_type', ['COMMISSION_PAYOUT_TO_AP', 'COMMISSION_PAYOUT_TO_PAYROLL'])->orderBy('event_type')->pluck('event_type')->all()))
        ->toBe(['COMMISSION_PAYOUT_TO_AP', 'COMMISSION_PAYOUT_TO_PAYROLL'])
        ->and(($this->in)(fn () => DB::table('outbox')->whereIn('message_type', ['CommissionPayableToAp', 'CommissionPayrollEarning'])->orderBy('message_type')->pluck('message_type')->all()))
        ->toBe(['CommissionPayableToAp', 'CommissionPayrollEarning'])
        ->and(($this->statement)($this->fa)?->status)->toBe('paid');
});

it('releases conditional commission when persistency is met, and holds it otherwise', function (): void {
    ($this->in)(fn () => DB::table('compensation_rules')->where('rate_bp', 2000)->update(['min_persistency_bp' => 8000]));
    ($this->sell)(10_000_000, '2026-09-01', '2026-09-05');
    ($this->run)('2026-09-30');
    expect(($this->in)(fn () => DB::table('commission_entries')->where('agent_id', $this->fa)->value('status')))->toBe('conditional')
        ->and(($this->statement)($this->fa))->toBeNull();

    // FA-1's two policies started more than thirteen months before the June 2027 run and are both still in force: 13th-month persistency 100%.
    ($this->sell)(1_000_000, '2026-09-01', '2026-09-06');
    ($this->in)(fn () => DB::table('policies')->where('agent_id', $this->fa)->update(['inception' => '2026-05-15']));
    ($this->run)('2027-06-30');
    expect(($this->in)(fn () => DB::table('commission_entries')->where('agent_id', $this->fa)->where('status', 'conditional')->count()))->toBe(0)
        ->and((int) ($this->statement)($this->fa)?->earned_minor)->toBe(2_200_000);
});
