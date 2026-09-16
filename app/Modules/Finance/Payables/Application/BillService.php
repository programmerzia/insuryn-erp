<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Application;

use App\Modules\Finance\Payables\Domain\Enums\BillStatus;
use App\Modules\Finance\Payables\Domain\Models\ApBill;
use App\Modules\Finance\Payables\Domain\Models\ApBillLine;
use App\Modules\Finance\Payables\Domain\Models\Supplier;
use App\Modules\Finance\Payables\Domain\Withholding;
use App\Modules\Platform\Approvals\ApprovalFacts;
use App\Modules\Platform\Approvals\ApprovalService;
use App\Modules\Platform\Approvals\Decision;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Authorization\SodViolation;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Supplier bills (addendum v2 §B.4): entered as a draft (`ap.enter_bills`), submitted for approval with a BIL number, approved by someone else
 * (`ap.approve_bills`, maker ≠ checker and SoD on the bill) which posts AP_BILL_POSTED on the bill date, paid by payment runs, or cancelled while
 * nothing is paid (AP_BILL_CANCELLED, the mirror). An approval limit for `ap_bill` routes approval through the approvals inbox; without one, one
 * checker other than the maker approves from the bill page.
 *
 * ASSUMPTION A-246: a bill posts on its bill date (the accounting date), not on the approval day; a bill dated in a locked month is refused at posting.
 * ASSUMPTION A-247: whoever approves bills cancels them (finance decides); cancelling a draft needs only the person who entered it.
 */
final class BillService
{
    public const ENTER = 'ap.enter_bills';

    public const APPROVE = 'ap.approve_bills';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SodGuard $sod,
        private readonly ApprovalService $approvals,
        private readonly DocumentNumberer $numbers,
        private readonly PayablesAccountingEvents $accounting,
        private readonly Audit $audit,
    ) {}

    /**
     * @param list<array{description: string, account_id: string, net_minor: int, vat_minor?: int|null, claim_id?: string|null, policy_id?: string|null}> $lines
     *
     * @throws BusinessRuleViolation SUPPLIER_NOT_PAYABLE | BILL_LINES_REQUIRED | INVALID_AMOUNT | INVALID_ACCOUNT | DUPLICATE_SUPPLIER_BILL | INVALID_DUE_DATE
     */
    public function create(string $branchId, string $supplierId, string $supplierReference, CarbonImmutable $billDate, ?CarbonImmutable $dueDate, ?string $description,
        array $lines, string $actorUserId): ApBill
    {
        $supplier = Supplier::query()->findOrFail($supplierId);
        $this->permissions->authorize($actorUserId, self::ENTER, AuthorizationScope::branch($supplier->entity_id, $branchId));
        if ($supplier->status === 'blocked') {
            throw new BusinessRuleViolation('SUPPLIER_NOT_PAYABLE', "Supplier {$supplier->code} is blocked.");
        }
        $dueDate ??= $billDate->addDays($supplier->payment_terms_days);
        if ($dueDate->lessThan($billDate)) {
            throw new BusinessRuleViolation('INVALID_DUE_DATE', 'The due date cannot be before the bill date.');
        }

        return DB::transaction(function () use ($supplier, $branchId, $supplierReference, $billDate, $dueDate, $description, $lines, $actorUserId): ApBill {
            $reference = trim($supplierReference);
            if (ApBill::query()->where('supplier_id', $supplier->id)->whereRaw('lower(supplier_reference) = lower(?)', [$reference])->where('status', '<>', BillStatus::Cancelled->value)->exists()) {
                throw new BusinessRuleViolation('DUPLICATE_SUPPLIER_BILL', "Supplier {$supplier->code} already has a bill with reference {$reference}.");
            }
            $currency = (string) (DB::table('legal_entities')->where('id', $supplier->entity_id)->value('base_currency') ?? config('erp.default_currency', 'KES'));
            $bill = ApBill::query()->create(['entity_id' => $supplier->entity_id, 'branch_id' => $branchId, 'supplier_id' => $supplier->id, 'supplier_reference' => $reference,
                'bill_date' => $billDate->toDateString(), 'due_date' => $dueDate->toDateString(), 'currency' => $currency, 'description' => $description,
                'status' => BillStatus::Draft->value, 'source_type' => 'manual', 'created_by' => $actorUserId]);
            $this->writeLines($bill, $supplier, $lines);
            $this->audit->record('ap_bill.created', AuditSubject::of('ap_bill', $bill->id), null, ['supplier_id' => $supplier->id, 'reference' => $reference, 'payable_minor' => $bill->payable_minor],
                null, self::ENTER, Actor::user($actorUserId));

            return $bill;
        });
    }

    /**
     * @param list<array{description: string, account_id: string, net_minor: int, vat_minor?: int|null, claim_id?: string|null, policy_id?: string|null}> $lines
     *
     * @throws BusinessRuleViolation BILL_NOT_DRAFT (and the create checks on lines)
     */
    public function updateDraft(string $billId, string $supplierReference, CarbonImmutable $billDate, CarbonImmutable $dueDate, ?string $description, array $lines, string $actorUserId): ApBill
    {
        $bill = ApBill::query()->findOrFail($billId);
        $this->permissions->authorize($actorUserId, self::ENTER, AuthorizationScope::branch($bill->entity_id, $bill->branch_id));

        return DB::transaction(function () use ($billId, $supplierReference, $billDate, $dueDate, $description, $lines, $actorUserId): ApBill {
            $bill = $this->lock($billId, [BillStatus::Draft]);
            $bill->forceFill(['supplier_reference' => trim($supplierReference), 'bill_date' => $billDate->toDateString(), 'due_date' => $dueDate->toDateString(), 'description' => $description])->save();
            ApBillLine::query()->where('bill_id', $bill->id)->delete();
            $this->writeLines($bill, Supplier::query()->findOrFail($bill->supplier_id), $lines);
            $this->audit->record('ap_bill.updated', AuditSubject::of('ap_bill', $bill->id), null, ['payable_minor' => $bill->payable_minor], null, self::ENTER, Actor::user($actorUserId));

            return $bill;
        });
    }

    /** Sends a draft for approval: numbers it (BIL-<branch>-<fy>-n) and requests an approval when a limit applies. */
    public function submit(string $billId, string $actorUserId): ApBill
    {
        $bill = ApBill::query()->findOrFail($billId);
        $this->permissions->authorize($actorUserId, self::ENTER, AuthorizationScope::branch($bill->entity_id, $bill->branch_id));
        $number = $bill->number === null ? $this->numbers->reserve(new DocumentNumberScope($bill->entity_id, $bill->branch_id, 'ap_bill', 'BIL', $bill->bill_date), $actorUserId) : null;

        return DB::transaction(function () use ($billId, $actorUserId, $number): ApBill {
            $bill = $this->lock($billId, [BillStatus::Draft]);
            if ($number !== null) {
                $this->numbers->markUsed($number->id, 'ap_bill', $bill->id);
            }
            $bill->forceFill(['status' => BillStatus::PendingApproval->value, 'submitted_by' => $actorUserId, 'number' => $bill->number ?? $number?->number])->save();
            $approvalId = $this->approvals->request('ap_bill', $bill->id, new ApprovalFacts($bill->payable_minor), $actorUserId, $bill->bill_date, ['number' => $bill->number]);
            $bill->forceFill(['approval_id' => $approvalId])->save();
            $this->audit->record('ap_bill.submitted', AuditSubject::of('ap_bill', $bill->id), ['status' => 'draft'], ['status' => 'pending_approval', 'number' => $bill->number,
                'payable_minor' => $bill->payable_minor], null, self::ENTER, Actor::user($actorUserId));

            return $bill;
        });
    }

    /**
     * Approves a bill waiting for approval. Through the approval engine when a limit applies (the last step posts); otherwise directly by a holder of
     * ap.approve_bills who did not enter or submit it.
     *
     * @throws SodViolation MAKER_CHECKER | SOD_CONFLICT
     */
    public function approve(string $billId, string $actorUserId): ApBill
    {
        $bill = ApBill::query()->findOrFail($billId);
        $pending = $this->approvals->pendingFor('ap_bill', $bill->id);
        if ($pending !== null) {
            $this->approvals->decide($pending, $actorUserId, Decision::Approved, null);

            return $bill->refresh();
        }
        $this->authorizeChecker($bill, $actorUserId);

        return DB::transaction(function () use ($billId, $actorUserId): ApBill {
            $this->completeApproval($billId, $actorUserId);

            return ApBill::query()->findOrFail($billId);
        });
    }

    /** Posts the bill (AP_BILL_POSTED on its bill date). Called directly or by BillApprovalHandler after the last approval step. */
    public function completeApproval(string $billId, string $approverId): void
    {
        $bill = $this->lock($billId, [BillStatus::PendingApproval]);
        $this->sod->assert($approverId, self::APPROVE, AuditSubject::of('ap_bill', $bill->id));
        $bill->forceFill(['status' => BillStatus::Posted->value, 'approved_by' => $approverId, 'approved_at' => CarbonImmutable::now(), 'accounting_date' => $bill->bill_date->toDateString()])->save();
        $this->accounting->billPosted($bill, Supplier::query()->findOrFail($bill->supplier_id));
        $this->audit->record('ap_bill.approved', AuditSubject::of('ap_bill', $bill->id), ['status' => 'pending_approval'], ['status' => 'posted', 'accounting_date' => $bill->bill_date->toDateString()],
            null, self::APPROVE, Actor::user($approverId));
    }

    /** @throws BusinessRuleViolation REASON_REQUIRED */
    public function reject(string $billId, string $reason, string $actorUserId): ApBill
    {
        self::assertReason($reason);
        $bill = ApBill::query()->findOrFail($billId);
        $pending = $this->approvals->pendingFor('ap_bill', $bill->id);
        if ($pending !== null) {
            $this->approvals->decide($pending, $actorUserId, Decision::Rejected, $reason);

            return $bill->refresh();
        }
        $this->authorizeChecker($bill, $actorUserId);

        return DB::transaction(function () use ($billId, $reason, $actorUserId): ApBill {
            $this->returnToDraft($billId, $actorUserId, $reason);

            return ApBill::query()->findOrFail($billId);
        });
    }

    /** A rejected bill goes back to its maker as a draft, keeping its number. Called directly or by BillApprovalHandler. */
    public function returnToDraft(string $billId, string $deciderId, string $reason): void
    {
        $bill = $this->lock($billId, [BillStatus::PendingApproval]);
        $bill->forceFill(['status' => BillStatus::Draft->value, 'approval_id' => null])->save();
        $this->audit->record('ap_bill.rejected', AuditSubject::of('ap_bill', $bill->id), ['status' => 'pending_approval'], ['status' => 'draft'], $reason, self::APPROVE, Actor::user($deciderId));
    }

    /**
     * Cancels a draft, or a posted bill nothing was paid on and no payment run holds (AP_BILL_CANCELLED on $on mirrors the posting).
     *
     * @throws BusinessRuleViolation REASON_REQUIRED | BILL_NOT_CANCELLABLE | BILL_IN_PAYMENT_RUN
     */
    public function cancel(string $billId, string $reason, CarbonImmutable $on, string $actorUserId): ApBill
    {
        self::assertReason($reason);
        $bill = ApBill::query()->findOrFail($billId);
        $scope = AuthorizationScope::branch($bill->entity_id, $bill->branch_id);
        $bill->status === BillStatus::Draft ? $this->permissions->authorizeAny($actorUserId, [self::ENTER, self::APPROVE], $scope) : $this->permissions->authorize($actorUserId, self::APPROVE, $scope);

        return DB::transaction(function () use ($billId, $reason, $on, $actorUserId): ApBill {
            $bill = ApBill::query()->whereKey($billId)->lockForUpdate()->firstOrFail();
            $posted = $bill->status === BillStatus::Posted;
            if (! $posted && $bill->status !== BillStatus::Draft || $bill->paid_minor > 0) {
                throw new BusinessRuleViolation('BILL_NOT_CANCELLABLE', "Bill {$bill->number} is {$bill->status->value}; only a draft or an unpaid posted bill can be cancelled.");
            }
            if (DB::table('payment_run_items')->where('payable_type', 'ap_bill')->where('payable_id', $bill->id)->where('status', 'included')->exists()) {
                throw new BusinessRuleViolation('BILL_IN_PAYMENT_RUN', "Bill {$bill->number} is in a payment run; remove it from the run or cancel the run first.");
            }
            $before = $bill->status->value;
            $bill->forceFill(['status' => BillStatus::Cancelled->value, 'cancelled_by' => $actorUserId, 'cancelled_on' => $on->toDateString(), 'cancelled_reason' => $reason])->save();
            if ($posted) {
                $this->accounting->billCancelled($bill, Supplier::query()->findOrFail($bill->supplier_id), $on);
            }
            $this->audit->record('ap_bill.cancelled', AuditSubject::of('ap_bill', $bill->id), ['status' => $before], ['status' => 'cancelled', 'cancelled_on' => $on->toDateString()],
                $reason, $posted ? self::APPROVE : self::ENTER, Actor::user($actorUserId));

            return $bill;
        });
    }

    /**
     * @param list<array{description: string, account_id: string, net_minor: int, vat_minor?: int|null, claim_id?: string|null, policy_id?: string|null}> $lines
     */
    private function writeLines(ApBill $bill, Supplier $supplier, array $lines): void
    {
        if ($lines === []) {
            throw new BusinessRuleViolation('BILL_LINES_REQUIRED', 'A bill needs at least one line.');
        }
        $rates = SupplierService::rates($supplier->category);
        $totals = ['net_minor' => 0, 'vat_minor' => 0, 'vds_minor' => 0, 'tds_minor' => 0];
        foreach ($lines as $index => $line) {
            if ($line['net_minor'] <= 0 || ($line['vat_minor'] ?? 0) < 0) {
                throw new BusinessRuleViolation('INVALID_AMOUNT', 'Each bill line needs a positive amount and a VAT of zero or more.');
            }
            if (! DB::table('accounts')->where('id', $line['account_id'])->where('entity_id', $bill->entity_id)->where('is_postable', true)->where('status', 'active')->where('is_control', false)->exists()) {
                throw new BusinessRuleViolation('INVALID_ACCOUNT', 'Choose an active account of this entity that takes postings and is not a control account for each line.');
            }
            $taxes = Withholding::forLine($line['net_minor'], $line['vat_minor'] ?? null, $rates['vat_bp'], $rates['vds_bp'], $rates['tds_bp']);
            ApBillLine::query()->create(['bill_id' => $bill->id, 'line_no' => $index + 1, 'description' => trim($line['description']) === '' ? 'Bill line' : trim($line['description']),
                'account_id' => $line['account_id'], 'claim_id' => ($line['claim_id'] ?? '') === '' ? null : $line['claim_id'], 'policy_id' => ($line['policy_id'] ?? '') === '' ? null : $line['policy_id'],
                'net_minor' => $line['net_minor'], 'vat_minor' => $taxes->vatMinor, 'vds_minor' => $taxes->vdsMinor, 'tds_minor' => $taxes->tdsMinor]);
            $totals['net_minor'] += $line['net_minor'];
            $totals['vat_minor'] += $taxes->vatMinor;
            $totals['vds_minor'] += $taxes->vdsMinor;
            $totals['tds_minor'] += $taxes->tdsMinor;
        }
        $gross = $totals['net_minor'] + $totals['vat_minor'];
        $bill->forceFill([...$totals, 'gross_minor' => $gross, 'payable_minor' => $gross - $totals['vds_minor'] - $totals['tds_minor']])->save();
    }

    private function authorizeChecker(ApBill $bill, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, self::APPROVE, AuthorizationScope::branch($bill->entity_id, $bill->branch_id));
        if ($actorUserId === $bill->created_by || $actorUserId === $bill->submitted_by) {
            throw new SodViolation('SOD_CONFLICT', $actorUserId, self::APPROVE, self::ENTER, 'MAKER_CHECKER', "The person who entered bill {$bill->number} cannot approve it.");
        }
        $this->sod->assert($actorUserId, self::APPROVE, AuditSubject::of('ap_bill', $bill->id));
    }

    /** @param list<BillStatus> $allowed */
    private function lock(string $billId, array $allowed): ApBill
    {
        $bill = ApBill::query()->whereKey($billId)->lockForUpdate()->firstOrFail();
        if (! in_array($bill->status, $allowed, true)) {
            throw new BusinessRuleViolation('INVALID_BILL_TRANSITION', "Bill {$bill->number} is {$bill->status->value}.");
        }

        return $bill;
    }

    private static function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new BusinessRuleViolation('REASON_REQUIRED', 'Give a reason.');
        }
    }
}
