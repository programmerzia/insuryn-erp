<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use App\Modules\Insurance\Commission\Domain\Models\CommissionPlan;
use App\Modules\Insurance\Party\Domain\Models\Agent;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;

/**
 * Which plan pays commission on a policy. ASSUMPTION: A-7 — which of the product version's and the agent's plan wins is not
 * specified; the default is the product version's (erp.commission.plan_precedence), falling back to the other. No plan → no commission.
 */
final class CommissionPlanResolver
{
    /** @throws BusinessRuleViolation COMMISSION_PLAN_MISSING when a referenced plan does not exist */
    public function planFor(Policy $policy): ?CommissionPlan
    {
        if ($policy->agent_id === null) {
            return null;
        }
        /** @var list<string> $precedence */
        $precedence = config('erp.commission.plan_precedence', ['product_version', 'agent']);
        foreach ($precedence as $source) {
            $planId = match ($source) {
                'product_version' => ProductVersion::query()->whereKey($policy->product_version_id)->value('commission_plan_id'),
                'agent' => Agent::query()->whereKey($policy->agent_id)->value('commission_plan_id'),
                default => null,
            };
            if ($planId !== null) {
                return CommissionPlan::query()->whereKey($planId)->first()
                    ?? throw new BusinessRuleViolation('COMMISSION_PLAN_MISSING', "Commission plan {$planId} ({$source}) does not exist.");
            }
        }

        return null;
    }
}
