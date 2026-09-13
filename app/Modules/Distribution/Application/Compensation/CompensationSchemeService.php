<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Compensation;

use App\Modules\Distribution\Domain\ComplianceProfile;
use App\Modules\Distribution\Domain\Enums\CommissionBasis;
use App\Modules\Distribution\Domain\Enums\CompensationMode;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compensation schemes and their rules (Distribution design note §0–§1, slice D4). "Enforced at plan creation": every rule is checked against the
 * scheme's mode and compliance profile when written, and a profile change is refused when an existing rule would break it. The calculation (D5)
 * enforces the same profile again per policy.
 */
final class CompensationSchemeService
{
    private const PERMISSION = 'commission.manage_plans';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /** @param array<mixed> $complianceProfile */
    public function createScheme(string $code, string $name, string $mode, CarbonImmutable $effectiveFrom, ?CarbonImmutable $effectiveTo, array $complianceProfile, string $actorUserId,
        ?string $withholdingJurisdiction = null, ?string $withholdingTaxType = null): string
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION);
        $modeValue = CompensationMode::tryFrom($mode) ?? throw new BusinessRuleViolation('SCHEME_MODE_INVALID', "Mode {$mode} is not commission, salary_incentive, hybrid or none.");
        $profile = $this->profile($complianceProfile);
        if ($effectiveTo !== null && $effectiveTo->lessThanOrEqualTo($effectiveFrom)) {
            throw new BusinessRuleViolation('SCHEME_DATES_INVALID', 'A scheme must end after it starts.');
        }
        if (($withholdingJurisdiction === null) !== ($withholdingTaxType === null)) {
            throw new BusinessRuleViolation('INVALID_WITHHOLDING', 'Withholding needs both a jurisdiction and a tax type, or neither.');
        }

        return DB::transaction(function () use ($code, $name, $modeValue, $effectiveFrom, $effectiveTo, $profile, $actorUserId, $withholdingJurisdiction, $withholdingTaxType): string {
            if (DB::table('compensation_schemes')->where('code', $code)->exists()) {
                throw new BusinessRuleViolation('SCHEME_CODE_TAKEN', "A compensation scheme with code {$code} already exists.");
            }
            $id = (string) Str::uuid7();
            DB::table('compensation_schemes')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'code' => $code, 'name' => $name, 'mode' => $modeValue->value,
                'effective_from' => $effectiveFrom->toDateString(), 'effective_to' => $effectiveTo?->toDateString(), 'compliance_profile' => json_encode($profile->toArray(), JSON_THROW_ON_ERROR),
                'withholding_jurisdiction' => $withholdingJurisdiction, 'withholding_tax_type' => $withholdingTaxType, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('compensation_scheme.created', AuditSubject::of('compensation_scheme', $id), null,
                ['code' => $code, 'mode' => $modeValue->value, 'compliance_profile' => $profile->toArray()], null, self::PERMISSION, Actor::user($actorUserId));

            return $id;
        });
    }

    /** @param array<mixed> $complianceProfile the complete new profile */
    public function updateComplianceProfile(string $schemeId, array $complianceProfile, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION);
        $profile = $this->profile($complianceProfile);

        DB::transaction(function () use ($schemeId, $profile, $actorUserId): void {
            $scheme = $this->lockScheme($schemeId);
            $rules = DB::table('compensation_rules')->where('scheme_id', $schemeId)->orderBy('created_at')->get();
            foreach ($rules as $rule) {
                $this->assertRuleFits(CompensationMode::from((string) $scheme->mode), $profile, $schemeId, self::requestFromRow($rule), (string) $rule->id);
            }
            $before = json_decode((string) $scheme->compliance_profile, true);
            DB::table('compensation_schemes')->where('id', $schemeId)->update(['compliance_profile' => json_encode($profile->toArray(), JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            $this->audit->record('compensation_scheme.profile_changed', AuditSubject::of('compensation_scheme', $schemeId), ['compliance_profile' => $before],
                ['compliance_profile' => $profile->toArray()], null, self::PERMISSION, Actor::user($actorUserId));
        });
    }

    /** @return string the rule id */
    public function addRule(string $schemeId, CompensationRuleRequest $rule, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION);

        return DB::transaction(function () use ($schemeId, $rule, $actorUserId): string {
            $scheme = $this->lockScheme($schemeId);
            $profile = ComplianceProfile::fromArray((array) json_decode((string) $scheme->compliance_profile, true));
            if ($rule->effectiveFrom->lessThan(CarbonImmutable::parse((string) $scheme->effective_from))
                || ($scheme->effective_to !== null && ($rule->effectiveTo === null || $rule->effectiveTo->greaterThan(CarbonImmutable::parse((string) $scheme->effective_to))))) {
                throw new BusinessRuleViolation('RULE_OUTSIDE_SCHEME', "A rule must fall within its scheme's dates ({$scheme->effective_from} onwards).");
            }
            $this->assertRuleFits(CompensationMode::from((string) $scheme->mode), $profile, $schemeId, $rule, null);

            $id = (string) Str::uuid7();
            DB::table('compensation_rules')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'scheme_id' => $schemeId, 'product_id' => $rule->productId,
                'producer_type' => $rule->producerType, 'level_code' => $rule->levelCode, 'basis' => $rule->basis, 'policy_year_from' => $rule->policyYearFrom,
                'policy_year_to' => $rule->policyYearTo, 'rate_bp' => $rule->rateBp, 'override_rate_bp' => $rule->overrideRateBp, 'cap_bp' => $rule->capBp,
                'min_persistency_bp' => $rule->minPersistencyBp, 'renewal_requires_valid_licence' => $rule->renewalRequiresValidLicence, 'pays_after_termination' => $rule->paysAfterTermination,
                'effective_from' => $rule->effectiveFrom->toDateString(), 'effective_to' => $rule->effectiveTo?->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('compensation_rule.added', AuditSubject::of('compensation_scheme', $schemeId), null, ['rule_id' => $id, ...(array) DB::table('compensation_rules')->where('id', $id)->first()],
                null, self::PERMISSION, Actor::user($actorUserId));

            return $id;
        });
    }

    /** Ends a rule from a date (rules are never edited in place: payouts must be explainable by the rule that applied). */
    public function endRule(string $ruleId, CarbonImmutable $effectiveTo, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION);

        DB::transaction(function () use ($ruleId, $effectiveTo, $actorUserId): void {
            $rule = DB::table('compensation_rules')->where('id', $ruleId)->lockForUpdate()->first(['scheme_id', 'effective_from', 'effective_to']);
            if ($rule === null) {
                throw new \Illuminate\Database\RecordsNotFoundException("Compensation rule {$ruleId} does not exist.");
            }
            if ($effectiveTo->lessThanOrEqualTo(CarbonImmutable::parse((string) $rule->effective_from))) {
                throw new BusinessRuleViolation('RULE_INVALID', 'A rule must end after it starts.');
            }
            DB::table('compensation_rules')->where('id', $ruleId)->update(['effective_to' => $effectiveTo->toDateString(), 'updated_at' => now()]);
            $this->audit->record('compensation_rule.ended', AuditSubject::of('compensation_scheme', (string) $rule->scheme_id), ['rule_id' => $ruleId, 'effective_to' => $rule->effective_to],
                ['rule_id' => $ruleId, 'effective_to' => $effectiveTo->toDateString()], null, self::PERMISSION, Actor::user($actorUserId));
        });
    }

    /** @throws BusinessRuleViolation the first rule a scheme's mode or compliance profile does not allow */
    private function assertRuleFits(CompensationMode $mode, ComplianceProfile $profile, string $schemeId, CompensationRuleRequest $rule, ?string $ruleId): void
    {
        if (CommissionBasis::tryFrom($rule->basis) === null || $rule->policyYearFrom < 1 || $rule->policyYearTo < $rule->policyYearFrom || $rule->policyYearTo > 99
            || $rule->rateBp < 0 || $rule->rateBp > 10_000 || $rule->overrideRateBp < 0 || $rule->overrideRateBp > 10_000
            || ($rule->effectiveTo !== null && $rule->effectiveTo->lessThanOrEqualTo($rule->effectiveFrom))) {
            throw new BusinessRuleViolation('RULE_INVALID', 'A rule needs a known basis, policy years within 1–99 (from ≤ to), rates between 0 and 10000 basis points and an end after its start.');
        }
        if (! $mode->paysCommission()) {
            throw new BusinessRuleViolation('COMMISSION_NOT_ALLOWED_BY_MODE', "A {$mode->value} scheme pays no commission; pay its producers through incentive plans.");
        }
        if ($rule->producerType !== null && ! $profile->allows($rule->producerType)) {
            throw new BusinessRuleViolation('PRODUCER_TYPE_NOT_ALLOWED', "The scheme's compliance profile does not allow commission to {$rule->producerType} producers.");
        }
        if ($rule->overrideRateBp > 0 && $rule->levelCode === null) {
            throw new BusinessRuleViolation('OVERRIDE_NEEDS_LEVEL', 'An override is paid to a hierarchy level; choose the level.');
        }
        if ($rule->levelCode !== null && ! DB::table('hierarchy_levels')->where('scheme_id', $schemeId)->where('level_code', $rule->levelCode)->exists()) {
            throw new BusinessRuleViolation('HIERARCHY_LEVEL_UNKNOWN', "Level {$rule->levelCode} is not defined in this scheme.");
        }
        if ($rule->capBp !== null && ($rule->rateBp > $rule->capBp || $rule->overrideRateBp > $rule->capBp)) {
            throw new BusinessRuleViolation('RULE_RATE_ABOVE_RULE_CAP', "The rule's rate is above its own cap of {$rule->capBp} basis points.");
        }
        if ($rule->productId !== null && ! $profile->nonLifeCommissionAllowed
            && DB::table('products')->where('id', $rule->productId)->value('insurance_class') === 'non_life') {
            throw new BusinessRuleViolation('NON_LIFE_COMMISSION_DISABLED', 'Commission on non-life products is disabled in this scheme\'s compliance profile.');
        }
        $this->assertWithinCaps($profile, $schemeId, $rule, $ruleId);
    }

    /**
     * Worst case for each policy year the rule covers: the highest direct rate among overlapping rules plus the highest override of every level
     * (a policy pays one direct commission and at most one override per level above the seller). It must stay within the lowest cap in force.
     */
    private function assertWithinCaps(ComplianceProfile $profile, string $schemeId, CompensationRuleRequest $rule, ?string $ruleId): void
    {
        $others = DB::table('compensation_rules')->where('scheme_id', $schemeId)->when($ruleId !== null, fn ($q) => $q->where('id', '<>', $ruleId))
            ->where(fn ($q) => $rule->productId === null ? $q : $q->whereNull('product_id')->orWhere('product_id', $rule->productId))
            ->where('policy_year_from', '<=', $rule->policyYearTo)->where('policy_year_to', '>=', $rule->policyYearFrom)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $rule->effectiveFrom->toDateString()))
            ->when($rule->effectiveTo !== null, fn ($q) => $q->where('effective_from', '<', $rule->effectiveTo?->toDateString()))
            ->get(['policy_year_from', 'policy_year_to', 'rate_bp', 'override_rate_bp', 'level_code']);

        for ($year = $rule->policyYearFrom; $year <= $rule->policyYearTo; $year++) {
            $cap = $profile->capFor($rule->productId, $year);
            if ($cap === null) {
                continue;
            }
            $inYear = $others->filter(fn (\stdClass $r): bool => $year >= (int) $r->policy_year_from && $year <= (int) $r->policy_year_to)->values();
            $direct = max($rule->rateBp, (int) $inYear->max('rate_bp'));
            $overrides = [];
            foreach ([...$inYear->all(), (object) ['level_code' => $rule->levelCode, 'override_rate_bp' => $rule->overrideRateBp]] as $r) {
                if ($r->level_code !== null) {
                    $overrides[(string) $r->level_code] = max($overrides[(string) $r->level_code] ?? 0, (int) $r->override_rate_bp);
                }
            }
            $total = $direct + array_sum($overrides);
            if ($total > $cap) {
                throw new BusinessRuleViolation('COMPLIANCE_CAP_EXCEEDED', "In policy year {$year} commission could reach {$total} basis points (direct and overrides), above the cap of {$cap}.");
            }
        }
    }

    /** @param array<mixed> $profile */
    private function profile(array $profile): ComplianceProfile
    {
        try {
            return ComplianceProfile::fromArray($profile);
        } catch (DomainException $invalid) {
            throw new BusinessRuleViolation('COMPLIANCE_PROFILE_INVALID', $invalid->getMessage());
        }
    }

    private function lockScheme(string $schemeId): \stdClass
    {
        return DB::table('compensation_schemes')->where('id', $schemeId)->lockForUpdate()->first()
            ?? throw new BusinessRuleViolation('COMPENSATION_SCHEME_UNKNOWN', "Compensation scheme {$schemeId} does not exist.");
    }

    private static function requestFromRow(\stdClass $rule): CompensationRuleRequest
    {
        return CompensationRuleRequest::fromArray((array) $rule);
    }
}
