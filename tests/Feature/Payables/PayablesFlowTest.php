<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Finance\Bank\Application\BankAccountService;
use App\Modules\Finance\Payables\Application\BankPaymentFile;
use App\Modules\Finance\Payables\Application\BillService;
use App\Modules\Finance\Payables\Application\PayablesReconciler;
use App\Modules\Finance\Payables\Application\PaymentRunService;
use App\Modules\Finance\Payables\Application\SupplierService;
use App\Modules\Platform\Authorization\SodViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Slices 2.3/2.4 accounts payable (addendum v2 §B.4): a supplier bill entered by the accountant, approved by someone else (posting AP_BILL_POSTED with
 * VAT and taxes deducted at source), paid by a payment run prepared, approved and released by three people (AP_PAYMENT_RELEASED), the bank file, and the AP
 * subledger reconciling to accounts payable.
 */
beforeEach(function (): void {
    Queue::fake();
    $this->ctx = seedDemoTenant();
    $t = $this->ctx['tenant_id'];
    $this->accountant = userWithPermissions($t, ['ap.manage_suppliers', 'ap.enter_bills', 'ap.prepare_payments', 'bank.manage_accounts']);
    $this->manager = userWithPermissions($t, ['ap.approve_bills', 'ap.approve_payments', 'ap.release_payments']);
    $this->cfo = userWithPermissions($t, ['ap.approve_bills', 'ap.approve_payments', 'ap.release_payments']);
    $this->post = fn () => asTenant($t, function (): void {
        foreach (DB::table('accounting_events')->where('status', 'queued')->orderBy('created_at')->pluck('id') as $id) {
            app(PostingEngine::class)->post((string) $id);
        }
    });
});

it('enters, approves and pays an office rent bill with VDS and TDS, posting the journals and reconciling AP', function (): void {
    $ctx = $this->ctx;
    $d = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);

    [$bill, $supplierParty, $bankAccountId] = asTenant($ctx['tenant_id'], function () use ($ctx, $d): array {
        $supplier = app(SupplierService::class)->create($ctx['entity_id'], ['code' => 'SUP-RENT', 'name' => 'Gulshan Properties Ltd', 'category' => 'rent', 'payment_terms_days' => 10,
            'tin' => '123456789012', 'bank_name' => 'Dutch-Bangla Bank', 'bank_branch' => 'Gulshan', 'routing_no' => '090261726', 'account_name' => 'Gulshan Properties Ltd',
            'account_no' => '1051100012345'], $this->accountant);
        $bank = app(BankAccountService::class)->create($ctx['entity_id'], $ctx['accounts']['bank_main'], 'City Bank', '****4471', 'BDT', $this->accountant);
        $bills = app(BillService::class);
        $bill = $bills->create($ctx['branch_id'], $supplier->id, 'GP/RENT/09', $d('2026-09-01'), null, 'September office rent',
            [['description' => 'Office rent, Gulshan Avenue', 'account_id' => $ctx['accounts']['ap_expense'], 'net_minor' => 85_000_00]], $this->accountant);
        $bill = $bills->submit($bill->id, $this->accountant);
        expect(fn () => $bills->approve($bill->id, $this->accountant))->toThrow(App\Modules\Platform\Authorization\PermissionDenied::class);
        $bill = $bills->approve($bill->id, $this->manager);

        return [$bill, $supplier->party_id, $bank->id];
    });

    // Rent (A-243 placeholders): VAT 15% 12,750 expensed, all of it deducted at source; TDS 5% 4,250. Payable to the landlord 80,750.
    expect($bill->status->value)->toBe('posted')->and($bill->number)->toStartWith('BIL-HO-')
        ->and([$bill->net_minor, $bill->vat_minor, $bill->vds_minor, $bill->tds_minor, $bill->payable_minor])->toBe([85_000_00, 12_750_00, 12_750_00, 4_250_00, 80_750_00]);
    ($this->post)();
    $lines = asTenant($ctx['tenant_id'], fn (): array => DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
        ->where('j.source_type', 'ap_bill')->where('j.source_id', $bill->id)->orderBy('l.line_no')->get(['l.role_code', 'l.side', 'l.amount_minor', 'l.dims_ext'])
        ->map(fn (object $l): array => [$l->role_code, $l->side, (int) $l->amount_minor])->all());
    expect($lines)->toBe([['ap_expense', 'debit', 97_750_00], ['accounts_payable', 'credit', 80_750_00], ['vat_deducted_at_source_payable', 'credit', 12_750_00],
        ['supplier_tax_withheld_payable', 'credit', 4_250_00]]);

    $run = asTenant($ctx['tenant_id'], function () use ($ctx, $d, $bill, $bankAccountId) {
        $runs = app(PaymentRunService::class);
        expect(array_column($runs->dueBills($ctx['entity_id'], $d('2026-09-30')), 'id'))->toBe([$bill->id]);
        $run = $runs->create($ctx['entity_id'], $bankAccountId, $d('2026-09-10'), [$bill->id], $this->accountant);
        $runs->submit($run->id, $this->accountant);
        $runs->approve($run->id, $this->manager);
        expect(fn () => $runs->release($run->id, $this->manager))->toThrow(SodViolation::class);

        return $runs->release($run->id, $this->cfo);
    });
    ($this->post)();

    asTenant($ctx['tenant_id'], function () use ($run, $bill, $supplierParty): void {
        expect($run->status->value)->toBe('released')->and($run->number)->toStartWith('PRN-')
            ->and(DB::table('ap_bills')->where('id', $bill->id)->value('status'))->toBe('paid');
        $payment = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.source_type', 'payment_run')->where('j.source_id', $run->id)
            ->orderBy('l.line_no')->get(['l.role_code', 'l.side', 'l.amount_minor', 'l.account_id', 'l.dims_ext']);
        expect($payment->map(fn (object $l): array => [$l->role_code, $l->side, (int) $l->amount_minor])->all())->toBe([['accounts_payable', 'debit', 80_750_00], ['bank_main', 'credit', 80_750_00]])
            ->and(json_decode((string) $payment->first()?->dims_ext, true)['payee_party'])->toBe($supplierParty);

        $file = app(BankPaymentFile::class)->generate($run->id, $this->cfo);
        expect($file['contents'])->toContain('"Gulshan Properties Ltd","Dutch-Bangla Bank",Gulshan,090261726,1051100012345,80750.00');

        $september = (string) DB::table('fiscal_periods')->where('starts', '2026-09-01')->value('id');
        $runId = app(ReconciliationService::class)->run(app(PayablesReconciler::class), $september);
        expect(DB::table('reconciliation_runs')->where('id', $runId)->value('status'))->toBe('clean');
    });
});
