<?php

declare(strict_types=1);

namespace App\Modules\Platform\Approvals;

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
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
 * Every decision enforces: the step permission; the decider is not the requester (maker ≠ checker);
 * nobody decides two steps of one approval; and SodGuard on the approved object. The owning module's
 * ApprovalHandler completes the business action on final approval or rejection.
 *
 * Condition keys (all optional, all must hold): min_amount_minor (amount >= value), max_amount_minor
 * (amount < value), kinds (attributes.kind in list). When several policies match, the strictest wins:
 * most steps, then highest min_amount_minor.
 *
 * ASSUMPTION: approval thresholds and who approves are unknown (design OPEN #3), so no policies are seeded;
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
            /** @var object{id: string, object_type: string, object_id: string, policy_id: string, status: string, current_step: int, requested_by: string, context: string|null}|null $approval */
            $approval = DB::table('approvals')->where('id', $approvalId)->lockForUpdate()->first();
            if ($approval === null || $approval->status !== ApprovalStatus::Pending->value) {
                throw new ApprovalException('NOT_PENDING', "Approval {$approvalId} is not pending.");
            }
            $subject = AuditSubject::of((string) $approval->object_type, (string) $approval->object_id);
            $steps = $this->steps((string) $approval->policy_id);
            $stepNo = (int) $approval->current_step;
            $permission = $steps[$stepNo - 1] ?? throw new ApprovalException('INVALID_POLICY', "Approval {$approvalId} has no step {$stepNo}.");

            $this->assertMayDecide($approvalId, (string) $approval->requested_by, $deciderId, $permission, $subject);
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

    private function assertMayDecide(string $approvalId, string $requestedBy, string $deciderId, string $permission, AuditSubject $subject): void
    {
        $this->permissions->authorize($deciderId, $permission);
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

    /** @return array{id: string, steps: list<string>, min_amount: int}|null */
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

    /** @return list<string> step permissions in order */
    private function steps(string $policyId): array
    {
        return $this->parseSteps((string) DB::table('approval_policies')->where('id', $policyId)->value('steps'), $policyId);
    }

    /** @return list<string> */
    private function parseSteps(string $json, string $policyId): array
    {
        $steps = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $permissions = is_array($steps) ? array_values(array_filter(array_map(
            fn (mixed $step): ?string => is_array($step) && is_string($step['permission'] ?? null) ? $step['permission'] : null, $steps))) : [];
        if ($permissions === [] || count($permissions) !== count((array) $steps)) {
            throw new ApprovalException('INVALID_POLICY', "Approval policy {$policyId} needs at least one step, each with a permission.");
        }

        return $permissions;
    }
}
