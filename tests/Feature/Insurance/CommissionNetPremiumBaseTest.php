<?php

declare(strict_types=1);

use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Commission\Application\CommissionPlanService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Policy\Domain\PremiumMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gap audit GA-42 (D-70, A-181): agent commission on premium received is a percentage of the net premium inside each allocation, without VAT
 * and stamp duty. An installment holds net premium in the proportion of the transaction that billed it (the issue, or the premium increase
 * that added it). `erp.commission.premium_received_base = gross` keeps the earlier base, the cash allocated.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $planner = userWithPermissions($this->ctx['tenant_id'], ['commission.manage_plans']);
    $this->planId = asTenant($this->ctx['tenant_id'], fn (): string => app(CommissionPlanService::class)->create('MOTOR-10', 'Motor 10%', 1000, null, null, $planner)->id);
    $this->world = seedInsuranceWorld($this->ctx, 'daily_365', true, $this->planId);
    $this->receive = function (array $lines): void {
        $amount = array_sum(array_map(fn (AllocationLine $l): int => $l->amountMinor, $lines));
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', $amount, 'BDT',
            CarbonImmutable::parse('2026-09-15'), null, 'ref', array_values($lines)), $this->world['admin']);
    };
    $this->policy = function (): array {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 2), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return [$policy->id, DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->pluck('id')->map(fn ($id): string => (string) $id)->all()];
    };
});

it('earns commission on the net premium share of an installment, not on its VAT', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        [$policyId, $installments] = ($this->policy)();
        $policy = DB::table('policies')->where('id', $policyId)->sole(['net_premium_minor', 'tax_minor', 'gross_premium_minor']);
        expect((int) $policy->tax_minor)->toBeGreaterThan(0);
        ($this->receive)([new AllocationLine($installments[0], 6_000_000)]);

        $net = PremiumMath::prorate(6_000_000, (int) $policy->net_premium_minor, (int) $policy->gross_premium_minor);
        $entry = DB::table('commission_entries')->sole(['base_minor', 'rate_bp', 'amount_minor']);
        expect($net)->toBeLessThan(6_000_000)->toBeGreaterThan(5_200_000)
            ->and([(int) $entry->base_minor, (int) $entry->rate_bp, (int) $entry->amount_minor])->toBe([$net, 1000, PremiumMath::prorate($net, 1000, 10_000)])
            ->and((int) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.description', 'COMMISSION_EARNED')
                ->where('l.role_code', 'commission_expense')->sum('l.amount_minor'))->toBe(PremiumMath::prorate($net, 1000, 10_000));
    });
});

it('uses the proportion of the endorsement that billed an added installment', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        [$policyId] = ($this->policy)();
        // An unrated endorsement adding 1,150.00 VAT-inclusive: 1,000.00 net and 150.00 VAT, billed as installment 3.
        app(PolicyLifecycle::class)->endorse($policyId, CarbonImmutable::parse('2026-09-10'), 115_000, 'Extra cover', $this->world['admin']);
        $added = DB::table('installments')->where('policy_id', $policyId)->where('no', 3)->sole(['id', 'amount_minor']);
        $endorsement = DB::table('policy_transactions')->where('policy_id', $policyId)->where('type', 'endorsement')->sole(['net_delta_minor', 'premium_delta_minor']);
        expect([(int) $added->amount_minor, (int) $endorsement->net_delta_minor])->toBe([115_000, 100_000]);

        ($this->receive)([new AllocationLine((string) $added->id, 115_000)]);

        expect([(int) DB::table('commission_entries')->value('base_minor'), (int) DB::table('commission_entries')->value('amount_minor')])->toBe([100_000, 10_000]);
    });
});

it('pays on the cash allocated when the base is configured as gross', function (): void {
    config(['erp.commission.premium_received_base' => 'gross']);
    asTenant($this->ctx['tenant_id'], function (): void {
        [, $installments] = ($this->policy)();
        ($this->receive)([new AllocationLine($installments[0], 6_000_000)]);

        expect([(int) DB::table('commission_entries')->value('base_minor'), (int) DB::table('commission_entries')->value('amount_minor')])->toBe([6_000_000, 600_000]);
    });
});
