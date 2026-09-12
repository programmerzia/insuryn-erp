<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use App\Modules\Insurance\Collections\Domain\Enums\RefundStatus;
use App\Modules\Insurance\Collections\Domain\Models\Refund;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Customer refunds (design §4.4 event B). One person requests (receipt.refund_request), another releases
 * (receipt.refund_release, SoD §7.3); only the release posts REFUND_ISSUED. A policy can be refunded at most the
 * refund_due of its cancellations, less refunds requested or released.
 */
final class RefundService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SodGuard $sod,
        private readonly CollectionsAccountingEvents $accounting,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation INVALID_AMOUNT | REASON_REQUIRED | REFUND_EXCEEDS_DUE */
    public function request(string $policyId, int $amountMinor, string $reason, string $actorUserId): Refund
    {
        $policy = Policy::query()->findOrFail($policyId);
        $this->permissions->authorize($actorUserId, 'receipt.refund_request', AuthorizationScope::branch($policy->entity_id, $policy->branch_id));
        if ($amountMinor <= 0) {
            throw new BusinessRuleViolation('INVALID_AMOUNT', 'A refund must be a positive amount.');
        }
        self::assertReason($reason);

        return DB::transaction(function () use ($policyId, $amountMinor, $reason, $actorUserId): Refund {
            $policy = Policy::query()->whereKey($policyId)->lockForUpdate()->firstOrFail();
            $available = $this->refundDue($policy) - $this->refundsClaimed($policy);
            if ($amountMinor > $available) {
                throw new BusinessRuleViolation('REFUND_EXCEEDS_DUE', "Policy {$policy->number} has {$available} refundable, less than {$amountMinor}.");
            }
            $refund = Refund::query()->create([
                'entity_id' => $policy->entity_id, 'branch_id' => $policy->branch_id, 'policy_id' => $policy->id, 'party_id' => $policy->policyholder_party_id,
                'amount_minor' => $amountMinor, 'currency' => $policy->currency, 'reason' => $reason, 'status' => RefundStatus::Requested->value,
                'requested_by' => $actorUserId, 'requested_at' => CarbonImmutable::now(),
            ]);
            $this->audit->record('refund.requested', AuditSubject::of('refund', $refund->id), null, ['policy_id' => $policy->id, 'amount_minor' => $amountMinor],
                $reason, 'receipt.refund_request', Actor::user($actorUserId));

            return $refund;
        });
    }

    /** @throws BusinessRuleViolation REFUND_NOT_REQUESTED */
    public function release(string $refundId, string $actorUserId, CarbonImmutable $paidOn): Refund
    {
        $refund = $this->authorizeDecision($refundId, 'receipt.refund_release', $actorUserId);

        return DB::transaction(function () use ($refund, $actorUserId, $paidOn): Refund {
            $refund = $this->lockRequested($refund->id);
            $refund->forceFill(['status' => RefundStatus::Released->value, 'decided_by' => $actorUserId, 'decided_at' => CarbonImmutable::now()])->save();
            $this->accounting->refundIssued($refund, Policy::query()->findOrFail($refund->policy_id), $paidOn);
            $this->audit->record('refund.released', AuditSubject::of('refund', $refund->id), ['status' => 'requested'], ['status' => 'released', 'paid_on' => $paidOn->toDateString()],
                null, 'receipt.refund_release', Actor::user($actorUserId));

            return $refund;
        });
    }

    /** @throws BusinessRuleViolation REASON_REQUIRED | REFUND_NOT_REQUESTED */
    public function reject(string $refundId, string $reason, string $actorUserId): Refund
    {
        self::assertReason($reason);
        $refund = $this->authorizeDecision($refundId, 'receipt.refund_release', $actorUserId);

        return DB::transaction(function () use ($refund, $reason, $actorUserId): Refund {
            $refund = $this->lockRequested($refund->id);
            $refund->forceFill(['status' => RefundStatus::Rejected->value, 'decided_by' => $actorUserId, 'decided_at' => CarbonImmutable::now(), 'decision_reason' => $reason])->save();
            $this->audit->record('refund.rejected', AuditSubject::of('refund', $refund->id), ['status' => 'requested'], ['status' => 'rejected'],
                $reason, 'receipt.refund_release', Actor::user($actorUserId));

            return $refund;
        });
    }

    private function authorizeDecision(string $refundId, string $permission, string $actorUserId): Refund
    {
        $refund = Refund::query()->findOrFail($refundId);
        $this->permissions->authorize($actorUserId, $permission, AuthorizationScope::branch($refund->entity_id, $refund->branch_id));
        $this->sod->assert($actorUserId, $permission, AuditSubject::of('refund', $refund->id));

        return $refund;
    }

    private function lockRequested(string $refundId): Refund
    {
        $refund = Refund::query()->whereKey($refundId)->lockForUpdate()->firstOrFail();
        if ($refund->status !== RefundStatus::Requested) {
            throw new BusinessRuleViolation('REFUND_NOT_REQUESTED', "Refund {$refund->id} is {$refund->status->value}.");
        }

        return $refund;
    }

    private function refundDue(Policy $policy): int
    {
        return (int) DB::table('policy_transactions')->where('policy_id', $policy->id)->where('type', 'cancellation')
            ->selectRaw("coalesce(sum((amounts->>'refund_due')::bigint), 0) as due")->value('due');
    }

    private function refundsClaimed(Policy $policy): int
    {
        return (int) Refund::query()->where('policy_id', $policy->id)->whereIn('status', [RefundStatus::Requested->value, RefundStatus::Released->value])->sum('amount_minor');
    }

    private static function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new BusinessRuleViolation('REASON_REQUIRED', 'A refund decision needs a reason.');
        }
    }
}
