<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Distribution\Application\Incentives\IncentivePlanDirectory;
use App\Modules\Distribution\Application\ProducerDirectory;
use App\Modules\Distribution\Application\Targets\TargetService;
use App\Modules\Insurance\Commission\Domain\Models\CommissionEntry;
use App\Modules\Insurance\Policy\Application\ProductionQuery;
use App\Modules\Insurance\Policy\Domain\PremiumMath;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tax\TaxRates;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Distribution design note §4 "incentive tiers computed at period end into producer_statements.bonus" (slice D7). For every plan whose period
 * ends on the run date, each producer it applies to with a target for the period and metric is measured (ProductionQuery); the highest tier reached
 * pays a bonus: an award row (once per plan, producer and period) and a `bonus` commission entry posted INCENTIVE_BONUS_EARNED, which the next
 * statement run pays. ASSUMPTION A-25: no target, no bonus; withholding follows the plan's withholding tax, none when the plan has none.
 */
final class IncentiveRun
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly IncentivePlanDirectory $plans,
        private readonly TargetService $targets,
        private readonly ProductionQuery $production,
        private readonly ProducerDirectory $producers,
        private readonly TaxRates $taxRates,
        private readonly SubmitAccountingEvent $submit,
        private readonly Audit $audit,
    ) {}

    /** @return int awards made */
    public function run(string $entityId, CarbonImmutable $periodEnd, string $actorUserId): int
    {
        $this->permissions->authorize($actorUserId, 'commission.approve', AuthorizationScope::entity($entityId));
        $currency = (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency');
        $awards = 0;

        foreach ($this->plans->endingOn($periodEnd) as $plan) {
            $actuals = $this->production->metric($plan->metric, $plan->periodStart, $plan->periodEnd, $plan->producerIds);
            foreach ($plan->producerIds as $producerId) {
                $target = $this->targets->valueFor('producer', $producerId, $plan->periodType, $plan->periodStart, $plan->metric);
                $producer = $this->producers->get($producerId);
                if ($target === null || (string) DB::table('branches')->where('id', $producer->branchId)->value('entity_id') !== $entityId) {
                    continue;
                }
                $actual = $actuals[$producerId] ?? 0;
                $result = $plan->bonusFor($target, $actual);
                if ($result['bonus_minor'] <= 0) {
                    continue;
                }
                $awards += DB::transaction(function () use ($plan, $producer, $entityId, $currency, $periodEnd, $target, $actual, $result, $actorUserId): int {
                    $awardId = (string) Str::uuid7();
                    $inserted = DB::table('incentive_awards')->insertOrIgnore(['id' => $awardId, 'tenant_id' => TenantContext::id(), 'plan_id' => $plan->planId, 'producer_id' => $producer->id,
                        'period_start' => $plan->periodStart->toDateString(), 'period_end' => $plan->periodEnd->toDateString(), 'target_value' => $target, 'actual_value' => $actual,
                        'achievement_bp' => $result['achievement_bp'], 'tier' => (int) $result['tier'], 'bonus_minor' => $result['bonus_minor']]);
                    if ($inserted === 0) {
                        return 0;
                    }
                    $withholdingBp = $plan->withholdingTaxType === null ? 0 : $this->taxRates->withholdingRateOn((string) $plan->withholdingJurisdiction, $plan->withholdingTaxType, $periodEnd);
                    $withholding = PremiumMath::prorate($result['bonus_minor'], $withholdingBp, 10_000);
                    $entry = CommissionEntry::query()->create(['entity_id' => $entityId, 'branch_id' => $producer->branchId, 'agent_id' => $producer->id, 'policy_id' => null,
                        'kind' => 'bonus', 'beneficiary_role' => 'direct', 'base_minor' => $actual, 'rate_bp' => null, 'amount_minor' => $result['bonus_minor'],
                        'withholding_minor' => $withholding, 'withholding_bp' => $withholdingBp, 'currency' => $currency, 'earned_on' => $periodEnd->toDateString(), 'status' => 'accrued']);
                    DB::table('incentive_awards')->where('id', $awardId)->update(['commission_entry_id' => $entry->id]);
                    ($this->submit)(entityId: $entityId, eventType: 'INCENTIVE_BONUS_EARNED', sourceType: 'commission_entry', sourceId: $entry->id,
                        idempotencyKey: 'INCENTIVE_BONUS_EARNED:'.$entry->id, transactionDate: $periodEnd, effectiveDate: $periodEnd, currency: $currency,
                        payload: ['amount' => $entry->amount_minor, 'withholding' => $entry->withholding_minor, 'commission_entry_id' => $entry->id, 'incentive_award_id' => $awardId],
                        dimensions: ['branch' => $producer->branchId, 'agent' => $producer->id]);
                    $this->audit->record('incentive.awarded', AuditSubject::of('producer', $producer->id), null, ['plan' => $plan->code, 'period_start' => $plan->periodStart->toDateString(),
                        'target' => $target, 'actual' => $actual, 'achievement_bp' => $result['achievement_bp'], 'bonus_minor' => $result['bonus_minor']], null, 'commission.approve', Actor::user($actorUserId));

                    return 1;
                });
            }
        }

        return $awards;
    }
}
