<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application\PremiumEarning;

use App\Modules\Accounting\Application\Queries\FiscalPeriodQuery;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Insurance\Policy\Application\EarningLayers;
use App\Modules\Insurance\Policy\Application\PolicyAccountingEvents;
use App\Modules\Insurance\Policy\Domain\EarningSchedule;
use App\Modules\Insurance\Policy\Domain\Enums\PolicyStatus;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design §4.3 / §8.5 PremiumEarningRun (batch queue, nightly + close): for one period, each policy on cover earns the
 * schedule's amount for that calendar month. A ledger row unique per (policy, period) is written with the
 * PREMIUM_EARNED event in one transaction, so a rerun inserts nothing and posts nothing (key PREMIUM_EARNED:{policy_id}:{period_id}).
 */
final class PremiumEarningRun
{
    /** Statuses still earning: cover was issued and not cancelled (a lapsed policy stays on the books until cancelled). */
    private const EARNING_STATUSES = [PolicyStatus::Issued, PolicyStatus::Active, PolicyStatus::Expired, PolicyStatus::Lapsed, PolicyStatus::Renewed];

    public function __construct(
        private readonly FiscalPeriodQuery $periods,
        private readonly SubmitAccountingEvent $submit,
    ) {}

    /** @throws BusinessRuleViolation PERIOD_NOT_OPEN */
    public function run(string $periodId): EarningRunResult
    {
        $period = $this->periods->find($periodId) ?? throw new BusinessRuleViolation('PERIOD_MISSING', "Period {$periodId} does not exist.");
        if (! $period->isOpen()) {
            throw new BusinessRuleViolation('PERIOD_NOT_OPEN', "Premium can only be earned into an open period; {$period->year}-{$period->period} is {$period->status}.");
        }
        $runId = (string) Str::uuid7();
        $monthKey = $period->starts->format('Y-m');
        [$count, $total] = [0, 0];

        Policy::query()->where('entity_id', $period->entityId)
            ->whereIn('status', array_map(fn (PolicyStatus $s): string => $s->value, self::EARNING_STATUSES))
            ->where('inception', '<=', $period->ends->toDateString())->where('expiry', '>=', $period->starts->toDateString())
            ->orderBy('id')->chunkById(500, function ($policies) use ($period, $monthKey, $runId, &$count, &$total): void {
                foreach ($policies as $policy) {
                    $amount = $this->scheduledAmount($policy, $monthKey);
                    if ($amount !== 0 && $this->earn($policy, $period, $amount, 'scheduled', $runId, $period->ends)) {
                        $count++;
                        $total += $amount;
                    }
                }
            });

        return new EarningRunResult($count, $total);
    }

    /**
     * Writes one ledger row and its PREMIUM_EARNED event; false when the row already existed (rerun).
     * Runs in the caller's transaction when there is one.
     */
    public function earn(Policy $policy, FiscalPeriodView $period, int $amount, string $kind, ?string $runId, CarbonImmutable $postingDate): bool
    {
        return DB::transaction(function () use ($policy, $period, $amount, $kind, $runId, $postingDate): bool {
            $inserted = DB::table('premium_earning_ledger')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'policy_id' => $policy->id, 'period_id' => $period->id,
                'kind' => $kind, 'earned_minor' => $amount, 'run_id' => $runId, 'created_at' => now(),
            ]);
            if ($inserted === 0) {
                return false;
            }
            $key = 'PREMIUM_EARNED:'.$policy->id.':'.$period->id.($kind === 'scheduled' ? '' : ':'.$kind);
            ($this->submit)(
                entityId: $policy->entity_id, eventType: 'PREMIUM_EARNED', sourceType: 'premium_earning_ledger', sourceId: $policy->id,
                idempotencyKey: $key, transactionDate: $postingDate, effectiveDate: $postingDate, currency: $policy->currency,
                payload: ['earned' => $amount, 'period_id' => $period->id], dimensions: PolicyAccountingEvents::dimensions($policy),
            );

            return true;
        });
    }

    private function scheduledAmount(Policy $policy, string $monthKey): int
    {
        $method = ProductVersion::query()->findOrFail($policy->product_version_id)->earning_method;

        return EarningSchedule::byCalendarMonth($method, EarningLayers::of($policy))[$monthKey] ?? 0;
    }
}
