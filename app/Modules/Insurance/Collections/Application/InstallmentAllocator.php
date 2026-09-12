<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use App\Modules\Insurance\Collections\Domain\Events\ReceiptAllocated;
use App\Modules\Insurance\Collections\Domain\Models\Receipt;
use App\Modules\Insurance\Collections\Domain\Models\ReceiptAllocation;
use App\Modules\Insurance\Policy\Domain\Enums\InstallmentStatus;
use App\Modules\Insurance\Policy\Domain\Models\Installment;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

/**
 * Applies part of a receipt to one installment: pays the installment down and records the allocation. The caller
 * posts the matching accounting event. Must run inside the caller's transaction.
 */
final class InstallmentAllocator
{
    /**
     * $allocatedOn is the date the allocation's accounting event posts on.
     *
     * @return array{0: ReceiptAllocation, 1: Policy}
     *
     * @throws BusinessRuleViolation INVALID_AMOUNT | CURRENCY_MISMATCH | ALLOCATION_EXCEEDS_OUTSTANDING
     */
    public function allocate(Receipt $receipt, string $installmentId, int $amountMinor, ?string $suspenseItemId, string $actorUserId, CarbonImmutable $allocatedOn): array
    {
        if ($amountMinor <= 0) {
            throw new BusinessRuleViolation('INVALID_AMOUNT', 'An allocation must be a positive amount.');
        }
        $installment = Installment::query()->whereKey($installmentId)->lockForUpdate()->firstOrFail();
        $policy = Policy::query()->findOrFail($installment->policy_id);
        if ($policy->currency !== $receipt->currency) {
            throw new BusinessRuleViolation('CURRENCY_MISMATCH', "Receipt {$receipt->number} is in {$receipt->currency}; policy {$policy->number} is in {$policy->currency}.");
        }
        if ($amountMinor > $installment->outstanding()) {
            throw new BusinessRuleViolation('ALLOCATION_EXCEEDS_OUTSTANDING', "Installment {$installment->no} of policy {$policy->number} has {$installment->outstanding()} outstanding, less than {$amountMinor}.");
        }

        $installment->paid_minor += $amountMinor;
        $installment->status = $installment->outstanding() === 0 ? InstallmentStatus::Paid : InstallmentStatus::PartiallyPaid;
        $installment->save();
        $allocation = ReceiptAllocation::query()->create([
            'receipt_id' => $receipt->id, 'target_type' => 'installment', 'target_id' => $installment->id, 'policy_id' => $policy->id,
            'suspense_item_id' => $suspenseItemId, 'amount_minor' => $amountMinor, 'posted_on' => $allocatedOn->toDateString(),
            'allocated_at' => CarbonImmutable::now(), 'allocated_by' => $actorUserId,
        ]);
        Event::dispatch(new ReceiptAllocated($allocation->id, $receipt->id, $policy->id, $amountMinor, $allocatedOn));

        return [$allocation, $policy];
    }
}
