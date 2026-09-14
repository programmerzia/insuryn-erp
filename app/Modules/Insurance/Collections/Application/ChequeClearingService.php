<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use App\Modules\Insurance\Collections\Domain\Enums\ReceiptStatus;
use App\Modules\Insurance\Collections\Domain\Models\Receipt;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gap fix GA-14: a cheque received into cheques in clearing is cleared when the bank credits it — CHEQUE_CLEARED moves the whole cheque from
 * cheques_in_clearing to the receipt's bank account on the clearing day, and the receipt keeps `cleared_on`. A cheque that bounces before that never
 * reached the bank, so its reversal leaves clearing (rule version 2). ASSUMPTION A-217: whoever may record a bounce (receipt.allocate on the receipt's
 * branch) clears; the bank statement line then matches the cleared amount by its receipt number or reference.
 */
final class ChequeClearingService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly CollectionsAccountingEvents $accounting,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation NOT_A_CHEQUE | CHEQUE_NOT_IN_CLEARING | ALREADY_CLEARED | ALREADY_BOUNCED | CLEARED_BEFORE_RECEIPT */
    public function clear(string $receiptId, string $actorUserId, CarbonImmutable $clearedOn): Receipt
    {
        $receipt = Receipt::query()->findOrFail($receiptId);
        $this->permissions->authorize($actorUserId, 'receipt.allocate', AuthorizationScope::branch($receipt->entity_id, $receipt->branch_id));

        return DB::transaction(function () use ($receiptId, $actorUserId, $clearedOn): Receipt {
            $receipt = Receipt::query()->whereKey($receiptId)->lockForUpdate()->firstOrFail();
            if ($receipt->channel !== 'cheque') {
                throw new BusinessRuleViolation('NOT_A_CHEQUE', "Receipt {$receipt->number} was not paid by cheque.");
            }
            if ($receipt->status === ReceiptStatus::Bounced) {
                throw new BusinessRuleViolation('ALREADY_BOUNCED', "Receipt {$receipt->number} has already bounced.");
            }
            if (! $receipt->in_clearing) {
                throw new BusinessRuleViolation('CHEQUE_NOT_IN_CLEARING', "Cheque receipt {$receipt->number} went straight to the bank when it was recorded, so there is nothing to clear.");
            }
            if ($receipt->cleared_on !== null) {
                throw new BusinessRuleViolation('ALREADY_CLEARED', "Cheque receipt {$receipt->number} cleared on {$receipt->cleared_on->toDateString()}.");
            }
            if ($clearedOn->lessThan($receipt->value_date)) {
                throw new BusinessRuleViolation('CLEARED_BEFORE_RECEIPT', 'A cheque cannot clear before it was received.');
            }
            $receipt->forceFill(['cleared_on' => $clearedOn->toDateString()])->save();
            $this->accounting->chequeCleared($receipt, $clearedOn);
            $this->audit->record('receipt.cheque_cleared', AuditSubject::of('receipt', $receipt->id), ['cleared_on' => null], ['cleared_on' => $clearedOn->toDateString()],
                null, 'receipt.allocate', Actor::user($actorUserId));

            return $receipt;
        });
    }
}
