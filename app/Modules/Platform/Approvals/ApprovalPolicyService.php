<?php

declare(strict_types=1);

namespace App\Modules\Platform\Approvals;

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Money\MinorUnits;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Approval limits (fix F3, design §2.1 approval_policies, §7.3): administrators list, add, change and end the policies ApprovalService matches.
 * Policies are effective-dated and history is never rewritten: a policy already in force is ended the day its successor starts; only a policy that
 * has not started yet is edited in place. Two policies for the same object type may not cover the same amount on the same day, so the engine's
 * "strictest wins" tie-break is never needed for policies made here.
 *
 * A step is stored as {permission, role}: the permission is the object type's approval duty (config erp.approvals.object_types), used for the
 * segregation-of-duties check and the audit trail; the role is who decides (DECISION D-26).
 *
 * ASSUMPTION: A-54 — administered by platform.manage_approvals (Tenant Admin). ASSUMPTION: A-55 — the defaults below are placeholders (design §7.3 OPEN:
 * actual thresholds come from the customer).
 */
final class ApprovalPolicyService
{
    public const PERMISSION = 'platform.manage_approvals';

    /** ASSUMPTION: A-55 — verify with the customer. object type => list of [at least (minor), below (minor), roles in order]. */
    public const DEFAULTS = [
        // A claims manager approves a payment up to 500,000 on their own (no policy); from 500,000 the finance manager and then the CFO approve too.
        'claim_payment' => [[50_000_000, null, ['finance_manager', 'cfo']]],
        // The finance manager releases payments; from 500,000 the CFO approves the release.
        'claim_payment_release' => [[50_000_000, null, ['cfo']]],
        // Manual journals and journal reversals: a finance manager approves (the same checker as without a policy, now written down).
        'journal' => [[null, null, ['finance_manager']]],
        'journal_reversal' => [[null, null, ['finance_manager']]],
    ];

    private const MAX_STEPS = 5;

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /** @return array<string, array{label: string, permission: string}> object types the approval engine requests, with a plain name and their approval duty */
    public static function objectTypes(): array
    {
        /** @var array<string, array{label: string, permission: string}> */
        return (array) config('erp.approvals.object_types', []);
    }

    /**
     * Every policy of the tenant, newest first within each object type.
     *
     * @return list<array{id: string, object_type: string, label: string, min_amount_minor: int|null, max_amount_minor: int|null, amount: string,
     *     steps: list<array{role: string|null, role_name: string|null, permission: string}>, approvers: string, effective_from: string, effective_to: string|null,
     *     status: string, kinds: list<string>|null}>
     */
    public function policies(CarbonImmutable $today): array
    {
        $roleNames = DB::table('roles')->pluck('name', 'code')->map(fn (mixed $n): string => (string) $n)->all();
        $currency = $this->currency();
        $types = self::objectTypes();
        $rows = [];
        foreach (DB::table('approval_policies')->orderBy('object_type')->orderByDesc('effective_from')->orderBy('id')->get() as $policy) {
            $condition = self::decode((string) $policy->condition);
            $min = is_int($condition['min_amount_minor'] ?? null) ? $condition['min_amount_minor'] : null;
            $max = is_int($condition['max_amount_minor'] ?? null) ? $condition['max_amount_minor'] : null;
            $steps = array_map(fn (array $s): array => ['role' => $s['role'], 'role_name' => $s['role'] === null ? null : ($roleNames[$s['role']] ?? null), 'permission' => $s['permission']],
                self::steps((string) $policy->steps));
            $from = (string) $policy->effective_from;
            $to = $policy->effective_to === null ? null : (string) $policy->effective_to;
            $kinds = is_array($condition['kinds'] ?? null) ? array_values(array_map(fn (mixed $k): string => (string) $k, $condition['kinds'])) : null;
            $rows[] = ['id' => (string) $policy->id, 'object_type' => (string) $policy->object_type, 'label' => $types[(string) $policy->object_type]['label'] ?? (string) $policy->object_type,
                'min_amount_minor' => $min, 'max_amount_minor' => $max, 'amount' => self::band($min, $max, $currency), 'steps' => $steps,
                'approvers' => implode(' → ', array_map(fn (array $s): string => $s['role'] === null ? "anyone with {$s['permission']}" : ($s['role_name'] ?? "{$s['role']} (role removed)"), $steps)),
                'effective_from' => $from, 'effective_to' => $to, 'status' => self::status($from, $to, $today), 'kinds' => $kinds];
        }

        return $rows;
    }

    /** @throws BusinessRuleViolation APPROVAL_POLICY_INVALID | APPROVAL_POLICY_OVERLAP */
    public function create(ApprovalPolicyRequest $request, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION);

        return DB::transaction(function () use ($request, $actorUserId): string {
            $this->lockTenantPolicies();
            $this->assertValid($request, CarbonImmutable::today());
            $condition = self::condition($request, null);
            $this->assertNoOverlap($request, $condition, null);

            return $this->insert($request, $condition, $actorUserId, null);
        });
    }

    /**
     * Changes a policy from $request->effectiveFrom. A policy not yet in force is edited in place; one in force ends that day and a successor takes
     * over (returned id). Ended policies do not change.
     *
     * @throws BusinessRuleViolation APPROVAL_POLICY_INVALID | APPROVAL_POLICY_OVERLAP | APPROVAL_POLICY_ENDED
     */
    public function revise(string $policyId, ApprovalPolicyRequest $request, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION);

        return DB::transaction(function () use ($policyId, $request, $actorUserId): string {
            $this->lockTenantPolicies();
            $today = CarbonImmutable::today();
            $policy = $this->find($policyId);
            $from = CarbonImmutable::parse((string) $policy->effective_from);
            $to = $policy->effective_to === null ? null : CarbonImmutable::parse((string) $policy->effective_to);
            if ($to !== null && $to->lessThanOrEqualTo($today)) {
                throw new BusinessRuleViolation('APPROVAL_POLICY_ENDED', 'This policy has ended and stays as it was. Add a new policy instead.');
            }
            if ($request->objectType !== (string) $policy->object_type) {
                throw new BusinessRuleViolation('APPROVAL_POLICY_INVALID', 'A policy keeps what it approves. End it and add a new policy for something else.');
            }
            $this->assertValid($request, $today);
            $condition = self::condition($request, self::decode((string) $policy->condition));
            $before = self::snapshot($policy);

            if ($from->greaterThan($today)) {
                $this->assertNoOverlap($request, $condition, $policyId);
                DB::table('approval_policies')->where('id', $policyId)->update(['condition' => self::json($condition), 'steps' => self::json($this->stepsFor($request)),
                    'effective_from' => $request->effectiveFrom->toDateString(), 'effective_to' => $request->effectiveTo?->toDateString()]);
                $this->audit->record('approval_policy.updated', AuditSubject::of('approval_policy', $policyId), $before, self::snapshot($this->find($policyId)),
                    null, self::PERMISSION, Actor::user($actorUserId));

                return $policyId;
            }

            if ($request->effectiveFrom->lessThanOrEqualTo($from)) {
                throw new BusinessRuleViolation('APPROVAL_POLICY_INVALID', 'The change must start after the policy started ('.$from->format('j M Y').'), from today or later.');
            }
            if ($to !== null && $request->effectiveFrom->greaterThanOrEqualTo($to)) {
                throw new BusinessRuleViolation('APPROVAL_POLICY_INVALID', 'The change must start before the policy ends ('.$to->format('j M Y').').');
            }
            $this->assertNoOverlap($request, $condition, $policyId);
            DB::table('approval_policies')->where('id', $policyId)->update(['effective_to' => $request->effectiveFrom->toDateString()]);
            $this->audit->record('approval_policy.ended', AuditSubject::of('approval_policy', $policyId), $before, self::snapshot($this->find($policyId)),
                null, self::PERMISSION, Actor::user($actorUserId));

            return $this->insert($request, $condition, $actorUserId, $policyId);
        });
    }

    /** Ends a policy: it no longer applies from $effectiveTo (today or later). @throws BusinessRuleViolation APPROVAL_POLICY_INVALID | APPROVAL_POLICY_ENDED */
    public function end(string $policyId, CarbonImmutable $effectiveTo, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION);

        DB::transaction(function () use ($policyId, $effectiveTo, $actorUserId): void {
            $this->lockTenantPolicies();
            $policy = $this->find($policyId);
            $to = $policy->effective_to === null ? null : CarbonImmutable::parse((string) $policy->effective_to);
            if ($to !== null && $to->lessThanOrEqualTo(CarbonImmutable::today())) {
                throw new BusinessRuleViolation('APPROVAL_POLICY_ENDED', 'This policy has already ended.');
            }
            if ($effectiveTo->lessThan(CarbonImmutable::today()) || $effectiveTo->lessThanOrEqualTo(CarbonImmutable::parse((string) $policy->effective_from))
                || ($to !== null && $effectiveTo->greaterThan($to))) {
                throw new BusinessRuleViolation('APPROVAL_POLICY_INVALID', 'Choose an end date from today, after the policy starts'.($to === null ? '' : ' and no later than it already ends').'.');
            }
            $before = self::snapshot($policy);
            DB::table('approval_policies')->where('id', $policyId)->update(['effective_to' => $effectiveTo->toDateString()]);
            $this->audit->record('approval_policy.ended', AuditSubject::of('approval_policy', $policyId), $before, self::snapshot($this->find($policyId)),
                null, self::PERMISSION, Actor::user($actorUserId));
        });
    }

    /**
     * The default limits (A-55) from $from, skipping any that would overlap a policy the tenant already has or name a role it does not have.
     *
     * @return int policies created
     */
    public function acceptDefaults(CarbonImmutable $from, string $actorUserId): int
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION);
        $created = 0;
        foreach ($this->defaults($from) as $request) {
            try {
                $this->create($request, $actorUserId);
                $created++;
            } catch (BusinessRuleViolation $skipped) {
                if (! in_array($skipped->reasonCode, ['APPROVAL_POLICY_OVERLAP', 'APPROVAL_POLICY_INVALID'], true)) {
                    throw $skipped;
                }
            }
        }

        return $created;
    }

    /** @return list<ApprovalPolicyRequest> */
    public function defaults(CarbonImmutable $from): array
    {
        $requests = [];
        foreach (self::DEFAULTS as $objectType => $bands) {
            foreach ($bands as [$min, $max, $roles]) {
                $requests[] = new ApprovalPolicyRequest($objectType, $min, $max, $roles, $from);
            }
        }

        return $requests;
    }

    /** Human description of an amount band: "Any amount", "500,000.00 and above", "Below 500,000.00", "100.00 up to 500,000.00". */
    public static function band(?int $min, ?int $max, string $currency): string
    {
        $min = $min === 0 ? null : $min;

        return match (true) {
            $min === null && $max === null => 'Any amount',
            $max === null => MinorUnits::format((int) $min, $currency).' and above',
            $min === null => 'Below '.MinorUnits::format($max, $currency),
            default => MinorUnits::format($min, $currency).' up to, not including, '.MinorUnits::format($max, $currency),
        };
    }

    public function currency(): string
    {
        return (string) (DB::table('tenants')->where('id', TenantContext::id())->value('base_currency') ?? 'BDT');
    }

    private function assertValid(ApprovalPolicyRequest $request, CarbonImmutable $today): void
    {
        $types = self::objectTypes();
        if (! isset($types[$request->objectType])) {
            throw new BusinessRuleViolation('APPROVAL_POLICY_INVALID', 'Choose what the policy approves from the list.');
        }
        if (($request->minAmountMinor !== null && $request->minAmountMinor < 0) || ($request->maxAmountMinor !== null && $request->maxAmountMinor <= 0)
            || ($request->minAmountMinor !== null && $request->maxAmountMinor !== null && $request->maxAmountMinor <= $request->minAmountMinor)) {
            throw new BusinessRuleViolation('APPROVAL_POLICY_INVALID', 'The upper amount must be above the lower amount.');
        }
        if ($request->roles === [] || count($request->roles) > self::MAX_STEPS) {
            throw new BusinessRuleViolation('APPROVAL_POLICY_INVALID', 'A policy needs between one and '.self::MAX_STEPS.' approval steps.');
        }
        $known = DB::table('roles')->whereIn('code', $request->roles)->pluck('code')->map(fn (mixed $c): string => (string) $c)->all();
        $unknown = array_values(array_diff($request->roles, $known));
        if ($unknown !== []) {
            throw new BusinessRuleViolation('APPROVAL_POLICY_INVALID', 'Each step needs a role of this organisation; '.implode(', ', $unknown).' is not one.');
        }
        if ($request->effectiveFrom->lessThan($today)) {
            throw new BusinessRuleViolation('APPROVAL_POLICY_INVALID', 'A policy starts today or later; approvals already made keep the rules they were made under.');
        }
        if ($request->effectiveTo !== null && $request->effectiveTo->lessThanOrEqualTo($request->effectiveFrom)) {
            throw new BusinessRuleViolation('APPROVAL_POLICY_INVALID', 'A policy must end after it starts.');
        }
    }

    /**
     * @param array<string, mixed> $condition
     *
     * @throws BusinessRuleViolation APPROVAL_POLICY_OVERLAP
     */
    private function assertNoOverlap(ApprovalPolicyRequest $request, array $condition, ?string $exceptId): void
    {
        $from = $request->effectiveFrom->toDateString();
        $to = $request->effectiveTo?->toDateString();
        $candidates = DB::table('approval_policies')->where('object_type', $request->objectType)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '<>', $exceptId))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $from))
            ->when($to !== null, fn ($q) => $q->where('effective_from', '<', $to))
            ->get(['condition', 'effective_from', 'effective_to']);
        $min = $request->minAmountMinor ?? 0;
        $max = $request->maxAmountMinor ?? PHP_INT_MAX;
        $kinds = is_array($condition['kinds'] ?? null) ? $condition['kinds'] : null;
        foreach ($candidates as $other) {
            $otherCondition = self::decode((string) $other->condition);
            $otherMin = is_int($otherCondition['min_amount_minor'] ?? null) ? $otherCondition['min_amount_minor'] : 0;
            $otherMax = is_int($otherCondition['max_amount_minor'] ?? null) ? $otherCondition['max_amount_minor'] : PHP_INT_MAX;
            $otherKinds = is_array($otherCondition['kinds'] ?? null) ? $otherCondition['kinds'] : null;
            $kindsMeet = $kinds === null || $otherKinds === null || array_intersect($kinds, $otherKinds) !== [];
            if ($min < $otherMax && $otherMin < $max && $kindsMeet) {
                $currency = $this->currency();
                throw new BusinessRuleViolation('APPROVAL_POLICY_OVERLAP', sprintf('Another %s policy already covers %s from %s%s. Change or end that policy first, or choose other amounts or dates.',
                    strtolower(self::objectTypes()[$request->objectType]['label']), strtolower(self::band($otherMin === 0 ? null : $otherMin, $otherMax === PHP_INT_MAX ? null : $otherMax, $currency)),
                    CarbonImmutable::parse((string) $other->effective_from)->format('j M Y'),
                    $other->effective_to === null ? '' : ' to '.CarbonImmutable::parse((string) $other->effective_to)->format('j M Y')));
            }
        }
    }

    /** @param array<string, mixed> $condition */
    private function insert(ApprovalPolicyRequest $request, array $condition, string $actorUserId, ?string $replaces): string
    {
        $id = (string) Str::uuid7();
        DB::table('approval_policies')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'object_type' => $request->objectType, 'condition' => self::json($condition),
            'steps' => self::json($this->stepsFor($request)), 'effective_from' => $request->effectiveFrom->toDateString(), 'effective_to' => $request->effectiveTo?->toDateString()]);
        $this->audit->record('approval_policy.created', AuditSubject::of('approval_policy', $id), null, self::snapshot($this->find($id)) + ($replaces === null ? [] : ['replaces' => $replaces]),
            null, self::PERMISSION, Actor::user($actorUserId));

        return $id;
    }

    /** @return list<array{permission: string, role: string}> */
    private function stepsFor(ApprovalPolicyRequest $request): array
    {
        $permission = self::objectTypes()[$request->objectType]['permission'];

        return array_map(fn (string $role): array => ['permission' => $permission, 'role' => $role], $request->roles);
    }

    /**
     * The stored condition: the amount band, keeping any other condition (such as journal kinds) of the policy being changed.
     *
     * @param array<string, mixed>|null $previous
     * @return array<string, mixed>
     */
    private static function condition(ApprovalPolicyRequest $request, ?array $previous): array
    {
        $condition = $previous ?? [];
        unset($condition['min_amount_minor'], $condition['max_amount_minor']);
        if ($request->minAmountMinor !== null && $request->minAmountMinor > 0) {
            $condition['min_amount_minor'] = $request->minAmountMinor;
        }
        if ($request->maxAmountMinor !== null) {
            $condition['max_amount_minor'] = $request->maxAmountMinor;
        }

        return $condition;
    }

    /** @return object{id: string, object_type: string, condition: string, steps: string, effective_from: string, effective_to: string|null} */
    private function find(string $policyId): object
    {
        /** @var object{id: string, object_type: string, condition: string, steps: string, effective_from: string, effective_to: string|null}|null $policy */
        $policy = DB::table('approval_policies')->where('id', $policyId)->first(['id', 'object_type', 'condition', 'steps', 'effective_from', 'effective_to']);

        return $policy ?? throw new RecordsNotFoundException("Approval policy {$policyId} does not exist.");
    }

    /** Serialises changes to the tenant's policies, so two administrators cannot add overlapping policies at the same moment. */
    private function lockTenantPolicies(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['approval_policies:'.TenantContext::id()]);
    }

    /**
     * @param object{object_type: string, condition: string, steps: string, effective_from: string, effective_to: string|null} $policy
     * @return array<string, mixed>
     */
    private static function snapshot(object $policy): array
    {
        return ['object_type' => (string) $policy->object_type, 'condition' => self::decode((string) $policy->condition), 'steps' => self::decode((string) $policy->steps),
            'effective_from' => (string) $policy->effective_from, 'effective_to' => $policy->effective_to === null ? null : (string) $policy->effective_to];
    }

    /** @return list<array{permission: string, role: string|null}> */
    private static function steps(string $json): array
    {
        $steps = [];
        foreach (self::decode($json) as $step) {
            if (is_array($step)) {
                $steps[] = ['permission' => (string) ($step['permission'] ?? ''), 'role' => is_string($step['role'] ?? null) ? $step['role'] : null];
            }
        }

        return $steps;
    }

    /** @return array<array-key, mixed> */
    private static function decode(string $json): array
    {
        $value = json_decode($json, true);

        return is_array($value) ? $value : [];
    }

    /** @param array<array-key, mixed> $value */
    private static function json(array $value): string
    {
        return $value === [] ? '{}' : json_encode($value, JSON_THROW_ON_ERROR);
    }

    private static function status(string $from, ?string $to, CarbonImmutable $today): string
    {
        $day = $today->toDateString();

        return match (true) {
            $to !== null && $to <= $day => 'ended',
            $from > $day => 'scheduled',
            default => 'in_force',
        };
    }
}
