<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reinsurance\Application;

use App\Modules\Insurance\Policy\Domain\Enums\PolicyStatus;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Reinsurance\Domain\RiMath;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Facultative placements (ri.place_facultative on the policy's branch): a share of one policy placed with a reinsurer on its own slip — share %, premium and
 * commission agreed with the reinsurer, typed in — usually for the part above treaty capacity. The placement cedes at once (RI_PREMIUM_CEDED).
 * ASSUMPTION A-258: a placement may be made on any issued or active policy; the ceded sum insured is the share of the whole sum insured unless given.
 */
final class FacultativeService
{
    public const PERMISSION = 'ri.place_facultative';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly CessionEngine $cessions,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation RI_POLICY_NOT_IN_FORCE | RI_REINSURER_INACTIVE | RI_FACULTATIVE_INVALID | RI_SHARE_EXCEEDS_RISK */
    public function place(string $policyId, string $reinsurerId, int $shareBp, ?int $cededSumInsuredMinor, int $premiumMinor, int $commissionBp, ?string $slipReference,
        CarbonImmutable $placedOn, string $actorUserId): string
    {
        $policy = Policy::query()->findOrFail($policyId);
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::branch($policy->entity_id, $policy->branch_id));
        if (! in_array($policy->status, [PolicyStatus::Issued, PolicyStatus::Active], true)) {
            throw new BusinessRuleViolation('RI_POLICY_NOT_IN_FORCE', 'Facultative reinsurance is placed on an issued or active policy.');
        }
        if (! DB::table('reinsurers')->where('id', $reinsurerId)->where('status', 'active')->exists()) {
            throw new BusinessRuleViolation('RI_REINSURER_INACTIVE', 'Choose an active reinsurer.');
        }
        if ($shareBp <= 0 || $shareBp > 10_000 || $premiumMinor <= 0 || $commissionBp < 0 || $commissionBp > 10_000) {
            throw new BusinessRuleViolation('RI_FACULTATIVE_INVALID', 'Enter a share above 0% and up to 100%, a premium above zero and a commission between 0% and 100%.');
        }
        $sumInsured = CessionEngine::sumInsuredOf($policy);
        $cededSi = $cededSumInsuredMinor ?? RiMath::bp($sumInsured, $shareBp);
        $alreadyCeded = (int) DB::table('ri_cessions')->where('policy_id', $policy->id)->sum('ceded_sum_insured_minor');
        if ($sumInsured > 0 && $alreadyCeded + $cededSi > $sumInsured) {
            throw new BusinessRuleViolation('RI_SHARE_EXCEEDS_RISK', 'This placement would cede more than the policy\'s sum insured. Reduce the share or the ceded sum insured.');
        }
        $commission = RiMath::bp($premiumMinor, $commissionBp);

        return DB::transaction(function () use ($policy, $reinsurerId, $shareBp, $cededSi, $premiumMinor, $commissionBp, $commission, $slipReference, $placedOn, $actorUserId): string {
            $id = (string) Str::uuid7();
            DB::table('ri_facultative_placements')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $policy->entity_id, 'policy_id' => $policy->id,
                'reinsurer_id' => $reinsurerId, 'slip_reference' => $slipReference, 'share_bp' => $shareBp, 'ceded_sum_insured_minor' => $cededSi, 'premium_minor' => $premiumMinor,
                'commission_bp' => $commissionBp, 'commission_minor' => $commission, 'placed_on' => $placedOn->toDateString(), 'currency' => $policy->currency,
                'placed_by' => $actorUserId, 'created_at' => now(), 'updated_at' => now()]);
            $this->cessions->writePlacement($policy, $id, $reinsurerId, $shareBp, $cededSi, $premiumMinor, $commission, $placedOn);
            $above = (int) DB::table('ri_policy_positions')->where('policy_id', $policy->id)->value('above_capacity_minor');
            DB::table('ri_policy_positions')->where('policy_id', $policy->id)->update(['above_capacity_minor' => max(0, $above - $cededSi), 'updated_at' => now()]
                + ($above > 0 && $above - $cededSi <= 0 ? ['note' => 'The part above treaty capacity is placed facultatively.'] : []));
            $this->audit->record('ri_facultative.placed', AuditSubject::of('policy', $policy->id), null, ['placement_id' => $id, 'reinsurer_id' => $reinsurerId, 'share_bp' => $shareBp,
                'ceded_sum_insured_minor' => $cededSi, 'premium_minor' => $premiumMinor, 'commission_minor' => $commission, 'slip_reference' => $slipReference], null, self::PERMISSION, Actor::user($actorUserId));

            return $id;
        });
    }
}
