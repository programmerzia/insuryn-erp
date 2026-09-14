<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use App\Modules\Insurance\Collections\Domain\Enums\WriteOffStatus;
use App\Modules\Insurance\Collections\Domain\Models\PremiumWriteOff;
use App\Modules\Insurance\Policy\Application\InstallmentPlanner;
use App\Modules\Insurance\Policy\Domain\Enums\PolicyStatus;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Platform\Approvals\ApprovalFacts;
use App\Modules\Platform\Approvals\ApprovalService;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gap fixes W7 (GA-24 "Write off small balance"): a cancelled policy whose customer still owes a little of the premium earned before the cancellation
 * (receivable ageing carried POL-HO-2026-000004's 496.77) is written off instead of chased.
 *
 * - Requested by a holder of `receipt.write_off_request` in the policy's branch, with a reason, for everything the policy still owes — at most
 *   `erp.premium_write_off.max_minor` (a small balance; more is collected, A-231).
 * - Always approved through the approval engine (object type `premium_write_off`): an approval policy on the Approval limits screen when one matches the
 *   amount, otherwise one step for `receipt.write_off_approve` (A-233). The engine refuses the requester (maker ≠ checker).
 * - The final approval credits the installments and posts PREMIUM_WRITTEN_OFF (debit premium written off, credit premium receivable) on the company's
 *   today, for what is still owed then (never more than was requested); when the customer paid meanwhile nothing is posted and the request is "settled".
 * - Every step is audited on the policy.
 */
final class PremiumWriteOffService
{
    public const OBJECT_TYPE = 'premium_write_off';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SodGuard $sod,
        private readonly ApprovalService $approvals,
        private readonly InstallmentPlanner $installments,
        private readonly CollectionsAccountingEvents $accounting,
        private readonly Audit $audit,
        private readonly BusinessClock $clock,
    ) {}

    /** ASSUMPTION: A-231 — the largest balance that may be written off, in minor units (default 1,000.00). */
    public static function limitMinor(): int
    {
        return max(0, (int) config('erp.premium_write_off.max_minor', 100_000));
    }

    /**
     * What a write-off of this policy would be now, for the policy page: the amount owed, whether it is within the limit, the request waiting.
     *
     * @return array{owed_minor: int, limit_minor: int, pending: array{id: string, requested_minor: int, requested_at: string}|null}|null null when the policy is not cancelled or owes nothing
     */
    public function outlook(Policy $policy): ?array
    {
        if ($policy->status !== PolicyStatus::Cancelled) {
            return null;
        }
        $owed = $this->installments->outstanding($policy);
        $pending = PremiumWriteOff::query()->where('policy_id', $policy->id)->where('status', WriteOffStatus::PendingApproval->value)->first();
        if ($owed <= 0 && $pending === null) {
            return null;
        }

        return ['owed_minor' => max(0, $owed), 'limit_minor' => self::limitMinor(),
            'pending' => $pending === null ? null : ['id' => $pending->id, 'requested_minor' => $pending->requested_minor, 'requested_at' => $pending->requested_at->toIso8601String()]];
    }

    /**
     * @throws BusinessRuleViolation REASON_REQUIRED | WRITE_OFF_POLICY_NOT_CANCELLED | WRITE_OFF_NOTHING_OWED | WRITE_OFF_ABOVE_LIMIT | WRITE_OFF_ALREADY_REQUESTED
     */
    public function request(string $policyId, string $reason, string $actorUserId): PremiumWriteOff
    {
        $policy = Policy::query()->findOrFail($policyId);
        $this->permissions->authorize($actorUserId, 'receipt.write_off_request', AuthorizationScope::branch($policy->entity_id, $policy->branch_id));
        if (trim($reason) === '') {
            throw new BusinessRuleViolation('REASON_REQUIRED', 'Say why the balance is written off.');
        }

        return DB::transaction(function () use ($policyId, $reason, $actorUserId): PremiumWriteOff {
            $policy = Policy::query()->whereKey($policyId)->lockForUpdate()->firstOrFail();
            if ($policy->status !== PolicyStatus::Cancelled) {
                throw new BusinessRuleViolation('WRITE_OFF_POLICY_NOT_CANCELLED', "Policy {$policy->number} is {$policy->status->value}. Only a cancelled policy's unpaid premium is written off; collect it otherwise.");
            }
            $owed = $this->installments->outstanding($policy);
            if ($owed <= 0) {
                throw new BusinessRuleViolation('WRITE_OFF_NOTHING_OWED', "Policy {$policy->number} owes no premium.");
            }
            if ($owed > self::limitMinor()) {
                throw new BusinessRuleViolation('WRITE_OFF_ABOVE_LIMIT', "Policy {$policy->number} still owes more than a small balance can be (the limit is "
                    .\App\Modules\Platform\Money\MinorUnits::format(self::limitMinor(), $policy->currency).'). Collect it from the customer.');
            }
            if (PremiumWriteOff::query()->where('policy_id', $policy->id)->where('status', WriteOffStatus::PendingApproval->value)->exists()) {
                throw new BusinessRuleViolation('WRITE_OFF_ALREADY_REQUESTED', "A write-off of policy {$policy->number} is already waiting for approval.");
            }
            $writeOff = PremiumWriteOff::query()->create([
                'entity_id' => $policy->entity_id, 'branch_id' => $policy->branch_id, 'policy_id' => $policy->id, 'requested_minor' => $owed, 'currency' => $policy->currency,
                'status' => WriteOffStatus::PendingApproval->value, 'reason' => trim($reason), 'requested_by' => $actorUserId, 'requested_at' => CarbonImmutable::now(),
            ]);
            // ASSUMPTION: A-233 — an approval policy for premium_write_off decides who approves; without one, any other holder of receipt.write_off_approve.
            $today = $this->clock->today($policy->entity_id);
            $approvalId = $this->approvals->request(self::OBJECT_TYPE, $writeOff->id, new ApprovalFacts($owed), $actorUserId, $today, ['policy_id' => $policy->id])
                ?? $this->approvals->requestWithSteps(self::OBJECT_TYPE, $writeOff->id, [['permission' => 'receipt.write_off_approve', 'role' => null]], $actorUserId, ['policy_id' => $policy->id]);
            $writeOff->forceFill(['approval_id' => $approvalId])->save();
            $this->audit->record('premium_write_off.requested', AuditSubject::of('policy', $policy->id), null, ['write_off_id' => $writeOff->id, 'amount_minor' => $owed],
                trim($reason), 'receipt.write_off_request', Actor::user($actorUserId));

            return $writeOff;
        });
    }

    /** Called by PremiumWriteOffApprovalHandler on the final approval. */
    public function completeApproval(string $writeOffId, string $approverId): void
    {
        $writeOff = $this->lockPending($writeOffId);
        $policy = Policy::query()->whereKey($writeOff->policy_id)->lockForUpdate()->firstOrFail();
        $this->sod->assert($approverId, 'receipt.write_off_approve', AuditSubject::of('policy', $policy->id));
        $amount = min($writeOff->requested_minor, max(0, $this->installments->outstanding($policy)));
        $today = $this->clock->today($policy->entity_id);
        $writeOff->forceFill(['status' => ($amount > 0 ? WriteOffStatus::WrittenOff : WriteOffStatus::Settled)->value, 'written_off_minor' => $amount,
            'decided_by' => $approverId, 'decided_at' => CarbonImmutable::now(), 'written_off_on' => $amount > 0 ? $today->toDateString() : null])->save();
        if ($amount > 0) {
            $this->installments->credit($policy, $amount);
            $this->accounting->premiumWrittenOff($writeOff, $policy, $today);
        }
        $this->audit->record($amount > 0 ? 'premium_write_off.approved' : 'premium_write_off.settled', AuditSubject::of('policy', $policy->id), ['status' => 'pending_approval'],
            ['write_off_id' => $writeOff->id, 'amount_minor' => $amount, 'on' => $today->toDateString()], null, 'receipt.write_off_approve', Actor::user($approverId));
    }

    public function rejectApproval(string $writeOffId, string $deciderId, string $reason): void
    {
        $writeOff = $this->lockPending($writeOffId);
        $writeOff->forceFill(['status' => WriteOffStatus::Rejected->value, 'decided_by' => $deciderId, 'decided_at' => CarbonImmutable::now(), 'rejection_reason' => $reason])->save();
        $this->audit->record('premium_write_off.rejected', AuditSubject::of('policy', $writeOff->policy_id), ['status' => 'pending_approval'], ['write_off_id' => $writeOff->id],
            $reason, 'receipt.write_off_approve', Actor::user($deciderId));
    }

    /** @throws BusinessRuleViolation WRITE_OFF_NOT_PENDING */
    private function lockPending(string $writeOffId): PremiumWriteOff
    {
        $writeOff = PremiumWriteOff::query()->whereKey($writeOffId)->lockForUpdate()->firstOrFail();
        if ($writeOff->status !== WriteOffStatus::PendingApproval) {
            throw new BusinessRuleViolation('WRITE_OFF_NOT_PENDING', 'This write-off has already been decided.');
        }

        return $writeOff;
    }
}
