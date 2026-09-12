<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application;

use App\Modules\Finance\Bank\Application\BankAccountQuery;
use App\Modules\Insurance\Claims\Domain\Enums\ClaimPaymentStatus;
use App\Modules\Insurance\Claims\Domain\Enums\ClaimStatus;
use App\Modules\Insurance\Claims\Domain\Models\Claim;
use App\Modules\Insurance\Claims\Domain\Models\ClaimPayment;
use App\Modules\Platform\Approvals\ApprovalFacts;
use App\Modules\Platform\Approvals\ApprovalService;
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
 * Design §5.5 approve and pay (partial payments allowed), §4.7 events. "Approval limits by amount/role at approve and each pay": when an
 * approval policy for `claim_payment` (approve) or `claim_payment_release` (pay) matches the amount, the step waits for that approval and
 * the handler completes it; otherwise the acting user's permission decides (A-2). SoD: claim.reserve ✕ claim.approve on the claim;
 * claim.pay_request ✕ claim.pay_release on the payment.
 */
final class ClaimPaymentService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SodGuard $sod,
        private readonly ApprovalService $approvals,
        private readonly ClaimReserveBook $reserves,
        private readonly ClaimAccountingEvents $accounting,
        private readonly BankAccountQuery $bankAccounts,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation INVALID_AMOUNT | APPROVAL_EXCEEDS_RESERVE | INVALID_CLAIM_TRANSITION */
    public function approve(string $claimId, int $amountMinor, string $payeePartyId, string $actorUserId, CarbonImmutable $on): ClaimPayment
    {
        $claim = Claim::query()->findOrFail($claimId);
        $this->permissions->authorize($actorUserId, 'claim.approve', AuthorizationScope::branch($claim->entity_id, $claim->branch_id));
        $this->sod->assert($actorUserId, 'claim.approve', AuditSubject::of('claim', $claim->id));
        if ($amountMinor <= 0) {
            throw new BusinessRuleViolation('INVALID_AMOUNT', 'An approved amount must be positive.');
        }

        return DB::transaction(function () use ($claimId, $amountMinor, $payeePartyId, $actorUserId, $on): ClaimPayment {
            $claim = Claim::query()->whereKey($claimId)->lockForUpdate()->firstOrFail();
            if (! in_array($claim->status, [ClaimStatus::Reserved, ClaimStatus::Approved, ClaimStatus::Paid], true)) {
                throw new BusinessRuleViolation('INVALID_CLAIM_TRANSITION', "A {$claim->status->value} claim cannot approve a payment.");
            }
            $available = $claim->reserve_minor - $this->reserves->committedMinor($claim->id);
            if ($amountMinor > $available) {
                throw new BusinessRuleViolation('APPROVAL_EXCEEDS_RESERVE', "Claim {$claim->number} has {$available} of reserve left, less than {$amountMinor}.");
            }
            $payment = ClaimPayment::query()->create(['claim_id' => $claim->id, 'payee_party_id' => $payeePartyId, 'amount_minor' => $amountMinor, 'currency' => $claim->currency,
                'status' => ClaimPaymentStatus::PendingApproval->value, 'approved_on' => $on->toDateString(), 'approved_by' => $actorUserId]);
            $this->audit->record('claim_payment.approval_requested', AuditSubject::of('claim_payment', $payment->id), null, ['claim_id' => $claim->id, 'amount_minor' => $amountMinor],
                null, 'claim.approve', Actor::user($actorUserId));
            if ($this->approvals->request('claim_payment', $payment->id, new ApprovalFacts($amountMinor), $actorUserId, $on) === null) {
                $this->completeApproval($payment->id, $actorUserId);
            }

            return $payment->refresh();
        });
    }

    /** Posts CLAIM_APPROVED. Called directly or by ClaimPaymentApprovalHandler (the final approver must also pass claim SoD). */
    public function completeApproval(string $paymentId, string $approverId): void
    {
        $payment = $this->lockPayment($paymentId, [ClaimPaymentStatus::PendingApproval]);
        $claim = Claim::query()->whereKey($payment->claim_id)->lockForUpdate()->firstOrFail();
        $this->sod->assert($approverId, 'claim.approve', AuditSubject::of('claim', $claim->id));
        $payment->forceFill(['status' => ClaimPaymentStatus::Approved->value, 'approved_by' => $approverId])->save();
        if ($claim->status === ClaimStatus::Reserved) {
            $claim->forceFill(['status' => ClaimStatus::Approved->value])->save();
        }
        $this->accounting->approved($claim, $payment);
        $this->audit->record('claim_payment.approved', AuditSubject::of('claim', $claim->id), null, ['claim_payment_id' => $payment->id, 'amount_minor' => $payment->amount_minor],
            null, 'claim.approve', Actor::user($approverId));
    }

    public function rejectApproval(string $paymentId, string $deciderId, string $reason): void
    {
        $payment = $this->lockPayment($paymentId, [ClaimPaymentStatus::PendingApproval]);
        $payment->forceFill(['status' => ClaimPaymentStatus::Rejected->value])->save();
        $this->audit->record('claim_payment.rejected', AuditSubject::of('claim_payment', $payment->id), null, null, $reason, 'claim.approve', Actor::user($deciderId));
    }

    /** @throws BusinessRuleViolation INVALID_PAYMENT_TRANSITION | INVALID_BANK_ACCOUNT */
    public function requestRelease(string $paymentId, string $actorUserId, ?string $bankAccountId): ClaimPayment
    {
        $claim = $this->claimOf($paymentId);
        $this->permissions->authorize($actorUserId, 'claim.pay_request', AuthorizationScope::branch($claim->entity_id, $claim->branch_id));
        if ($bankAccountId !== null) {
            $this->bankAccounts->glAccountFor($bankAccountId, $claim->entity_id, $claim->currency);
        }

        return DB::transaction(function () use ($paymentId, $actorUserId, $bankAccountId): ClaimPayment {
            $payment = $this->lockPayment($paymentId, [ClaimPaymentStatus::Approved]);
            $payment->forceFill(['status' => ClaimPaymentStatus::ReleaseRequested->value, 'release_requested_by' => $actorUserId, 'bank_account_id' => $bankAccountId])->save();
            $this->audit->record('claim_payment.release_requested', AuditSubject::of('claim_payment', $payment->id), null, ['bank_account_id' => $bankAccountId],
                null, 'claim.pay_request', Actor::user($actorUserId));

            return $payment;
        });
    }

    /** @throws BusinessRuleViolation INVALID_PAYMENT_TRANSITION */
    public function release(string $paymentId, string $actorUserId, CarbonImmutable $paidOn): ClaimPayment
    {
        $claim = $this->claimOf($paymentId);
        $this->permissions->authorize($actorUserId, 'claim.pay_release', AuthorizationScope::branch($claim->entity_id, $claim->branch_id));
        $this->sod->assert($actorUserId, 'claim.pay_release', AuditSubject::of('claim_payment', $paymentId));

        return DB::transaction(function () use ($paymentId, $actorUserId, $paidOn): ClaimPayment {
            $payment = $this->lockPayment($paymentId, [ClaimPaymentStatus::ReleaseRequested]);
            $payment->forceFill(['status' => ClaimPaymentStatus::ReleasePendingApproval->value, 'released_by' => $actorUserId, 'paid_on' => $paidOn->toDateString()])->save();
            $this->audit->record('claim_payment.released', AuditSubject::of('claim_payment', $payment->id), null, ['paid_on' => $paidOn->toDateString()],
                null, 'claim.pay_release', Actor::user($actorUserId));
            if ($this->approvals->request('claim_payment_release', $payment->id, new ApprovalFacts($payment->amount_minor), $actorUserId, $paidOn) === null) {
                $this->completeRelease($payment->id);
            }

            return $payment->refresh();
        });
    }

    /** Posts CLAIM_PAID on the paid date recorded at release. Called directly or by ClaimPaymentReleaseApprovalHandler. */
    public function completeRelease(string $paymentId): void
    {
        $payment = $this->lockPayment($paymentId, [ClaimPaymentStatus::ReleasePendingApproval]);
        $claim = Claim::query()->whereKey($payment->claim_id)->lockForUpdate()->firstOrFail();
        $payment->forceFill(['status' => ClaimPaymentStatus::Paid->value])->save();
        if (in_array($claim->status, [ClaimStatus::Reserved, ClaimStatus::Approved], true)) {
            $claim->forceFill(['status' => ClaimStatus::Paid->value])->save();
        }
        $this->accounting->paid($claim, $payment, $payment->paid_on ?? CarbonImmutable::today());
    }

    /** A rejected release returns the payment to approved, so it can be requested again. */
    public function rejectRelease(string $paymentId, string $deciderId, string $reason): void
    {
        $payment = $this->lockPayment($paymentId, [ClaimPaymentStatus::ReleasePendingApproval]);
        $payment->forceFill(['status' => ClaimPaymentStatus::Approved->value, 'released_by' => null, 'paid_on' => null])->save();
        $this->audit->record('claim_payment.release_rejected', AuditSubject::of('claim_payment', $payment->id), null, null, $reason, 'claim.pay_release', Actor::user($deciderId));
    }

    private function claimOf(string $paymentId): Claim
    {
        return Claim::query()->findOrFail(ClaimPayment::query()->findOrFail($paymentId)->claim_id);
    }

    /** @param list<ClaimPaymentStatus> $allowed */
    private function lockPayment(string $paymentId, array $allowed): ClaimPayment
    {
        $payment = ClaimPayment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
        if (! in_array($payment->status, $allowed, true)) {
            throw new BusinessRuleViolation('INVALID_PAYMENT_TRANSITION', "Claim payment {$payment->id} is {$payment->status->value}.");
        }

        return $payment;
    }
}
