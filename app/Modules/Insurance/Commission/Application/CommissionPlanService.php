<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use App\Modules\Insurance\Commission\Domain\Models\CommissionPlan;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/** Commission plans (spec §4 Commission "rules per product", config is data). Plans attach to product versions and agents. */
final class CommissionPlanService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation INVALID_RATE | INVALID_WITHHOLDING | DUPLICATE_PLAN_CODE */
    public function create(string $code, string $name, int $rateBp, ?string $withholdingJurisdiction, ?string $withholdingTaxType, string $actorUserId): CommissionPlan
    {
        $this->permissions->authorize($actorUserId, 'commission.manage_plans');
        if ($rateBp < 0 || $rateBp > 10_000) {
            throw new BusinessRuleViolation('INVALID_RATE', 'A commission rate must be between 0 and 10000 basis points.');
        }
        if (($withholdingJurisdiction === null) !== ($withholdingTaxType === null)) {
            throw new BusinessRuleViolation('INVALID_WITHHOLDING', 'Withholding needs both a jurisdiction and a tax type, or neither.');
        }
        if (CommissionPlan::query()->where('code', $code)->exists()) {
            throw new BusinessRuleViolation('DUPLICATE_PLAN_CODE', "Commission plan {$code} already exists.");
        }

        return DB::transaction(function () use ($code, $name, $rateBp, $withholdingJurisdiction, $withholdingTaxType, $actorUserId): CommissionPlan {
            $plan = CommissionPlan::query()->create(['code' => $code, 'name' => $name, 'rate_bp' => $rateBp,
                'withholding_jurisdiction' => $withholdingJurisdiction, 'withholding_tax_type' => $withholdingTaxType, 'status' => 'active']);
            $this->audit->record('commission_plan.created', AuditSubject::of('commission_plan', $plan->id), null,
                $plan->only(['code', 'name', 'rate_bp', 'withholding_jurisdiction', 'withholding_tax_type']), null, 'commission.manage_plans', Actor::user($actorUserId));

            return $plan;
        });
    }
}
