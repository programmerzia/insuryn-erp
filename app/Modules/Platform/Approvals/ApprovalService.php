<?php

declare(strict_types=1);

namespace App\Modules\Platform\Approvals;

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Authorization\SodViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design §2.1 approval_policies / approvals / approval_decisions; spec §7 "Phase 1 = maker-checker +
 * amount-threshold approval". A policy applies to an object type, is effective-dated, and has a condition
 * and sequential steps, each naming the permission its approver must hold.
 *
 * A step may also name a role (fix F3, DECISION D-26): then the decider must hold that role, which is the authority to approve (design §7.2
 * "CFO: approve above thresholds", §7.3 "> 500,000 → Finance Manager + CFO"), and the step's permission is the approval duty used for the
 * segregation-of-duties check and the audit trail. Steps without a role behave as before.
 *
 * Every decision enforces: the step permission; the decider is not the requester (maker ≠ checker);
 * nobody decides two steps of one approval; and SodGuard on the approved object. The owning module's
 * ApprovalHandler completes the business action on final approval or rejection.
 *
 * Condition keys (all optional, all must hold): min_amount_minor (amount >= value), max_amount_minor
 * (amount < value), kinds (attributes.kind in list). When several policies match, the strictest wins:
 * most steps, then highest min_amount_minor.
 *
 * ASSUMPTION: A-2 — approval thresholds and who approves are unknown (design OPEN #3), so no policies are seeded;
 * callers fall back to one checker ≠ maker. Configure thresholds as approval_policies rows.
 */
final class ApprovalService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SodGuard $sod,
        private readonly Audit $audit,
        private readonly ApprovalHandlerRegistry $handlers,
    ) {}

    /**
     * Starts an approval when a policy matches; null when none does (the caller applies its own
     * maker-checker rule).
     *
     * @param array<string, mixed> $context kept with the approval and handed to the handler
     */
    public function request(string $objectType, string $objectId, ApprovalFacts $facts, string $requestedBy, CarbonImmutable $on, array $context = []): ?string
    {
        $policy = $this->matchingPolicy($objectType, $facts, $on);
        if ($policy === null) {
            return null;
        }
        $approvalId = (string) Str::uuid7();
        DB::table('approvals')->insert([
            'id' => $approvalId, 'tenant_id' => TenantContext::id(), 'object_type' => $objectType, 'object_id' => $objectId,
            'policy_id' => $policy['id'], 'status' => ApprovalStatus::Pending->value, 'current_step' => 1,
            'requested_by' => $requestedBy, 'requested_at' => CarbonImmutable::now(), 'context' => json_encode($context, JSON_THROW_ON_ERROR),
        ]);

        return $approvalId;
    }

    /**
     * DECISION D-31 (slice R5): starts an approval whose steps the requesting module works out itself (an underwriting referral goes to the role whose
     * underwriting limit covers the sum insured) instead of an approval policy. The steps are kept on the approval; deciding follows exactly the same rules.
     *
     * @param list<array{permission: string, role: string|null}> $steps
     * @param array<string, mixed> $context
     *
     * @throws ApprovalException INVALID_POLICY when there is no step or a step has no permission
     */
    public function requestWithSteps(string $objectType, string $objectId, array $steps, string $requestedBy, array $context = []): string
    {
        $json = json_encode($steps, JSON_THROW_ON_ERROR);
        $this->parseSteps($json, "for {$objectType} {$objectId}");
        $approvalId = (string) Str::uuid7();
        DB::table('approvals')->insert([
            'id' => $approvalId, 'tenant_id' => TenantContext::id(), 'object_type' => $objectType, 'object_id' => $objectId, 'policy_id' => null, 'steps' => $json,
            'status' => ApprovalStatus::Pending->value, 'current_step' => 1, 'requested_by' => $requestedBy, 'requested_at' => CarbonImmutable::now(),
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
        ]);

        return $approvalId;
    }

    /**
     * The steps an approval of these facts would need, or null when no policy matches (the amount is within the requester's limit). Read-only:
     * lets a screen offer a next step or say who approves without starting an approval.
     *
     * @return list<array{permission: string, role: string|null}>|null
     */
    public function stepsRequiredFor(string $objectType, ApprovalFacts $facts, CarbonImmutable $on): ?array
    {
        return $this->matchingPolicy($objectType, $facts, $on)['steps'] ?? null;
    }

    public function pendingFor(string $objectType, string $objectId): ?string
    {
        $id = DB::table('approvals')->where('object_type', $objectType)->where('object_id', $objectId)
            ->where('status', ApprovalStatus::Pending->value)->value('id');

        return is_string($id) ? $id : null;
    }

    /**
     * @throws ApprovalException NOT_PENDING, APPROVER_ALREADY_DECIDED, REASON_REQUIRED
     * @throws \App\Modules\Platform\Authorization\PermissionDenied
     * @throws SodViolation
     */
    public function decide(string $approvalId, string $deciderId, Decision $decision, ?string $reason): ApprovalStatus
    {
        return DB::transaction(function () use ($approvalId, $deciderId, $decision, $reason): ApprovalStatus {
            /** @var object{id: string, object_type: string, object_id: string, policy_id: string|null, steps: string|null, status: string, current_step: int, requested_by: string, context: string|null}|null $approval */
            $approval = DB::table('approvals')->where('id', $approvalId)->lockForUpdate()->first();
            if ($approval === null || $approval->status !== ApprovalStatus::Pending->value) {
                throw new ApprovalException('NOT_PENDING', "Approval {$approvalId} is not pending.");
            }
            $subject = AuditSubject::of((string) $approval->object_type, (string) $approval->object_id);
            $steps = $approval->steps !== null ? $this->parseSteps((string) $approval->steps, "on approval {$approvalId}") : $this->steps((string) $approval->policy_id);
            $stepNo = (int) $approval->current_step;
            $step = $steps[$stepNo - 1] ?? throw new ApprovalException('INVALID_POLICY', "Approval {$approvalId} has no step {$stepNo}.");
            $permission = $step['permission'];

            $this->assertMayDecide($approvalId, (string) $approval->requested_by, $deciderId, $step, $subject);
            if ($decision === Decision::Rejected && trim((string) $reason) === '') {
                throw new ApprovalException('REASON_REQUIRED', 'Rejecting requires a reason.');
            }

            DB::table('approval_decisions')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'approval_id' => $approvalId, 'step_no' => $stepNo,
                'decided_by' => $deciderId, 'decision' => $decision->value, 'reason' => $reason, 'decided_at' => CarbonImmutable::now(),
            ]);
            $this->audit->record('approval.decided', $subject, null, ['approval_id' => $approvalId, 'step' => $stepNo, 'decision' => $decision->value],
                $reason, $permission, Actor::user($deciderId));

            return $this->advance($approval, $stepNo, count($steps), $deciderId, $decision, (string) $reason);
        });
    }

    /**
     * Slice 2.1b: whether the user could decide the pending approval's current step now (the same checks as decide, nothing written). Used where
     * "its approver" acts on a pending object without deciding it, such as moving a pending manual journal to the next period.
     *
     * @throws ApprovalException NOT_PENDING, APPROVER_ALREADY_DECIDED
     * @throws \App\Modules\Platform\Authorization\PermissionDenied
     * @throws SodViolation
     */
    public function assertMayDecideCurrentStep(string $approvalId, string $deciderId): void
    {
        /** @var object{object_type: string, object_id: string, policy_id: string|null, steps: string|null, status: string, current_step: int, requested_by: string}|null $approval */
        $approval = DB::table('approvals')->where('id', $approvalId)->first(['object_type', 'object_id', 'policy_id', 'steps', 'status', 'current_step', 'requested_by']);
        if ($approval === null || $approval->status !== ApprovalStatus::Pending->value) {
            throw new ApprovalException('NOT_PENDING', "Approval {$approvalId} is not pending.");
        }
        $steps = $approval->steps !== null ? $this->parseSteps((string) $approval->steps, "on approval {$approvalId}") : $this->steps((string) $approval->policy_id);
        $step = $steps[(int) $approval->current_step - 1] ?? throw new ApprovalException('INVALID_POLICY', "Approval {$approvalId} has no step {$approval->current_step}.");
        $this->assertMayDecide($approvalId, (string) $approval->requested_by, $deciderId, $step, AuditSubject::of((string) $approval->object_type, (string) $approval->object_id));
    }

    /**
     * Gap fixes W7 (GA-04 remainder): the pending approval of an object as its own page shows it — its id, the current step of how many, and whether the user
     * may decide that step now (the same checks as decide, nothing written). Null when nothing is pending. The decision itself still goes through decide().
     *
     * @return array{id: string, step: int, steps_total: int, may_decide: bool}|null
     */
    public function pendingOutlook(string $objectType, string $objectId, string $userId): ?array
    {
        /** @var object{id: string, policy_id: string|null, steps: string|null, current_step: int}|null $approval */
        $approval = DB::table('approvals')->where('object_type', $objectType)->where('object_id', $objectId)->where('status', ApprovalStatus::Pending->value)
            ->first(['id', 'policy_id', 'steps', 'current_step']);
        if ($approval === null) {
            return null;
        }
        try {
            $steps = $approval->steps !== null ? $this->parseSteps((string) $approval->steps, "on approval {$approval->id}") : $this->steps((string) $approval->policy_id);
        } catch (ApprovalException) {
            $steps = [];
        }
        try {
            $this->assertMayDecideCurrentStep((string) $approval->id, $userId);
            $mayDecide = true;
        } catch (ApprovalException|PermissionDenied|SodViolation) {
            $mayDecide = false;
        }

        return ['id' => (string) $approval->id, 'step' => (int) $approval->current_step, 'steps_total' => max(count($steps), (int) $approval->current_step), 'may_decide' => $mayDecide];
    }

    /** @param array{permission: string, role: string|null} $step */
    private function assertMayDecide(string $approvalId, string $requestedBy, string $deciderId, array $step, AuditSubject $subject): void
    {
        $permission = $step['permission'];
        if ($step['role'] === null) {
            $this->permissions->authorize($deciderId, $permission);
        } elseif (! self::holdsRole($deciderId, $step['role'])) {
            throw new PermissionDenied($deciderId, $permission, "User {$deciderId} does not hold role {$step['role']}, which decides this approval step.");
        }
        if ($deciderId === $requestedBy) {
            throw new SodViolation('SOD_CONFLICT', $deciderId, $permission, 'request', 'MAKER_CHECKER',
                "The requester of {$subject->type} {$subject->id} cannot approve it.");
        }
        if (DB::table('approval_decisions')->where('approval_id', $approvalId)->where('decided_by', $deciderId)->exists()) {
            throw new ApprovalException('APPROVER_ALREADY_DECIDED', "User {$deciderId} already decided a step of approval {$approvalId}.");
        }
        $this->sod->assert($deciderId, $permission, $subject);
    }

    /** @param object{id: string, object_type: string, object_id: string, requested_by: string, context: string|null} $approval */
    private function advance(object $approval, int $stepNo, int $stepCount, string $deciderId, Decision $decision, string $reason): ApprovalStatus
    {
        /** @var array<string, mixed> $context */
        $context = json_decode((string) ($approval->context ?? '{}'), true, 512, JSON_THROW_ON_ERROR) + ['requested_by' => (string) $approval->requested_by];
        $handler = fn (): ApprovalHandler => $this->handlers->for((string) $approval->object_type);

        if ($decision === Decision::Rejected) {
            $this->close((string) $approval->id, ApprovalStatus::Rejected);
            $handler()->rejected((string) $approval->object_id, $deciderId, $reason, $context);

            return ApprovalStatus::Rejected;
        }
        if ($stepNo < $stepCount) {
            DB::table('approvals')->where('id', $approval->id)->update(['current_step' => $stepNo + 1]);

            return ApprovalStatus::Pending;
        }
        $this->close((string) $approval->id, ApprovalStatus::Approved);
        $handler()->approved((string) $approval->object_id, $deciderId, $context);

        return ApprovalStatus::Approved;
    }

    private function close(string $approvalId, ApprovalStatus $status): void
    {
        DB::table('approvals')->where('id', $approvalId)->update(['status' => $status->value, 'decided_at' => CarbonImmutable::now()]);
    }

    /** A role the user holds in any scope (an approval step names who decides, not where). */
    public static function holdsRole(string $userId, string $roleCode): bool
    {
        return DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $userId)->where('r.code', $roleCode)->exists();
    }

    /** @return array{id: string, steps: list<array{permission: string, role: string|null}>, min_amount: int}|null */
    private function matchingPolicy(string $objectType, ApprovalFacts $facts, CarbonImmutable $on): ?array
    {
        $candidates = [];
        $policies = DB::table('approval_policies')->where('object_type', $objectType)
            ->where('effective_from', '<=', $on->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $on->toDateString()))
            ->orderBy('id')->get(['id', 'condition', 'steps']);
        foreach ($policies as $policy) {
            /** @var array<string, mixed> $condition */
            $condition = json_decode((string) $policy->condition, true, 512, JSON_THROW_ON_ERROR);
            if ($this->conditionHolds($condition, $facts)) {
                $candidates[] = ['id' => (string) $policy->id, 'steps' => $this->parseSteps((string) $policy->steps, (string) $policy->id),
                    'min_amount' => is_int($condition['min_amount_minor'] ?? null) ? $condition['min_amount_minor'] : 0];
            }
        }
        usort($candidates, fn (array $a, array $b): int => [count($b['steps']), $b['min_amount']] <=> [count($a['steps']), $a['min_amount']]);

        return $candidates[0] ?? null;
    }

    /** @param array<string, mixed> $condition */
    private function conditionHolds(array $condition, ApprovalFacts $facts): bool
    {
        $min = $condition['min_amount_minor'] ?? null;
        $max = $condition['max_amount_minor'] ?? null;
        $kinds = $condition['kinds'] ?? null;

        return (! is_int($min) || $facts->amountMinor >= $min)
            && (! is_int($max) || $facts->amountMinor < $max)
            && (! is_array($kinds) || in_array($facts->attributes['kind'] ?? null, $kinds, true));
    }

    /** @return list<array{permission: string, role: string|null}> steps in order */
    private function steps(string $policyId): array
    {
        return $this->parseSteps((string) DB::table('approval_policies')->where('id', $policyId)->value('steps'), $policyId);
    }

    /** @return list<array{permission: string, role: string|null}> */
    private function parseSteps(string $json, string $policyId): array
    {
        $steps = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $parsed = is_array($steps) ? array_values(array_filter(array_map(
            fn (mixed $step): ?array => is_array($step) && is_string($step['permission'] ?? null)
                ? ['permission' => $step['permission'], 'role' => is_string($step['role'] ?? null) ? $step['role'] : null] : null, $steps))) : [];
        if ($parsed === [] || count($parsed) !== count((array) $steps)) {
            throw new ApprovalException('INVALID_POLICY', "Approval policy {$policyId} needs at least one step, each with a permission.");
        }

        return $parsed;
    }
}
