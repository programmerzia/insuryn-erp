<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use App\Modules\Insurance\Collections\Domain\Enums\ReceiptStatus;
use App\Modules\Insurance\Collections\Domain\Enums\SuspenseStatus;
use App\Modules\Insurance\Collections\Domain\Models\Receipt;
use App\Modules\Insurance\Collections\Domain\Models\ReceiptAllocation;
use App\Modules\Insurance\Collections\Domain\Models\SuspenseItem;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Allocates suspense to installments (design §4.9 second event, spec §2 allocation queue). */
final class SuspenseService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly InstallmentAllocator $allocator,
        private readonly CollectionsAccountingEvents $accounting,
        private readonly Audit $audit,
    ) {}

    /**
     * Posts on the later of $allocatedOn and the receipt's value date, so suspense is never debited before it was credited.
     *
     * @throws BusinessRuleViolation SUSPENSE_NOT_OPEN | ALLOCATION_EXCEEDS_SUSPENSE | ALLOCATION_EXCEEDS_OUTSTANDING | CURRENCY_MISMATCH
     */
    public function allocate(string $suspenseItemId, string $installmentId, int $amountMinor, string $actorUserId, CarbonImmutable $allocatedOn): ReceiptAllocation
    {
        $item = SuspenseItem::query()->findOrFail($suspenseItemId);
        $receipt = Receipt::query()->findOrFail($item->receipt_id);
        $this->permissions->authorize($actorUserId, 'receipt.allocate', AuthorizationScope::branch($receipt->entity_id, $receipt->branch_id));

        return DB::transaction(function () use ($suspenseItemId, $receipt, $installmentId, $amountMinor, $actorUserId, $allocatedOn): ReceiptAllocation {
            $item = SuspenseItem::query()->whereKey($suspenseItemId)->lockForUpdate()->firstOrFail();
            if ($item->status !== SuspenseStatus::Open) {
                throw new BusinessRuleViolation('SUSPENSE_NOT_OPEN', "Suspense item {$item->id} is {$item->status->value}.");
            }
            if ($amountMinor > $item->openMinor()) {
                throw new BusinessRuleViolation('ALLOCATION_EXCEEDS_SUSPENSE', "Suspense item {$item->id} has {$item->openMinor()} open, less than {$amountMinor}.");
            }
            $postingDate = $allocatedOn->max($receipt->value_date);
            [$allocation, $policy] = $this->allocator->allocate($receipt, $installmentId, $amountMinor, $item->id, $actorUserId, $postingDate);

            $item->allocated_minor += $amountMinor;
            $item->status = $item->openMinor() === 0 ? SuspenseStatus::Allocated : SuspenseStatus::Open;
            $item->save();
            $this->refreshReceiptStatus($receipt);
            $this->accounting->receiptAllocated($receipt, $allocation, $policy, $postingDate);
            $this->audit->record('suspense.allocated', AuditSubject::of('suspense_item', $item->id), null,
                ['receipt_allocation_id' => $allocation->id, 'installment_id' => $installmentId, 'amount_minor' => $amountMinor, 'open_minor' => $item->openMinor()],
                null, 'receipt.allocate', Actor::user($actorUserId));

            return $allocation;
        });
    }

    private function refreshReceiptStatus(Receipt $receipt): void
    {
        $stillOpen = SuspenseItem::query()->where('receipt_id', $receipt->id)->where('status', SuspenseStatus::Open->value)->exists();
        $receipt->status = $stillOpen ? ReceiptStatus::PartiallyAllocated : ReceiptStatus::Allocated;
        $receipt->save();
    }
}
