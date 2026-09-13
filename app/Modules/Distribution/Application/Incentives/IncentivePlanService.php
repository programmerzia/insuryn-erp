<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Incentives;

use App\Modules\Distribution\Domain\Enums\ProducerType;
use App\Modules\Distribution\Domain\Incentives\IncentivePeriod;
use App\Modules\Distribution\Domain\Incentives\IncentiveTiers;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Incentive plans (Distribution design note §1 incentive_plans): period, metric, tiers and whom they apply to (producer type, channel, level). */
final class IncentivePlanService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /** @param array<string, mixed> $plan code, name, period_type, metric, tiers, applies_to {producer_type?, channel_id?, level_code?}, effective_from, effective_to?, withholding_jurisdiction?, withholding_tax_type? */
    public function create(array $plan, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, 'commission.manage_plans');
        $periodType = (string) ($plan['period_type'] ?? '');
        try {
            $tiers = IncentiveTiers::fromArray((string) ($plan['metric'] ?? ''), (array) ($plan['tiers'] ?? []));
        } catch (DomainException $invalid) {
            throw new BusinessRuleViolation('INCENTIVE_PLAN_INVALID', $invalid->getMessage());
        }
        $appliesTo = (array) ($plan['applies_to'] ?? []);
        if (! in_array($periodType, IncentivePeriod::TYPES, true) || array_diff(array_keys($appliesTo), ['producer_type', 'channel_id', 'level_code']) !== []
            || (isset($appliesTo['producer_type']) && ProducerType::tryFrom((string) $appliesTo['producer_type']) === null)
            || ! is_string($plan['code'] ?? null) || ! is_string($plan['name'] ?? null) || ! is_string($plan['effective_from'] ?? null)
            || (($plan['withholding_jurisdiction'] ?? null) === null) !== (($plan['withholding_tax_type'] ?? null) === null)) {
            throw new BusinessRuleViolation('INCENTIVE_PLAN_INVALID', 'A plan needs a code, name, monthly/quarterly/annual period, effective date, applies_to by producer_type, channel_id or level_code, and withholding as a pair or not at all.');
        }

        return DB::transaction(function () use ($plan, $periodType, $tiers, $appliesTo, $actorUserId): string {
            if (DB::table('incentive_plans')->where('code', $plan['code'])->exists()) {
                throw new BusinessRuleViolation('INCENTIVE_PLAN_INVALID', "An incentive plan with code {$plan['code']} already exists.");
            }
            $id = (string) Str::uuid7();
            DB::table('incentive_plans')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'code' => $plan['code'], 'name' => $plan['name'], 'period_type' => $periodType,
                'metric' => $tiers->metric, 'tiers' => json_encode($tiers->tiers, JSON_THROW_ON_ERROR), 'applies_to' => json_encode((object) $appliesTo, JSON_THROW_ON_ERROR),
                'effective_from' => $plan['effective_from'], 'effective_to' => $plan['effective_to'] ?? null, 'withholding_jurisdiction' => $plan['withholding_jurisdiction'] ?? null,
                'withholding_tax_type' => $plan['withholding_tax_type'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('incentive_plan.created', AuditSubject::of('incentive_plan', $id), null, ['code' => $plan['code'], 'period_type' => $periodType, 'metric' => $tiers->metric,
                'tiers' => $tiers->tiers, 'applies_to' => $appliesTo], null, 'commission.manage_plans', Actor::user($actorUserId));

            return $id;
        });
    }
}
