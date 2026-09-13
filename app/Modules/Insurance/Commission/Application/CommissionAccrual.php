<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use App\Modules\Distribution\Application\Compensation\CommissionAward;
use App\Modules\Distribution\Application\Compensation\CompensationEngine;
use App\Modules\Distribution\Application\Compensation\CompensationRequest;
use App\Modules\Distribution\Application\Compensation\FlatCommissionTerms;
use App\Modules\Insurance\Commission\Domain\Models\CommissionEntry;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
use App\Modules\Platform\Tax\TaxRates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The commission subledger's side of Distribution design note §2 (slice D5): asks the compensation engine who earns what on a premium, then
 * records one entry per beneficiary — with scheme, rule, level and the hierarchy snapshot of the day — and posts COMMISSION_EARNED for each
 * unconditional entry, inside the trigger's transaction. The product version's compensation scheme decides; a version without one falls back
 * to the Phase 1 plan (A-7 precedence) as flat terms.
 */
final class CommissionAccrual
{
    public function __construct(
        private readonly CompensationEngine $engine,
        private readonly CommissionPlanResolver $plans,
        private readonly TaxRates $taxRates,
        private readonly CommissionAccountingEvents $accounting,
    ) {}

    /** @param array{receipt_allocation_id?: string, policy_transaction_id?: string} $source the trigger row the entries hang on */
    public function accrue(Policy $policy, string $basis, int $baseMinor, int $policyYear, CarbonImmutable $on, array $source): void
    {
        if ($policy->agent_id === null || $baseMinor === 0) {
            return;
        }
        $schemeId = ProductVersion::query()->whereKey($policy->product_version_id)->value('compensation_scheme_id');
        $flat = null;
        if ($schemeId === null) {
            $plan = $basis === 'premium_received' ? $this->plans->planFor($policy) : null;
            if ($plan === null) {
                return;
            }
            $flat = new FlatCommissionTerms($plan->id, $plan->rate_bp, $plan->withholding_tax_type === null ? 0
                : $this->taxRates->withholdingRateOn((string) $plan->withholding_jurisdiction, $plan->withholding_tax_type, $on));
        }
        [$sourceType, $sourceId] = isset($source['receipt_allocation_id']) ? ['receipt_allocation', $source['receipt_allocation_id']] : ['policy_transaction', (string) ($source['policy_transaction_id'] ?? '')];

        $outcome = $this->engine->calculate(new CompensationRequest($policy->id, $policy->product_id, (string) DB::table('products')->where('id', $policy->product_id)->value('insurance_class'),
            $policy->agent_id, $basis, $baseMinor, $policyYear, $on, $sourceType, $sourceId, $schemeId === null ? null : (string) $schemeId, $flat));

        foreach ($outcome->awards as $award) {
            /** @var CommissionAward $award */
            $entry = CommissionEntry::query()->create([
                'entity_id' => $policy->entity_id, 'branch_id' => $policy->branch_id, 'agent_id' => $award->producerId, 'policy_id' => $policy->id,
                'receipt_allocation_id' => $source['receipt_allocation_id'] ?? null, 'policy_transaction_id' => $source['policy_transaction_id'] ?? null,
                'commission_plan_id' => $outcome->planId, 'scheme_id' => $outcome->schemeId, 'rule_id' => $award->ruleId, 'beneficiary_role' => $award->role,
                'level_code' => $award->levelCode, 'hierarchy_snapshot' => json_encode($outcome->hierarchySnapshot, JSON_THROW_ON_ERROR), 'kind' => 'earned',
                'base_minor' => $baseMinor, 'rate_bp' => $award->rateBp, 'withholding_bp' => $outcome->withholdingBp, 'amount_minor' => $award->amountMinor, 'withholding_minor' => $award->withholdingMinor,
                'currency' => $policy->currency, 'earned_on' => $on->toDateString(), 'status' => $award->conditional ? 'conditional' : 'accrued',
            ]);
            if (! $award->conditional) {
                $this->accounting->earned($entry, $policy, $outcome->withholdingBp);
            }
        }
    }
}
