<?php

declare(strict_types=1);

namespace App\Modules\People\Payroll\Application;

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
 * Payroll rules as data (design §B.10.2, spec §6): payroll settings per entity (provident fund, festival bonuses, tax exemption and minimum tax), salary
 * structures per grade (house rent, medical, conveyance) and the income tax slab table per tax year. Nothing Bangladeshi is in code: the Bangladesh values
 * are seeded as placeholders flagged `verify` (CQ-J1, CQ-J3, CQ-J4) and edited on the settings screen with `payroll.manage_rules`.
 *
 * DECISION D-123: MVP edits rules in place of the §B.10.2 versioned rule sets with approval: a structure or settings change takes effect from a date (the row in
 * force ends the day before), slabs are replaced per tax year, every change is audited; a run already posted keeps its payslips and trace.
 */
final class PayrollRules
{
    public const PERMISSION = 'payroll.manage_rules';

    public function __construct(private readonly PermissionChecker $permissions, private readonly Audit $audit) {}

    /**
     * @return array{id: string, pf_employee_bp: int, pf_employer_bp: int, pf_employment_types: list<string>, festival_bonus_bp: int, festival_bonus_min_service_months: int,
     *     festivals: list<array{name: string, month: string}>, tax_year_start_month: int, tax_exempt_fraction_bp: int, tax_exempt_cap_minor: int, minimum_tax_minor: int,
     *     tax_category: string, commission_taxable: bool, verify: bool, effective_from: string}|null
     */
    public function settingsOn(string $entityId, CarbonImmutable $day): ?array
    {
        $row = DB::table('payroll_settings')->where('entity_id', $entityId)->where('effective_from', '<=', $day->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $day->toDateString()))->first();
        if ($row === null) {
            return null;
        }
        /** @var list<string> $types */
        $types = json_decode((string) $row->pf_employment_types, true, 512, JSON_THROW_ON_ERROR);
        /** @var list<array{name: string, month: string}> $festivals */
        $festivals = json_decode((string) $row->festivals, true, 512, JSON_THROW_ON_ERROR);

        return ['id' => (string) $row->id, 'pf_employee_bp' => (int) $row->pf_employee_bp, 'pf_employer_bp' => (int) $row->pf_employer_bp, 'pf_employment_types' => $types,
            'festival_bonus_bp' => (int) $row->festival_bonus_bp, 'festival_bonus_min_service_months' => (int) $row->festival_bonus_min_service_months, 'festivals' => $festivals,
            'tax_year_start_month' => (int) $row->tax_year_start_month, 'tax_exempt_fraction_bp' => (int) $row->tax_exempt_fraction_bp, 'tax_exempt_cap_minor' => (int) $row->tax_exempt_cap_minor,
            'minimum_tax_minor' => (int) $row->minimum_tax_minor, 'tax_category' => (string) $row->tax_category, 'commission_taxable' => (bool) $row->commission_taxable,
            'verify' => (bool) $row->verify, 'effective_from' => (string) $row->effective_from];
    }

    /** @return array<string, array{id: string, house_rent_bp: int, medical_bp: int, medical_cap_minor: int|null, conveyance_minor: int, basic_min_minor: int, basic_max_minor: int|null, verify: bool}> by grade id */
    public function structuresOn(CarbonImmutable $day): array
    {
        $out = [];
        foreach (DB::table('salary_structures')->where('effective_from', '<=', $day->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $day->toDateString()))->get() as $row) {
            $out[(string) $row->grade_id] = ['id' => (string) $row->id, 'house_rent_bp' => (int) $row->house_rent_bp, 'medical_bp' => (int) $row->medical_bp,
                'medical_cap_minor' => $row->medical_cap_minor === null ? null : (int) $row->medical_cap_minor, 'conveyance_minor' => (int) $row->conveyance_minor,
                'basic_min_minor' => (int) $row->basic_min_minor, 'basic_max_minor' => $row->basic_max_minor === null ? null : (int) $row->basic_max_minor, 'verify' => (bool) $row->verify];
        }

        return $out;
    }

    /** @return list<array{band_minor: int|null, rate_bp: int}> */
    public function slabs(string $taxYear, string $category): array
    {
        return array_values(DB::table('payroll_tax_slabs')->where('tax_year', $taxYear)->where('category', $category)->orderBy('seq')->get(['band_minor', 'rate_bp'])
            ->map(fn (object $s): array => ['band_minor' => $s->band_minor === null ? null : (int) $s->band_minor, 'rate_bp' => (int) $s->rate_bp])->all());
    }

    /** "2026-27" for a month in the tax year starting in $startMonth. */
    public static function taxYear(int $year, int $month, int $startMonth): string
    {
        $first = $month >= $startMonth ? $year : $year - 1;

        return $first.'-'.str_pad((string) (($first + 1) % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param array{pf_employee_bp: int, pf_employer_bp: int, pf_employment_types: list<string>, festival_bonus_bp: int, festival_bonus_min_service_months: int,
     *     festivals: list<array{name: string, month: string}>, tax_year_start_month?: int, tax_exempt_fraction_bp: int, tax_exempt_cap_minor: int, minimum_tax_minor: int,
     *     tax_category?: string, commission_taxable?: bool, verify?: bool} $values
     *
     * @throws BusinessRuleViolation PAYROLL_RULE_INVALID
     */
    public function saveSettings(string $entityId, CarbonImmutable $from, array $values, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::entity($entityId));
        foreach (['pf_employee_bp', 'pf_employer_bp', 'festival_bonus_bp', 'tax_exempt_fraction_bp'] as $bp) {
            if ($values[$bp] < 0 || $values[$bp] > 10_000) {
                throw new BusinessRuleViolation('PAYROLL_RULE_INVALID', 'Percentages must be between 0% and 100%.');
            }
        }
        foreach ($values['festivals'] as $festival) {
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $festival['month']) !== 1 || trim($festival['name']) === '') {
                throw new BusinessRuleViolation('PAYROLL_RULE_INVALID', 'Each festival needs a name and a month.');
            }
        }

        return DB::transaction(function () use ($entityId, $from, $values, $actorUserId): string {
            $current = DB::table('payroll_settings')->where('entity_id', $entityId)->whereNull('effective_to')->lockForUpdate()->first(['id', 'effective_from']);
            $row = ['pf_employee_bp' => $values['pf_employee_bp'], 'pf_employer_bp' => $values['pf_employer_bp'], 'pf_employment_types' => json_encode($values['pf_employment_types'], JSON_THROW_ON_ERROR),
                'festival_bonus_bp' => $values['festival_bonus_bp'], 'festival_bonus_min_service_months' => $values['festival_bonus_min_service_months'],
                'festivals' => json_encode($values['festivals'], JSON_THROW_ON_ERROR), 'tax_year_start_month' => $values['tax_year_start_month'] ?? 7,
                'tax_exempt_fraction_bp' => $values['tax_exempt_fraction_bp'], 'tax_exempt_cap_minor' => $values['tax_exempt_cap_minor'], 'minimum_tax_minor' => $values['minimum_tax_minor'],
                'tax_category' => $values['tax_category'] ?? 'general', 'commission_taxable' => $values['commission_taxable'] ?? false, 'verify' => $values['verify'] ?? true,
                'updated_by' => $actorUserId, 'updated_at' => now()];
            if ($current !== null && (string) $current->effective_from >= $from->toDateString()) {
                DB::table('payroll_settings')->where('id', $current->id)->update($row);
                $id = (string) $current->id;
            } else {
                if ($current !== null) {
                    DB::table('payroll_settings')->where('id', $current->id)->update(['effective_to' => $from->toDateString()]);
                }
                $id = (string) Str::uuid7();
                DB::table('payroll_settings')->insert($row + ['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId, 'effective_from' => $from->toDateString(), 'created_at' => now()]);
            }
            $this->audit->record('payroll_settings.saved', AuditSubject::of('payroll_settings', $id), null, $values + ['effective_from' => $from->toDateString()], null, self::PERMISSION, Actor::user($actorUserId));

            return $id;
        });
    }

    /**
     * @param array{house_rent_bp: int, medical_bp: int, medical_cap_minor: int|null, conveyance_minor: int, basic_min_minor?: int, basic_max_minor?: int|null, verify?: bool} $values
     *
     * @throws BusinessRuleViolation PAYROLL_RULE_INVALID
     */
    public function saveStructure(string $gradeId, CarbonImmutable $from, array $values, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION);
        if ($values['house_rent_bp'] < 0 || $values['medical_bp'] < 0 || $values['conveyance_minor'] < 0 || $values['house_rent_bp'] > 20_000 || $values['medical_bp'] > 10_000) {
            throw new BusinessRuleViolation('PAYROLL_RULE_INVALID', 'Allowances must be zero or more, house rent at most 200% and medical at most 100% of basic.');
        }

        return DB::transaction(function () use ($gradeId, $from, $values, $actorUserId): string {
            $current = DB::table('salary_structures')->where('grade_id', $gradeId)->whereNull('effective_to')->lockForUpdate()->first(['id', 'effective_from']);
            $row = ['house_rent_bp' => $values['house_rent_bp'], 'medical_bp' => $values['medical_bp'], 'medical_cap_minor' => $values['medical_cap_minor'],
                'conveyance_minor' => $values['conveyance_minor'], 'basic_min_minor' => $values['basic_min_minor'] ?? 0, 'basic_max_minor' => $values['basic_max_minor'] ?? null,
                'verify' => $values['verify'] ?? true, 'updated_by' => $actorUserId, 'updated_at' => now()];
            if ($current !== null && (string) $current->effective_from >= $from->toDateString()) {
                DB::table('salary_structures')->where('id', $current->id)->update($row);
                $id = (string) $current->id;
            } else {
                if ($current !== null) {
                    DB::table('salary_structures')->where('id', $current->id)->update(['effective_to' => $from->toDateString()]);
                }
                $id = (string) Str::uuid7();
                DB::table('salary_structures')->insert($row + ['id' => $id, 'tenant_id' => TenantContext::id(), 'grade_id' => $gradeId, 'effective_from' => $from->toDateString(), 'created_at' => now()]);
            }
            $this->audit->record('salary_structure.saved', AuditSubject::of('grade', $gradeId), null, $values + ['effective_from' => $from->toDateString()], null, self::PERMISSION, Actor::user($actorUserId));

            return $id;
        });
    }

    /**
     * Replaces a tax year's slab table: bands in order, each a width in minor units (the last one open, null) and a rate.
     *
     * @param list<array{band_minor: int|null, rate_bp: int}> $bands
     *
     * @throws BusinessRuleViolation PAYROLL_TAX_SLABS_INVALID
     */
    public function saveSlabs(string $taxYear, string $category, array $bands, bool $verify, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION);
        if (preg_match('/^\d{4}-\d{2}$/', $taxYear) !== 1 || $bands === []) {
            throw new BusinessRuleViolation('PAYROLL_TAX_SLABS_INVALID', 'Enter the tax year (like 2026-27) and at least one band.');
        }
        foreach ($bands as $i => $band) {
            $last = $i === count($bands) - 1;
            if ($band['rate_bp'] < 0 || $band['rate_bp'] > 10_000 || ($band['band_minor'] === null) !== $last || ($band['band_minor'] !== null && $band['band_minor'] <= 0)) {
                throw new BusinessRuleViolation('PAYROLL_TAX_SLABS_INVALID', 'Every band but the last needs a width above zero; the last band is "the rest"; rates are 0% to 100%.');
            }
        }
        DB::transaction(function () use ($taxYear, $category, $bands, $verify, $actorUserId): void {
            DB::table('payroll_tax_slabs')->where('tax_year', $taxYear)->where('category', $category)->delete();
            $firstId = null;
            foreach ($bands as $i => $band) {
                $id = (string) Str::uuid7();
                $firstId ??= $id;
                DB::table('payroll_tax_slabs')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'tax_year' => $taxYear, 'category' => $category, 'seq' => $i + 1,
                    'band_minor' => $band['band_minor'], 'rate_bp' => $band['rate_bp'], 'verify' => $verify, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record('payroll_tax_slabs.saved', AuditSubject::of('payroll_tax_slabs', (string) $firstId), null,
                ['tax_year' => $taxYear, 'category' => $category, 'bands' => $bands, 'verify' => $verify], null, self::PERMISSION, Actor::user($actorUserId));
        });
    }
}
