<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Application;

use App\Modules\Finance\Bank\Application\BankAccountQuery;
use App\Modules\Finance\Bank\Domain\Models\BankAccount;
use App\Modules\Finance\Payables\Domain\Enums\BillStatus;
use App\Modules\Finance\Payables\Domain\Enums\PaymentRunStatus;
use App\Modules\Finance\Payables\Domain\Models\ApBill;
use App\Modules\Finance\Payables\Domain\Models\PaymentRun;
use App\Modules\Finance\Payables\Domain\Models\PaymentRunItem;
use App\Modules\Finance\Payables\Domain\Models\Supplier;
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
 * Payment runs (addendum v2 §B.4, slice 2.4): the accountant picks due bills to pay from one bank account on a pay date (`ap.prepare_payments`), someone
 * else approves (`ap.approve_payments`), and a third person releases (`ap.release_payments`) — releasing posts AP_PAYMENT_RELEASED (DR accounts payable
 * per bill, CR the bank) and marks the bills paid; the bank payment file is downloaded from the released run. SoD object rules on the run:
 * prepare ✕ approve, approve ✕ release, prepare ✕ release.
 *
 * INVARIANTS: a bill is in at most one run waiting for release (unique index); a blocked or on-hold supplier's bills, or a supplier without a bank
 * account, are not added; each item keeps the supplier's bank account as it was when added; Σ items = run total = event credit = bank file total.
 */
final class PaymentRunService
{
    public const PREPARE = 'ap.prepare_payments';

    public const APPROVE = 'ap.approve_payments';

    public const RELEASE = 'ap.release_payments';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SodGuard $sod,
        private readonly ApprovalService $approvals,
        private readonly DocumentNumberer $numbers,
        private readonly BankAccountQuery $bankAccounts,
        private readonly PayablesAccountingEvents $accounting,
        private readonly Audit $audit,
    ) {}

    /**
     * Open bills due on or before $dueBy (optionally of one supplier) that can be paid now: supplier active with a bank account, not already in a run.
     *
     * @return list<array{id: string, number: string|null, supplier_id: string, supplier: string, supplier_reference: string, due_date: string, outstanding_minor: int, branch_id: string}>
     */
    public function dueBills(string $entityId, CarbonImmutable $dueBy, ?string $supplierId = null): array
    {
        return array_values(DB::table('ap_bills as b')->join('suppliers as s', 's.id', '=', 'b.supplier_id')->join('parties as p', 'p.id', '=', 's.party_id')
            ->where('b.entity_id', $entityId)->whereIn('b.status', BillStatus::open())->whereColumn('b.paid_minor', '<', 'b.payable_minor')
            ->where('b.due_date', '<=', $dueBy->toDateString())->where('s.status', 'active')->whereNotNull('s.account_no_enc')
            ->when($supplierId !== null && $supplierId !== '', fn ($q) => $q->where('b.supplier_id', $supplierId))
            ->whereNotExists(fn ($q) => $q->from('payment_run_items as i')->whereColumn('i.payable_id', 'b.id')->where('i.payable_type', 'ap_bill')->where('i.status', 'included'))
            ->orderBy('b.due_date')->orderBy('b.number')
            ->get(['b.id', 'b.number', 'b.supplier_id', 'p.display_name', 'b.supplier_reference', 'b.due_date', 'b.payable_minor', 'b.paid_minor', 'b.branch_id'])
            ->map(fn (object $b): array => ['id' => (string) $b->id, 'number' => $b->number === null ? null : (string) $b->number, 'supplier_id' => (string) $b->supplier_id,
                'supplier' => (string) $b->display_name, 'supplier_reference' => (string) $b->supplier_reference, 'due_date' => (string) $b->due_date,
                'outstanding_minor' => (int) $b->payable_minor - (int) $b->paid_minor, 'branch_id' => (string) $b->branch_id])->all());
    }

    /**
     * @param list<string> $billIds
     *
     * @throws BusinessRuleViolation PAYMENT_RUN_EMPTY | INVALID_BANK_ACCOUNT | BILL_NOT_PAYABLE | SUPPLIER_NOT_PAYABLE | SUPPLIER_BANK_ACCOUNT_MISSING | BILL_IN_PAYMENT_RUN
     */
    public function create(string $entityId, string $bankAccountId, CarbonImmutable $payDate, array $billIds, string $actorUserId): PaymentRun
    {
        $this->permissions->authorize($actorUserId, self::PREPARE, AuthorizationScope::entity($entityId));
        if ($billIds === []) {
            throw new BusinessRuleViolation('PAYMENT_RUN_EMPTY', 'Choose at least one bill to pay.');
        }
        $bankAccount = BankAccount::query()->find($bankAccountId);
        if ($bankAccount === null) {
            throw new BusinessRuleViolation('INVALID_BANK_ACCOUNT', "Bank account {$bankAccountId} is not an active account of this entity.");
        }
        $currency = (string) $bankAccount->currency;
        $this->bankAccounts->glAccountFor($bankAccountId, $entityId, $currency);
        $number = $this->numbers->reserve(new DocumentNumberScope($entityId, null, 'payment_run', 'PRN', $payDate), $actorUserId);

        return DB::transaction(function () use ($entityId, $bankAccountId, $payDate, $billIds, $actorUserId, $number, $currency): PaymentRun {
            $run = PaymentRun::query()->create(['entity_id' => $entityId, 'number' => $number->number, 'bank_account_id' => $bankAccountId, 'pay_date' => $payDate->toDateString(),
                'currency' => $currency, 'status' => PaymentRunStatus::Draft->value, 'created_by' => $actorUserId]);
            $this->numbers->markUsed($number->id, 'payment_run', $run->id);
            $total = 0;
            foreach (array_values(array_unique($billIds)) as $billId) {
                $total += $this->addItem($run, $billId)->amount_minor;
            }
            $run->forceFill(['total_minor' => $total, 'item_count' => count(array_unique($billIds))])->save();
            $this->audit->record('payment_run.created', AuditSubject::of('payment_run', $run->id), null, ['number' => $run->number, 'total_minor' => $total, 'items' => $run->item_count,
                'pay_date' => $payDate->toDateString()], null, self::PREPARE, Actor::user($actorUserId));

            return $run;
        });
    }

    public function submit(string $runId, string $actorUserId): PaymentRun
    {
        $run = PaymentRun::query()->findOrFail($runId);
        $this->permissions->authorize($actorUserId, self::PREPARE, AuthorizationScope::entity($run->entity_id));

        return DB::transaction(function () use ($runId, $actorUserId): PaymentRun {
            $run = $this->lock($runId, [PaymentRunStatus::Draft]);
            $run->forceFill(['status' => PaymentRunStatus::PendingApproval->value, 'submitted_by' => $actorUserId])->save();
            $approvalId = $this->approvals->request('payment_run', $run->id, new ApprovalFacts($run->total_minor), $actorUserId, $run->pay_date, ['number' => $run->number]);
            $run->forceFill(['approval_id' => $approvalId])->save();
            $this->audit->record('payment_run.submitted', AuditSubject::of('payment_run', $run->id), ['status' => 'draft'], ['status' => 'pending_approval', 'total_minor' => $run->total_minor],
                null, self::PREPARE, Actor::user($actorUserId));

            return $run;
        });
    }

    /** @throws SodViolation MAKER_CHECKER | SOD_CONFLICT */
    public function approve(string $runId, string $actorUserId): PaymentRun
    {
        $run = PaymentRun::query()->findOrFail($runId);
        $pending = $this->approvals->pendingFor('payment_run', $run->id);
        if ($pending !== null) {
            $this->approvals->decide($pending, $actorUserId, Decision::Approved, null);

            return $run->refresh();
        }
        $this->authorizeApprover($run, $actorUserId);

        return DB::transaction(function () use ($runId, $actorUserId): PaymentRun {
            $this->completeApproval($runId, $actorUserId);

            return PaymentRun::query()->findOrFail($runId);
        });
    }

    /** Called directly or by PaymentRunApprovalHandler after the last approval step. */
    public function completeApproval(string $runId, string $approverId): void
    {
        $run = $this->lock($runId, [PaymentRunStatus::PendingApproval]);
        $this->sod->assert($approverId, self::APPROVE, AuditSubject::of('payment_run', $run->id));
        $run->forceFill(['status' => PaymentRunStatus::Approved->value, 'approved_by' => $approverId, 'approved_at' => CarbonImmutable::now()])->save();
        $this->audit->record('payment_run.approved', AuditSubject::of('payment_run', $run->id), ['status' => 'pending_approval'], ['status' => 'approved'], null, self::APPROVE, Actor::user($approverId));
    }

    /** @throws BusinessRuleViolation REASON_REQUIRED */
    public function reject(string $runId, string $reason, string $actorUserId): PaymentRun
    {
        self::assertReason($reason);
        $run = PaymentRun::query()->findOrFail($runId);
        $pending = $this->approvals->pendingFor('payment_run', $run->id);
        if ($pending !== null) {
            $this->approvals->decide($pending, $actorUserId, Decision::Rejected, $reason);

            return $run->refresh();
        }
        $this->authorizeApprover($run, $actorUserId);

        return DB::transaction(function () use ($runId, $reason, $actorUserId): PaymentRun {
            $this->returnToDraft($runId, $actorUserId, $reason);

            return PaymentRun::query()->findOrFail($runId);
        });
    }

    public function returnToDraft(string $runId, string $deciderId, string $reason): void
    {
        $run = $this->lock($runId, [PaymentRunStatus::PendingApproval]);
        $run->forceFill(['status' => PaymentRunStatus::Draft->value, 'approval_id' => null])->save();
        $this->audit->record('payment_run.rejected', AuditSubject::of('payment_run', $run->id), ['status' => 'pending_approval'], ['status' => 'draft'], $reason, self::APPROVE, Actor::user($deciderId));
    }

    /**
     * Releases an approved run: posts AP_PAYMENT_RELEASED on the pay date and marks each bill (partially) paid.
     *
     * @throws SodViolation SOD_CONFLICT (the preparer or approver of the run)
     */
    public function release(string $runId, string $actorUserId): PaymentRun
    {
        $run = PaymentRun::query()->findOrFail($runId);
        $this->permissions->authorize($actorUserId, self::RELEASE, AuthorizationScope::entity($run->entity_id));
        if ($actorUserId === $run->created_by || $actorUserId === $run->approved_by) {
            throw new SodViolation('SOD_CONFLICT', $actorUserId, self::RELEASE, $actorUserId === $run->created_by ? self::PREPARE : self::APPROVE, 'MAKER_CHECKER',
                "Payment run {$run->number} is released by someone who neither prepared nor approved it.");
        }
        $this->sod->assert($actorUserId, self::RELEASE, AuditSubject::of('payment_run', $run->id));

        return DB::transaction(function () use ($runId, $actorUserId): PaymentRun {
            $run = $this->lock($runId, [PaymentRunStatus::Approved]);
            $items = PaymentRunItem::query()->where('run_id', $run->id)->where('status', 'included')->orderBy('created_at')->orderBy('id')->get();
            foreach ($items as $item) {
                $bill = ApBill::query()->whereKey($item->payable_id)->lockForUpdate()->firstOrFail();
                $paid = $bill->paid_minor + $item->amount_minor;
                $bill->forceFill(['paid_minor' => $paid, 'status' => $paid >= $bill->payable_minor ? BillStatus::Paid->value : BillStatus::PartiallyPaid->value])->save();
                $item->forceFill(['status' => 'released'])->save();
                $this->audit->record('ap_bill.paid', AuditSubject::of('ap_bill', $bill->id), null, ['payment_run_id' => $run->id, 'number' => $run->number, 'amount_minor' => $item->amount_minor,
                    'pay_date' => $run->pay_date->toDateString()], null, self::RELEASE, Actor::user($actorUserId));
            }
            $run->forceFill(['status' => PaymentRunStatus::Released->value, 'released_by' => $actorUserId, 'released_at' => CarbonImmutable::now()])->save();
            $this->accounting->paymentReleased($run, $items);
            $this->audit->record('payment_run.released', AuditSubject::of('payment_run', $run->id), ['status' => 'approved'], ['status' => 'released', 'total_minor' => $run->total_minor],
                null, self::RELEASE, Actor::user($actorUserId));

            return $run;
        });
    }

    /** @throws BusinessRuleViolation REASON_REQUIRED | INVALID_PAYMENT_RUN_TRANSITION */
    public function cancel(string $runId, string $reason, string $actorUserId): PaymentRun
    {
        self::assertReason($reason);
        $run = PaymentRun::query()->findOrFail($runId);
        $this->permissions->authorizeAny($actorUserId, [self::PREPARE, self::APPROVE], AuthorizationScope::entity($run->entity_id));

        return DB::transaction(function () use ($runId, $reason, $actorUserId): PaymentRun {
            $run = $this->lock($runId, [PaymentRunStatus::Draft, PaymentRunStatus::PendingApproval, PaymentRunStatus::Approved]);
            $before = $run->status->value;
            PaymentRunItem::query()->where('run_id', $run->id)->where('status', 'included')->update(['status' => 'removed']);
            DB::table('approvals')->where('object_type', 'payment_run')->where('object_id', $run->id)->where('status', 'pending')->update(['status' => 'rejected', 'decided_at' => CarbonImmutable::now()]);
            $run->forceFill(['status' => PaymentRunStatus::Cancelled->value, 'cancelled_reason' => $reason])->save();
            $this->audit->record('payment_run.cancelled', AuditSubject::of('payment_run', $run->id), ['status' => $before], ['status' => 'cancelled'], $reason,
                $this->permissions->has($actorUserId, self::PREPARE) ? self::PREPARE : self::APPROVE, Actor::user($actorUserId));

            return $run;
        });
    }

    private function addItem(PaymentRun $run, string $billId): PaymentRunItem
    {
        $bill = ApBill::query()->whereKey($billId)->where('entity_id', $run->entity_id)->lockForUpdate()->first();
        if ($bill === null || ! in_array($bill->status->value, BillStatus::open(), true) || $bill->outstandingMinor() <= 0) {
            throw new BusinessRuleViolation('BILL_NOT_PAYABLE', 'Only posted bills with an amount still to pay can be added to a payment run.');
        }
        $supplier = Supplier::query()->findOrFail($bill->supplier_id);
        if ($supplier->status !== 'active') {
            throw new BusinessRuleViolation('SUPPLIER_NOT_PAYABLE', "Supplier {$supplier->code} is {$supplier->status}; its bills cannot be paid.");
        }
        if ($supplier->account_no_enc === null || $supplier->account_no_enc === '') {
            throw new BusinessRuleViolation('SUPPLIER_BANK_ACCOUNT_MISSING', "Supplier {$supplier->code} has no bank account to pay into.");
        }
        if (PaymentRunItem::query()->where('payable_type', 'ap_bill')->where('payable_id', $bill->id)->where('status', 'included')->exists()) {
            throw new BusinessRuleViolation('BILL_IN_PAYMENT_RUN', "Bill {$bill->number} is already in a payment run.");
        }

        return PaymentRunItem::query()->create(['run_id' => $run->id, 'payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'supplier_id' => $supplier->id,
            'payee_party_id' => $supplier->party_id, 'branch_id' => $bill->branch_id, 'amount_minor' => $bill->outstandingMinor(), 'status' => 'included',
            'bank_account_snapshot' => ['bank_name' => $supplier->bank_name, 'bank_branch' => $supplier->bank_branch, 'routing_no' => $supplier->routing_no,
                'account_name' => $supplier->account_name, 'account_no_masked' => $supplier->account_no_masked, 'account_no_enc' => encrypt($supplier->account_no_enc)]]);
    }

    private function authorizeApprover(PaymentRun $run, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, self::APPROVE, AuthorizationScope::entity($run->entity_id));
        if ($actorUserId === $run->created_by || $actorUserId === $run->submitted_by) {
            throw new SodViolation('SOD_CONFLICT', $actorUserId, self::APPROVE, self::PREPARE, 'MAKER_CHECKER', "The person who prepared payment run {$run->number} cannot approve it.");
        }
        $this->sod->assert($actorUserId, self::APPROVE, AuditSubject::of('payment_run', $run->id));
    }

    /** @param list<PaymentRunStatus> $allowed */
    private function lock(string $runId, array $allowed): PaymentRun
    {
        $run = PaymentRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
        if (! in_array($run->status, $allowed, true)) {
            throw new BusinessRuleViolation('INVALID_PAYMENT_RUN_TRANSITION', "Payment run {$run->number} is {$run->status->value}.");
        }

        return $run;
    }

    private static function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new BusinessRuleViolation('REASON_REQUIRED', 'Give a reason.');
        }
    }
}
