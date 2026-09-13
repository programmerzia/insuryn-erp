<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use App\Modules\Insurance\Collections\Domain\Enums\ReceiptStatus;
use App\Modules\Insurance\Collections\Domain\Enums\SuspenseStatus;
use App\Modules\Insurance\Collections\Domain\Events\ReceiptAllocationReversed;
use App\Modules\Insurance\Collections\Domain\Models\Receipt;
use App\Modules\Insurance\Collections\Domain\Models\ReceiptAllocation;
use App\Modules\Insurance\Collections\Domain\Models\SuspenseItem;
use App\Modules\Insurance\Policy\Domain\Enums\InstallmentStatus;
use App\Modules\Insurance\Policy\Domain\Enums\PolicyStatus;
use App\Modules\Insurance\Policy\Domain\Models\Installment;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * A bounced cheque (spec §4). Business events undo the receipt, on the bounce date, in one transaction — never journal reversals from this
 * module (CONTEXT.md #4): each live allocation → PREMIUM_RECEIPT_REVERSED (direct) or RECEIPT_ALLOCATION_REVERSED (out of suspense), its
 * installment unpaid again and `ReceiptAllocationReversed` for commission; the receipt's suspense item → RECEIPT_BOUNCED for its full amount.
 * The dated rows (reversed_on, bounced_on) keep premium and suspense subledgers reconcilable as of any date. Interpretation: receipt.allocate.
 */
final class ChequeBounceService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly CollectionsAccountingEvents $accounting,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation REASON_REQUIRED | NOT_A_CHEQUE | ALREADY_BOUNCED | BOUNCE_BEFORE_RECEIPT | BOUNCE_AFTER_CANCELLATION */
    public function bounce(string $receiptId, string $reason, string $actorUserId, CarbonImmutable $bouncedOn): Receipt
    {
        $receipt = Receipt::query()->findOrFail($receiptId);
        $this->permissions->authorize($actorUserId, 'receipt.allocate', AuthorizationScope::branch($receipt->entity_id, $receipt->branch_id));
        if (trim($reason) === '') {
            throw new BusinessRuleViolation('REASON_REQUIRED', 'A bounced cheque needs the bank\'s reason.');
        }

        return DB::transaction(function () use ($receiptId, $reason, $actorUserId, $bouncedOn): Receipt {
            $receipt = $this->lockBounceable($receiptId, $bouncedOn);
            $allocations = ReceiptAllocation::query()->where('receipt_id', $receipt->id)->whereNull('reversed_on')->orderBy('allocated_at')->lockForUpdate()->get();
            foreach ($allocations as $allocation) {
                $this->reverseAllocation($receipt, $allocation, $bouncedOn);
            }
            $item = SuspenseItem::query()->where('receipt_id', $receipt->id)->lockForUpdate()->first();
            if ($item !== null) {
                $item->forceFill(['allocated_minor' => 0, 'status' => SuspenseStatus::Bounced->value, 'bounced_on' => $bouncedOn->toDateString()])->save();
                $this->accounting->receiptBounced($receipt, $item, $bouncedOn);
            }
            $receipt->forceFill(['status' => ReceiptStatus::Bounced->value, 'bounced_on' => $bouncedOn->toDateString(), 'bounce_reason' => $reason])->save();
            $this->audit->record('receipt.bounced', AuditSubject::of('receipt', $receipt->id), null,
                ['bounced_on' => $bouncedOn->toDateString(), 'allocations_reversed' => $allocations->count(), 'suspense_minor' => $item === null ? 0 : $item->amount_minor],
                $reason, 'receipt.allocate', Actor::user($actorUserId));

            return $receipt;
        });
    }

    private function lockBounceable(string $receiptId, CarbonImmutable $bouncedOn): Receipt
    {
        $receipt = Receipt::query()->whereKey($receiptId)->lockForUpdate()->firstOrFail();
        if ($receipt->channel !== 'cheque') {
            throw new BusinessRuleViolation('NOT_A_CHEQUE', "Receipt {$receipt->number} was not paid by cheque.");
        }
        if ($receipt->status === ReceiptStatus::Bounced) {
            throw new BusinessRuleViolation('ALREADY_BOUNCED', "Receipt {$receipt->number} has already bounced.");
        }
        if ($bouncedOn->lessThan($receipt->value_date)) {
            throw new BusinessRuleViolation('BOUNCE_BEFORE_RECEIPT', 'A cheque cannot bounce before it was received.');
        }
        // Conservative: a cancellation already settled refunds and receivable credits on the assumption this money arrived; undoing it
        // automatically would leave those wrong, so a person handles it.
        $cancelled = Policy::query()->whereIn('id', ReceiptAllocation::query()->where('receipt_id', $receipt->id)->whereNull('reversed_on')->select('policy_id'))
            ->where('status', PolicyStatus::Cancelled->value)->exists();
        if ($cancelled) {
            throw new BusinessRuleViolation('BOUNCE_AFTER_CANCELLATION', "Receipt {$receipt->number} paid a policy that has since been cancelled; reverse it manually.");
        }

        return $receipt;
    }

    private function reverseAllocation(Receipt $receipt, ReceiptAllocation $allocation, CarbonImmutable $bouncedOn): void
    {
        $installment = Installment::query()->whereKey($allocation->target_id)->lockForUpdate()->firstOrFail();
        $installment->paid_minor -= $allocation->amount_minor;
        $installment->status = match (true) {
            $installment->outstanding() === 0 && $installment->paid_minor === 0 => InstallmentStatus::Cancelled,
            $installment->outstanding() === 0 => InstallmentStatus::Paid,
            $installment->paid_minor === 0 => InstallmentStatus::Pending,
            default => InstallmentStatus::PartiallyPaid,
        };
        $installment->save();
        $allocation->forceFill(['reversed_on' => $bouncedOn->toDateString()])->save();
        $policy = Policy::query()->findOrFail((string) $allocation->policy_id);
        $this->accounting->allocationReversed($receipt, $allocation, $policy, $bouncedOn);
        Event::dispatch(new ReceiptAllocationReversed($allocation->id, $policy->id, $bouncedOn));
    }
}
