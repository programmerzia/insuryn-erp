<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Underwriting\Application;

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 3 design §5 "Authority limits: per user/role by class and sum insured (underwriting_limits); above limit → referral" (slice R5).
 *
 * A limit is the largest sum insured (minor units) holders of a role may accept for a product class, effective-dated. A user's limit for a class is the
 * highest limit of the roles they hold; a user whose roles have none has no authority (every proposal they submit is referred, A-85).
 * Changing a limit ends the one in force the day the change starts; history is never rewritten; limits of one role and class never overlap (constraint).
 *
 * ASSUMPTION: A-87 — limits are set by underwriting.manage_limits (Tenant Admin template), like approval limits (A-54).
 * ASSUMPTION: A-90 — the default limits below are placeholders to verify with the customer; seeded only in the demo tenants (flag `verify`).
 */
final class UnderwritingLimits
{
    public const PERMISSION = 'underwriting.manage_limits';

    /** ASSUMPTION: A-90 — placeholder limits, verify. role => class => largest sum insured in minor units. */
    public const DEFAULTS = [
        'branch_officer' => ['motor' => 200_000_000, 'fire' => 500_000_000, 'marine_cargo' => 200_000_000, 'misc' => 100_000_000],
        'branch_manager' => ['motor' => 1_000_000_000, 'fire' => 2_500_000_000, 'marine_cargo' => 1_000_000_000, 'misc' => 500_000_000],
        'finance_manager' => ['motor' => 5_000_000_000, 'fire' => 5_000_000_000, 'marine_cargo' => 5_000_000_000, 'misc' => 5_000_000_000],
        'cfo' => ['motor' => 25_000_000_000, 'fire' => 25_000_000_000, 'marine_cargo' => 25_000_000_000, 'misc' => 25_000_000_000],
    ];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /** The highest limit for the class among the roles the user holds (any scope) on the day; null when none of them has one. */
    public function limitFor(string $userId, string $classCode, CarbonImmutable $on): ?int
    {
        $limit = $this->inForce($classCode, $on)->join('roles as r', 'r.code', '=', 'l.role_code')->join('user_roles as ur', 'ur.role_id', '=', 'r.id')
            ->where('ur.user_id', $userId)->max('l.max_sum_insured_minor');

        return $limit === null ? null : (int) $limit;
    }

    /**
     * Roles whose limit for the class covers the sum insured on the day, smallest limit first (the lowest authority that may accept it); with $holding, only roles
     * that hold that permission (a referral step goes to a role that decides referrals).
     *
     * @return list<string>
     */
    public function rolesCovering(string $classCode, int $sumInsuredMinor, CarbonImmutable $on, ?string $holding = null): array
    {
        return array_values($this->inForce($classCode, $on)->join('roles as r', 'r.code', '=', 'l.role_code')->where('l.max_sum_insured_minor', '>=', $sumInsuredMinor)
            ->when($holding !== null, fn ($q) => $q->whereExists(fn ($e) => $e->from('role_permissions as rp')->whereColumn('rp.role_id', 'r.id')->where('rp.permission_code', $holding)))
            ->orderBy('l.max_sum_insured_minor')->orderBy('l.role_code')->pluck('l.role_code')->map(fn (mixed $c): string => (string) $c)->all());
    }

    /**
     * Every limit, newest first per role and class, with its status on $today.
     *
     * @return list<array{id: string, role_code: string, role_name: string|null, class_code: string, class_name: string|null, max_sum_insured_minor: int, effective_from: string, effective_to: string|null, status: string, verify: bool}>
     */
    public function all(CarbonImmutable $today): array
    {
        $day = $today->toDateString();

        return array_values(DB::table('underwriting_limits as l')->leftJoin('roles as r', 'r.code', '=', 'l.role_code')->leftJoin('product_classes as c', 'c.code', '=', 'l.class_code')
            ->orderBy('l.class_code')->orderBy('l.max_sum_insured_minor')->orderByDesc('l.effective_from')
            ->get(['l.id', 'l.role_code', 'r.name as role_name', 'l.class_code', 'c.name_en as class_name', 'l.max_sum_insured_minor', 'l.effective_from', 'l.effective_to', 'l.verify'])
            ->map(fn (object $l): array => ['id' => (string) $l->id, 'role_code' => (string) $l->role_code, 'role_name' => $l->role_name === null ? null : (string) $l->role_name,
                'class_code' => (string) $l->class_code, 'class_name' => $l->class_name === null ? null : (string) $l->class_name, 'max_sum_insured_minor' => (int) $l->max_sum_insured_minor,
                'effective_from' => (string) $l->effective_from, 'effective_to' => $l->effective_to === null ? null : (string) $l->effective_to,
                'status' => match (true) { (string) $l->effective_from > $day => 'scheduled', $l->effective_to !== null && (string) $l->effective_to <= $day => 'ended', default => 'in_force' },
                'verify' => (bool) $l->verify])->all());
    }

    /**
     * Sets the limit of a role for a class from a day (today or later). The limit in force then ends that day. Returns the new limit's id.
     *
     * @throws BusinessRuleViolation UNDERWRITING_LIMIT_INVALID, UNDERWRITING_LIMIT_OVERLAP
     */
    public function set(string $roleCode, string $classCode, int $maxSumInsuredMinor, CarbonImmutable $from, string $actorUserId, bool $verify = false): string
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION);
        if ($maxSumInsuredMinor < 0) {
            throw new BusinessRuleViolation('UNDERWRITING_LIMIT_INVALID', 'A limit cannot be below zero.');
        }
        if ($from->lessThan(CarbonImmutable::today())) {
            throw new BusinessRuleViolation('UNDERWRITING_LIMIT_INVALID', 'A limit starts today or later; proposals already decided keep the limits they were decided under.');
        }
        if (! DB::table('roles')->where('code', $roleCode)->exists()) {
            throw new BusinessRuleViolation('UNDERWRITING_LIMIT_INVALID', "Choose a role of this organisation; {$roleCode} is not one.");
        }
        if (! DB::table('product_classes')->where('code', $classCode)->where('status', 'active')->exists()) {
            throw new BusinessRuleViolation('UNDERWRITING_LIMIT_INVALID', "Choose an active product class; {$classCode} is not one.");
        }

        return DB::transaction(function () use ($roleCode, $classCode, $maxSumInsuredMinor, $from, $actorUserId, $verify): string {
            DB::table('underwriting_limits')->where('role_code', $roleCode)->where('class_code', $classCode)->lockForUpdate()->get(['id']);
            if (DB::table('underwriting_limits')->where('role_code', $roleCode)->where('class_code', $classCode)->where('effective_from', '>=', $from->toDateString())->exists()) {
                throw new BusinessRuleViolation('UNDERWRITING_LIMIT_OVERLAP', 'This role already has a limit for the class starting on or after that day. End it first.');
            }
            $current = DB::table('underwriting_limits')->where('role_code', $roleCode)->where('class_code', $classCode)->where('effective_from', '<', $from->toDateString())
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $from->toDateString()))->first(['id', 'max_sum_insured_minor', 'effective_to']);
            if ($current !== null) {
                DB::table('underwriting_limits')->where('id', $current->id)->update(['effective_to' => $from->toDateString(), 'updated_at' => now()]);
                $this->audit->record('underwriting_limit.ended', AuditSubject::of('underwriting_limit', (string) $current->id), ['effective_to' => $current->effective_to],
                    ['effective_to' => $from->toDateString()], null, self::PERMISSION, Actor::user($actorUserId));
            }
            $id = (string) Str::uuid7();
            DB::table('underwriting_limits')->insert(['id' => $id, 'tenant_id' => \App\Modules\Platform\Tenancy\TenantContext::id(), 'role_code' => $roleCode, 'class_code' => $classCode,
                'max_sum_insured_minor' => $maxSumInsuredMinor, 'effective_from' => $from->toDateString(), 'effective_to' => $current?->effective_to, 'verify' => $verify,
                'created_by' => $actorUserId, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('underwriting_limit.set', AuditSubject::of('underwriting_limit', $id), $current === null ? null : ['max_sum_insured_minor' => (int) $current->max_sum_insured_minor],
                ['role' => $roleCode, 'class' => $classCode, 'max_sum_insured_minor' => $maxSumInsuredMinor, 'effective_from' => $from->toDateString(), 'replaces' => $current?->id],
                null, self::PERMISSION, Actor::user($actorUserId));

            return $id;
        });
    }

    /** @throws BusinessRuleViolation UNDERWRITING_LIMIT_INVALID */
    public function end(string $limitId, CarbonImmutable $effectiveTo, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION);
        DB::transaction(function () use ($limitId, $effectiveTo, $actorUserId): void {
            $limit = DB::table('underwriting_limits')->where('id', $limitId)->lockForUpdate()->first(['id', 'effective_from', 'effective_to'])
                ?? throw new BusinessRuleViolation('UNDERWRITING_LIMIT_INVALID', 'That limit does not exist.');
            $to = $limit->effective_to === null ? null : (string) $limit->effective_to;
            if ($effectiveTo->lessThan(CarbonImmutable::today()) || $effectiveTo->toDateString() <= (string) $limit->effective_from || ($to !== null && $effectiveTo->toDateString() > $to)) {
                throw new BusinessRuleViolation('UNDERWRITING_LIMIT_INVALID', 'Choose an end date from today, after the limit starts and no later than it already ends.');
            }
            DB::table('underwriting_limits')->where('id', $limitId)->update(['effective_to' => $effectiveTo->toDateString(), 'updated_at' => now()]);
            $this->audit->record('underwriting_limit.ended', AuditSubject::of('underwriting_limit', $limitId), ['effective_to' => $to], ['effective_to' => $effectiveTo->toDateString()],
                null, self::PERMISSION, Actor::user($actorUserId));
        });
    }

    /**
     * The placeholder defaults (A-90) from $from for template roles the tenant has, marked `verify`; a role and class that already has a limit is skipped.
     *
     * @return int limits created
     */
    public function acceptDefaults(CarbonImmutable $from, string $actorUserId): int
    {
        $created = 0;
        foreach (self::DEFAULTS as $role => $classes) {
            if (! DB::table('roles')->where('code', $role)->exists()) {
                continue;
            }
            foreach ($classes as $class => $max) {
                if (DB::table('underwriting_limits')->where('role_code', $role)->where('class_code', $class)->exists()) {
                    continue;
                }
                $this->set($role, $class, $max, $from, $actorUserId, verify: true);
                $created++;
            }
        }

        return $created;
    }

    private function inForce(string $classCode, CarbonImmutable $on): \Illuminate\Database\Query\Builder
    {
        $day = $on->toDateString();

        return DB::table('underwriting_limits as l')->where('l.class_code', $classCode)->where('l.effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('l.effective_to')->orWhere('l.effective_to', '>', $day));
    }
}
