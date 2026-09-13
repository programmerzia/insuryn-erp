<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use App\Modules\Finance\Bank\Application\BankAccountQuery;
use App\Modules\Insurance\Collections\Domain\Enums\ReceiptStatus;
use App\Modules\Insurance\Collections\Domain\Enums\SuspenseStatus;
use App\Modules\Insurance\Collections\Domain\Models\Receipt;
use App\Modules\Insurance\Collections\Domain\Models\SuspenseItem;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records money received (design §2.4, §4.2, §4.9). Allocated parts post PREMIUM_RECEIVED per allocation; any
 * remainder becomes a suspense item and posts RECEIPT_RECORDED. Receipt, allocations and events commit together.
 */
final class ReceiptService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly DocumentNumberer $numbers,
        private readonly InstallmentAllocator $allocator,
        private readonly CollectionsAccountingEvents $accounting,
        private readonly BankAccountQuery $bankAccounts,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation INVALID_BANK_ACCOUNT | INVALID_AMOUNT | ALLOCATION_EXCEEDS_RECEIPT | ALLOCATION_EXCEEDS_OUTSTANDING | CURRENCY_MISMATCH */
    public function record(RecordReceiptRequest $request, string $actorUserId): Receipt
    {
        $scope = AuthorizationScope::branch($request->entityId, $request->branchId);
        $this->permissions->authorize($actorUserId, 'receipt.create', $scope);
        if ($request->allocations !== []) {
            $this->permissions->authorize($actorUserId, 'receipt.allocate', $scope);
        }
        if ($request->amountMinor <= 0) {
            throw new BusinessRuleViolation('INVALID_AMOUNT', 'A receipt must be a positive amount.');
        }
        if ($request->allocatedMinor() > $request->amountMinor) {
            throw new BusinessRuleViolation('ALLOCATION_EXCEEDS_RECEIPT', "Allocations of {$request->allocatedMinor()} exceed the receipt of {$request->amountMinor}.");
        }
        $this->assertCheque($request);
        if ($request->bankAccountId !== null) {
            $this->bankAccounts->glAccountFor($request->bankAccountId, $request->entityId, $request->currency);
        }
        $number = $this->numbers->reserve(new DocumentNumberScope($request->entityId, $request->branchId, 'receipt', 'RCT', $request->valueDate), $actorUserId);

        return DB::transaction(function () use ($request, $actorUserId, $number): Receipt {
            $receipt = Receipt::query()->create([
                'entity_id' => $request->entityId, 'branch_id' => $request->branchId, 'number' => $number->number, 'party_id' => $request->partyId,
                'channel' => $request->channel, 'amount_minor' => $request->amountMinor, 'currency' => $request->currency,
                'value_date' => $request->valueDate->toDateString(), 'received_at' => CarbonImmutable::now(), 'bank_account_id' => $request->bankAccountId,
                'reference' => $request->reference, 'status' => $this->statusFor($request), 'created_by' => $actorUserId,
                'cheque_no' => $request->cheque?->number, 'cheque_bank' => $request->cheque?->bank, 'cheque_date' => $request->cheque?->date->toDateString(),
            ]);
            $this->numbers->markUsed($number->id, 'receipt', $receipt->id);
            foreach ($request->allocations as $line) {
                [$allocation, $policy] = $this->allocator->allocate($receipt, $line->installmentId, $line->amountMinor, null, $actorUserId, $request->valueDate);
                $this->accounting->premiumReceived($receipt, $allocation, $policy);
            }
            $remainder = $request->amountMinor - $request->allocatedMinor();
            if ($remainder > 0) {
                $item = SuspenseItem::query()->create(['entity_id' => $request->entityId, 'receipt_id' => $receipt->id, 'amount_minor' => $remainder,
                    'allocated_minor' => 0, 'aged_since' => $request->valueDate->toDateString(), 'status' => SuspenseStatus::Open->value]);
                $this->accounting->receiptRecorded($receipt, $item);
            }
            $this->audit->record('receipt.recorded', AuditSubject::of('receipt', $receipt->id), null,
                ['number' => $receipt->number, 'amount_minor' => $receipt->amount_minor, 'allocated_minor' => $request->allocatedMinor(), 'suspense_minor' => $remainder],
                null, 'receipt.create', Actor::user($actorUserId));

            return $receipt;
        });
    }

    /** @throws BusinessRuleViolation CHEQUE_DETAILS_REQUIRED | DUPLICATE_CHEQUE (a cheque is presented once unless it bounced) */
    private function assertCheque(RecordReceiptRequest $request): void
    {
        if ($request->channel !== 'cheque') {
            return;
        }
        if ($request->cheque === null || trim($request->cheque->number) === '' || trim($request->cheque->bank) === '') {
            throw new BusinessRuleViolation('CHEQUE_DETAILS_REQUIRED', 'A cheque receipt needs the cheque number, bank and date.');
        }
        $presented = Receipt::query()->where('channel', 'cheque')->where('cheque_no', $request->cheque->number)
            ->whereRaw('lower(cheque_bank) = ?', [mb_strtolower($request->cheque->bank)])->where('status', '<>', ReceiptStatus::Bounced->value)->exists();
        if ($presented) {
            throw new BusinessRuleViolation('DUPLICATE_CHEQUE', "Cheque {$request->cheque->number} of {$request->cheque->bank} has already been received.");
        }
    }

    private function statusFor(RecordReceiptRequest $request): ReceiptStatus
    {
        return match (true) {
            $request->allocatedMinor() === 0 => ReceiptStatus::Unallocated,
            $request->allocatedMinor() === $request->amountMinor => ReceiptStatus::Allocated,
            default => ReceiptStatus::PartiallyAllocated,
        };
    }
}
