<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Close\PeriodCloseService;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Accounting\Application\Reversals\ReversalRequestService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Distribution\Application\Advances\AdvanceService;
use App\Modules\Distribution\Application\Compensation\CompensationEngine;
use App\Modules\Distribution\Application\Compensation\CompensationRequest;
use App\Modules\Distribution\Application\Compensation\CompensationRuleRequest;
use App\Modules\Distribution\Application\Compensation\CompensationSchemeService;
use App\Modules\Distribution\Application\Hierarchy\HierarchyService;
use App\Modules\Distribution\Application\Incentives\IncentivePlanService;
use App\Modules\Distribution\Application\Portal\ProducerPortalAccess;
use App\Modules\Distribution\Application\Targets\TargetService;
use App\Modules\Insurance\Commission\Application\IncentiveRun;
use App\Modules\Distribution\Application\Licences\LicenceExpiryAlerts;
use App\Modules\Finance\Bank\Application\BankAccountService;
use App\Modules\Finance\Bank\Application\BankMatcher;
use App\Modules\Finance\Bank\Application\StatementImport;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Collections\Application\AgentDepositService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ChequeDetails;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Collections\Application\RefundService;
use App\Modules\Insurance\Commission\Application\CommissionPayoutService;
use App\Modules\Insurance\Commission\Application\CommissionPlanService;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Policy\Application\Dunning\DunningRun;
use App\Modules\Insurance\Policy\Application\PayerShare;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\PremiumEarning\PremiumEarningRun;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Approvals\ApprovalService;
use App\Modules\Platform\Approvals\Decision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Review finding (medium) — design §8.6.7 / CONTEXT.md #6 behaviourally for every tenant table, not a sample: two tenants run the same Phase 1
 * business through the application, then the runtime role in tenant A sees none of tenant B's rows in any table carrying tenant_id, and nothing
 * without a tenant. A new tenant table must be populated here (or be named in the list below with a reason), so it cannot escape the check.
 */
const TABLES_WITHOUT_SCENARIO_ROWS = [];

/**
 * Runs Phase 1 business in the tenant so that every tenant table holds rows.
 *
 * @param array{tenant_id: string, entity_id: string, branch_id: string, book_id: string, accounts: array<string, string>} $ctx
 */
function populateEveryTenantTable(array $ctx): void
{
    $maker = userWithPermissions($ctx['tenant_id'], ['accounting.create_manual_journal', 'accounting.post_to_control', 'accounting.reverse_journal']);
    $checker = userWithPermissions($ctx['tenant_id'], ['accounting.approve_journal', 'accounting.post_to_control']);
    $planner = userWithPermissions($ctx['tenant_id'], ['commission.manage_plans']);
    $approver = userWithPermissions($ctx['tenant_id'], ['commission.approve', 'claim.approve', 'claim.pay_request', 'claim.close']);
    $payer = userWithPermissions($ctx['tenant_id'], ['commission.pay', 'claim.pay_release', 'receipt.refund_release', 'periods.lock']);
    $officer = userWithPermissions($ctx['tenant_id'], ['claim.register', 'claim.reserve', 'receipt.refund_request']);
    // Slices 2.3/2.4 accounts payable: a clerk enters and prepares, an approver approves, a third person releases.
    $ap = [userWithPermissions($ctx['tenant_id'], ['ap.manage_suppliers', 'ap.enter_bills', 'ap.prepare_payments']),
        userWithPermissions($ctx['tenant_id'], ['ap.approve_bills', 'ap.approve_payments']), userWithPermissions($ctx['tenant_id'], ['ap.release_payments'])];
    $planId = asTenant($ctx['tenant_id'], fn (): string => app(CommissionPlanService::class)->create('P10', 'Plan', 1000, null, null, $planner)->id);
    $world = seedInsuranceWorld($ctx, 'monthly', true, $planId);
    approvalPolicy($ctx['tenant_id'], 'claim_payment', ['min_amount_minor' => 1_000_000], [['permission' => 'periods.lock']]);

    asTenant($ctx['tenant_id'], function () use ($ctx, $world, $maker, $checker, $approver, $payer, $officer, $ap): void {
        $d = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);
        DB::table('dimension_requirements')->insert(['tenant_id' => $ctx['tenant_id'], 'event_type' => 'PREMIUM_RECEIVED', 'dimension_code' => 'branch']);
        app(PartyService::class)->addBankAccount($world['policyholder_id'], 'City Bank', '0012345678', true, $world['admin']);
        app(App\Modules\Platform\Preferences\UserPreferences::class)->set($world['admin'], 'theme', 'dark');
        app(App\Modules\Insurance\Product\Application\ProductCatalogue::class)->addCoverage($world['product_version_id'], // Phase 3 R1 coverages
            ['code' => 'own_damage', 'name_en' => 'Own damage', 'name_bn' => 'নিজস্ব ক্ষতি', 'basis' => 'sum_insured', 'mandatory' => true], $world['admin']);

        $lifecycle = app(PolicyLifecycle::class);
        $policy = $lifecycle->quote(new QuoteRequest($ctx['entity_id'], $ctx['branch_id'], $world['product_id'], $world['policyholder_id'], $world['agent_id'],
            $d('2026-07-01'), 12_000_000, 'BDT', 3, [new PayerShare($world['policyholder_id'], 10_000)]), $world['admin']);
        $lifecycle->issue($policy->id, $d('2026-07-01'), $world['admin']);
        $installments = DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->pluck('id')->map(fn ($id): string => (string) $id)->all();
        app(PremiumEarningRun::class)->run((string) DB::table('fiscal_periods')->where('starts', '2026-07-01')->value('id'));
        app(DunningRun::class)->run($ctx['entity_id'], $d('2026-08-15'));
        app(App\Modules\Platform\Jobs\JobRunLog::class)->record('dunning', fn (): int => 0, $ctx['entity_id']); // gap fix GA-05: job_runs

        $receipts = app(ReceiptService::class);
        $cheque = $receipts->record(new RecordReceiptRequest($ctx['entity_id'], $ctx['branch_id'], null, 'cheque', 4_500_000, 'BDT', $d('2026-09-02'), null, 'chq',
            [new AllocationLine($installments[0], 4_000_000)], new ChequeDetails('000111', 'Sonali Bank', $d('2026-09-01'))), $world['admin']);
        // Gap fix GA-14: the cheque waits in clearing; clearing it puts the whole cheque into the bank, where the statement line matches it.
        app(App\Modules\Insurance\Collections\Application\ChequeClearingService::class)->clear($cheque->id, $world['admin'], $d('2026-09-02'));
        $receipts->record(new RecordReceiptRequest($ctx['entity_id'], $ctx['branch_id'], null, 'cash', 4_000_000, 'BDT', $d('2026-09-03'), null, 'agent',
            [new AllocationLine($installments[1], 4_000_000)], null, $world['agent_id']), $world['admin']);
        app(AgentDepositService::class)->record($world['agent_id'], 4_000_000, null, 'slip', $world['admin'], $d('2026-09-04'));

        $gl = (string) DB::table('accounts')->where('code', '1010')->value('id');
        $bankAccount = app(BankAccountService::class)->create($ctx['entity_id'], $gl, 'Main', '****1', 'BDT', $world['admin']);
        app(StatementImport::class)->import($bankAccount->id, "date,description,reference,amount\n2026-09-02,chq,,45000.00\n", 's.csv', $world['admin']);
        $bankLine = (string) DB::table('journal_lines')->where('account_id', $gl)->where('side', 'debit')->orderBy('id')->value('id');
        $bankLines = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('l.account_id', $gl)->where('l.side', 'debit')
            ->whereIn('j.source_id', DB::table('receipt_allocations')->where('receipt_id', $cheque->id)->pluck('id')->push($cheque->id))->pluck('l.id')->map(fn ($id): string => (string) $id)->all();
        app(BankMatcher::class)->match((string) DB::table('bank_statement_lines')->value('id'), $bankLines === [] ? [$bankLine] : array_values($bankLines), $world['admin']);

        // Slices 2.3/2.4: suppliers, ap_bills, ap_bill_lines, payment_runs, payment_run_items, bank_payment_files.
        $supplier = app(App\Modules\Finance\Payables\Application\SupplierService::class)->create($ctx['entity_id'], ['code' => 'SUP-ISO', 'name' => 'Isolation Supplier', 'category' => 'supplies',
            'bank_name' => 'Sonali Bank', 'routing_no' => '200270003', 'account_name' => 'Isolation Supplier', 'account_no' => '0001234567'], $ap[0]);
        $apBills = app(App\Modules\Finance\Payables\Application\BillService::class);
        $apBill = $apBills->create($ctx['branch_id'], $supplier->id, 'INV-ISO', $d('2026-09-03'), $d('2026-09-10'), null, [['description' => 'Stationery', 'account_id' => $ctx['accounts']['ap_expense'], 'net_minor' => 100_000]], $ap[0]);
        $apBills->submit($apBill->id, $ap[0]);
        $apBills->approve($apBill->id, $ap[1]);
        $apRuns = app(App\Modules\Finance\Payables\Application\PaymentRunService::class);
        $apRun = $apRuns->create($ctx['entity_id'], $bankAccount->id, $d('2026-09-12'), [$apBill->id], $ap[0]);
        $apRuns->submit($apRun->id, $ap[0]);
        $apRuns->approve($apRun->id, $ap[1]);
        $apRuns->release($apRun->id, $ap[2]);
        app(App\Modules\Finance\Payables\Application\BankPaymentFile::class)->generate($apRun->id, $ap[2]);

        // GA-10: statements come only from the monthly statement run.
        $runIds = app(\App\Modules\Insurance\Commission\Application\CommissionStatementRun::class)->prepare($ctx['entity_id'], $d('2026-09-30'), $approver);
        $statement = app(\App\Modules\Insurance\Commission\Application\CommissionStatementRun::class)->approve($runIds[0], $approver, $d('2026-10-01'));
        app(CommissionPayoutService::class)->pay($statement->id, null, $payer, $d('2026-10-02'));

        $claims = app(ClaimService::class);
        $claim = $claims->register($policy->id, $d('2026-09-05'), 'collision', $officer, $d('2026-09-06'));
        $claims->reserve($claim->id, 3_000_000, 'initial', $officer, $d('2026-09-06'));
        $payment = app(ClaimPaymentService::class)->approve($claim->id, 2_000_000, $world['policyholder_id'], $approver, $d('2026-09-07'));
        app(ApprovalService::class)->decide((string) app(ApprovalService::class)->pendingFor('claim_payment', $payment->id), $payer, Decision::Approved, null);
        app(ClaimPaymentService::class)->requestRelease($payment->id, $approver, null);
        app(ClaimPaymentService::class)->release($payment->id, $payer, $d('2026-09-08'));
        $claims->recover($claim->id, 'salvage', 100_000, null, 'S', $approver, $d('2026-09-09'));

        $journals = app(ManualJournalService::class);
        $journal = $journals->create(new ManualJournalRequest($ctx['entity_id'], $d('2026-09-20'), 'adjustment', JournalKind::Adjustment, 'isolation', 'BDT', [
            new ManualJournalLine($ctx['accounts']['premium_receivable'], Side::Debit, 1_000, ['branch' => $ctx['branch_id']]),
            new ManualJournalLine($ctx['accounts']['rounding_difference'], Side::Credit, 1_000, ['branch' => $ctx['branch_id']]),
        ]), $maker);
        $journals->submit($journal->id, $maker);
        $journals->approve($journal->id, $checker);
        app(ReversalRequestService::class)->request($journal->id, $d('2026-09-21'), 'isolation check', $maker);
        app(ReconciliationService::class)->runAll((string) DB::table('fiscal_periods')->where('starts', '2026-09-01')->value('id'));
        app(PeriodCloseService::class)->start((string) DB::table('fiscal_periods')->where('starts', '2026-10-01')->value('id'), $world['admin']);

        $lifecycle->cancel($policy->id, $d('2026-10-15'), 'sold', $world['admin']);
        $due = (int) json_decode((string) DB::table('policy_transactions')->where('policy_id', $policy->id)->where('type', 'cancellation')->value('amounts'), true)['refund_due'];
        if ($due > 0) {
            app(RefundService::class)->request($policy->id, $due, 'cancellation', $officer);
        }
        DB::table('premium_write_offs')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $ctx['tenant_id'], 'entity_id' => $ctx['entity_id'], 'branch_id' => $ctx['branch_id'], // gap fixes W7 (GA-24)
            'policy_id' => $policy->id, 'requested_minor' => 100, 'currency' => 'BDT', 'status' => 'rejected', 'reason' => 'isolation', 'requested_by' => $officer, 'requested_at' => now()]);
        app(LicenceExpiryAlerts::class)->run($d('2030-12-01')); // the world agent's licence expires 2030-12-31 (slice D2)
        $scheme = app(CompensationSchemeService::class)->createScheme('LIFE', 'Life agency', 'commission', $d('2026-01-01'), null, [], $world['admin']); // slice D4
        app(HierarchyService::class)->defineLevels($scheme, [['code' => 'FA', 'rank' => 1, 'label' => 'Financial associate']], $world['admin']); // slice D3
        app(CompensationSchemeService::class)->addRule($scheme, CompensationRuleRequest::fromArray(['basis' => 'premium_received', 'policy_year_from' => 1, 'policy_year_to' => 1,
            'effective_from' => '2026-01-01', 'producer_type' => 'agent', 'rate_bp' => 1000]), $world['admin']);
        app(CompensationEngine::class)->calculate(new CompensationRequest($policy->id, $world['product_id'], 'non_life', $world['agent_id'], 'premium_received', 100_000, 1,
            $d('2026-09-30'), 'isolation', (string) Str::uuid7(), $scheme)); // slice D5: records a compliance exception (non-life commission disabled)
        app(AdvanceService::class)->issue($world['agent_id'], 500_000, ['type' => 'full'], $d('2026-09-01'), $world['admin']); // slice D6
        DB::transaction(fn () => app(AdvanceService::class)->recover($world['agent_id'], $ctx['entity_id'], 100_000, (string) Str::uuid7(), $d('2026-09-30')));
        app(TargetService::class)->set('producer', $world['agent_id'], 'monthly', $d('2026-07-01'), 'premium', 1_000, $world['admin']); // slice D7: the policy above was written in July
        app(IncentivePlanService::class)->create(['code' => 'AG-MONTHLY', 'name' => 'Agent bonus', 'period_type' => 'monthly', 'metric' => 'premium', 'applies_to' => ['producer_type' => 'agent'],
            'tiers' => [['achievement_bp_from' => 1, 'bonus' => ['type' => 'fixed_minor', 'value' => 1_000]]], 'effective_from' => '2026-01-01'], $world['admin']);
        app(IncentiveRun::class)->run($ctx['entity_id'], $d('2026-07-31'), $world['admin']);
        $portalUser = app(ProducerPortalAccess::class)->grant($world['agent_id'], 'portal-'.Str::lower(Str::random(6)).'@agents.test', $world['admin']); // slice D9
        App\Models\User::query()->findOrFail($portalUser)->createToken('isolation', ['portal:read']);
        app(App\Modules\Platform\Setup\SetupProgress::class)->complete('company', $world['admin']); // session S1 setup wizard
        Illuminate\Support\Facades\Storage::fake('documents'); // fix F2: a document attached to the claim
        app(App\Modules\Platform\Documents\DocumentStore::class)->attach('claim', $claim->id, new App\Modules\Platform\Documents\DocumentContents('survey.pdf', '%PDF isolation'), $officer);
        app(App\Modules\Platform\Documents\Templates\DocumentTemplates::class)->seedCurrentTenant(); // Phase 3 R8: templates and a generated schedule
        fakePdfRenderer();
        app(App\Modules\Platform\Documents\Generation\DocumentGenerator::class)->generate('policy_schedule', 'policy', $policy->id, $world['admin']);
        app(App\Modules\Insurance\Rating\Application\DutyBook::class)->record(['code' => 'vat', 'basis' => 'pct_of_premium', 'rate_bp' => 1500, 'class_codes' => ['motor'], // Phase 3 R2
            'effective_from' => '2026-01-01', 'label_en' => 'VAT', 'label_bn' => 'মূসক'], $world['admin']);
        DB::table('quotations')->insert(['id' => $quotationId = (string) Str::uuid7(), 'tenant_id' => $ctx['tenant_id'], 'entity_id' => $ctx['entity_id'], 'branch_id' => $ctx['branch_id'], // Phase 3 R4
            'product_id' => $world['product_id'], 'product_version_id' => $world['product_version_id'], 'inception' => '2026-09-15', 'risk_inputs' => '{}', 'coverages' => '[]',
            'currency' => 'BDT', 'status' => 'draft', 'created_by' => $world['admin'], 'created_at' => now(), 'updated_at' => now()]);
        DB::table('proposals')->insert(['id' => $proposalId = (string) Str::uuid7(), 'tenant_id' => $ctx['tenant_id'], 'entity_id' => $ctx['entity_id'], 'branch_id' => $ctx['branch_id'], // Phase 3 R5
            'quotation_id' => $quotationId, 'number' => 'PRP-ISOLATION', 'product_id' => $world['product_id'], 'product_version_id' => $world['product_version_id'], 'class_code' => 'motor',
            'customer_party_id' => $world['policyholder_id'], 'inception' => '2026-09-15', 'risk_inputs' => '{}', 'risk_keys' => '[]', 'rating_result' => '{}', 'rating_plan_code' => 'MOTOR-ISOLATION',
            'rating_plan_version' => 1, 'currency' => 'BDT', 'sum_insured_minor' => 1, 'net_premium_minor' => 1, 'duties_minor' => 0, 'gross_premium_minor' => 1,
            'created_by' => $world['admin'], 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cover_notes')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $ctx['tenant_id'], 'entity_id' => $ctx['entity_id'], 'branch_id' => $ctx['branch_id'], // Phase 3 R6
            'proposal_id' => $proposalId, 'number' => 'CVN-ISOLATION', 'class_code' => 'motor', 'valid_from' => '2026-09-15', 'valid_to' => '2026-09-30', 'status' => 'active',
            'issued_by' => $world['admin'], 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('expiry_register')->insert(['id' => $registerId = (string) Str::uuid7(), 'tenant_id' => $ctx['tenant_id'], 'entity_id' => $ctx['entity_id'], 'branch_id' => $ctx['branch_id'], // Phase 3 R9
            'policy_id' => $policy->id, 'policy_number' => 'POL-ISOLATION', 'product_id' => $world['product_id'], 'policyholder_party_id' => $world['policyholder_id'], 'expiry' => '2027-09-14',
            'rated' => false, 'bucket' => 60, 'days_left' => 50, 'as_of' => '2027-07-26', 'status' => 'upcoming', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('renewal_notices')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $ctx['tenant_id'], 'expiry_register_id' => $registerId, 'policy_id' => $policy->id,
            'offset_days' => 45, 'kind' => 'notice', 'quotation_id' => $quotationId, 'notification_ids' => '[]', 'sent_on' => '2027-07-31', 'created_at' => now()]);
        DB::table('notifications')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $ctx['tenant_id'], 'channel' => 'email', 'adapter' => 'log', 'recipient_type' => 'party',
            'recipient_id' => $world['policyholder_id'], 'recipient_name' => 'Rahima Akter', 'subject_type' => 'policy', 'subject_id' => $policy->id, 'title' => 'Renewal notice',
            'body' => 'Isolation', 'idempotency_key' => 'isolation', 'status' => 'sent', 'sent_at' => now(), 'created_at' => now()]);
        // Market gap G5: regulatory returns, technical provision runs and legacy paid claims history.
        DB::table('regulatory_returns')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $ctx['tenant_id'], 'entity_id' => $ctx['entity_id'], 'form_code' => 'claims', 'period_key' => '2026-Q3',
            'period_start' => '2026-07-01', 'period_end' => '2026-09-30', 'status' => 'draft', 'snapshot' => '{}', 'generated_by' => $world['admin'], 'generated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('technical_provision_runs')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $ctx['tenant_id'], 'entity_id' => $ctx['entity_id'], 'quarter_key' => '2026-Q3',
            'quarter_start' => '2026-07-01', 'quarter_end' => '2026-09-30', 'currency' => 'BDT', 'status' => 'draft', 'methods' => '{}', 'results' => '{"classes":[]}', 'total_ibnr_minor' => 0,
            'prepared_by' => $world['admin'], 'prepared_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('claims_paid_history')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $ctx['tenant_id'], 'entity_id' => $ctx['entity_id'], 'class' => 'motor',
            'accident_quarter_start' => '2026-01-01', 'paid_quarter_start' => '2026-04-01', 'paid_minor' => 1]);
        // Design addendum v2 §B.7 fixed assets: a class, an asset, a depreciation run and row, a movement and a disposal.
        $t = $ctx['tenant_id'];
        DB::table('asset_classes')->insert(['id' => $classId = (string) Str::uuid7(), 'tenant_id' => $t, 'entity_id' => $ctx['entity_id'], 'code' => 'IT', 'name' => 'IT', 'method' => 'straight_line',
            'useful_life_months' => 36, 'cost_account_id' => $ctx['accounts']['fixed_asset_cost'], 'accumulated_account_id' => $ctx['accounts']['accumulated_depreciation'], 'expense_account_id' => $ctx['accounts']['depreciation_expense']]);
        DB::table('fixed_assets')->insert(['id' => $assetId = (string) Str::uuid7(), 'tenant_id' => $t, 'entity_id' => $ctx['entity_id'], 'branch_id' => $ctx['branch_id'], 'class_id' => $classId,
            'number' => 'FA-ISOLATION-'.$t, 'description' => 'Laptop', 'acquired_on' => '2026-09-01', 'currency' => 'BDT', 'cost_minor' => 100, 'method' => 'straight_line', 'useful_life_months' => 36,
            'source_type' => 'manual', 'paid_via' => 'payable', 'status' => 'disposed', 'created_by' => $world['admin']]);
        DB::table('asset_depreciation_runs')->insert(['id' => $runId = (string) Str::uuid7(), 'tenant_id' => $t, 'entity_id' => $ctx['entity_id'], 'period_id' => DB::table('fiscal_periods')->value('id'),
            'period_ends' => '2026-09-30', 'assets_count' => 1, 'total_minor' => 3, 'posted_by' => $world['admin'], 'posted_at' => now()]);
        DB::table('asset_depreciation')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $t, 'asset_id' => $assetId, 'run_id' => $runId, 'period_id' => DB::table('fiscal_periods')->value('id'),
            'period_ends' => '2026-09-30', 'branch_id' => $ctx['branch_id'], 'amount_minor' => 3, 'accumulated_minor' => 3, 'nbv_minor' => 97]);
        DB::table('asset_movements')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $t, 'asset_id' => $assetId, 'moved_on' => '2026-09-15', 'from_branch_id' => $ctx['branch_id'],
            'to_branch_id' => (string) Str::uuid7(), 'reason' => 'Isolation', 'cost_minor' => 100, 'accumulated_minor' => 0, 'moved_by' => $world['admin']]);
        DB::table('asset_disposals')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $t, 'asset_id' => $assetId, 'number' => 'ADS-ISOLATION-'.$t, 'disposal_date' => '2026-10-01', 'kind' => 'write_off',
            'proceeds_minor' => 0, 'cost_minor' => 100, 'accumulated_minor' => 3, 'nbv_minor' => 97, 'gain_loss_minor' => -97, 'reason' => 'Isolation', 'disposed_by' => $world['admin']]);
        // Design addendum v2 §B.8.1 budgets.
        DB::table('budgets')->insert(['id' => $budgetId = (string) Str::uuid7(), 'tenant_id' => $t, 'entity_id' => $ctx['entity_id'], 'fiscal_year' => 2026, 'code' => 'MAIN', 'name' => 'Budget',
            'version' => 1, 'status' => 'draft', 'prepared_by' => $world['admin']]);
        DB::table('budget_lines')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $t, 'budget_id' => $budgetId, 'account_id' => $ctx['accounts']['salary_expense'], 'branch_id' => $ctx['branch_id'],
            'period_no' => 3, 'amount_minor' => 100]);
        app(App\Modules\Insurance\Underwriting\Application\UnderwritingLimits::class)->set((string) DB::table('roles')->where('code', 'like', 'test-%')->value('code'), 'motor', 1, CarbonImmutable::today()->addYear(), $world['admin']);
        // Reinsurance MVP (G4): every reinsurance table.
        $t = $ctx['tenant_id'];
        $partyId = (string) DB::table('parties')->value('id');
        DB::table('reinsurers')->insert(['id' => $reinsurer = (string) Str::uuid7(), 'tenant_id' => $t, 'party_id' => $partyId, 'code' => 'RE1', 'country' => 'BD', 'created_by' => $world['admin']]);
        DB::table('ri_treaties')->insert(['id' => $treaty = (string) Str::uuid7(), 'tenant_id' => $t, 'entity_id' => $ctx['entity_id'], 'code' => 'T1', 'name' => 'Treaty', 'class_code' => 'motor',
            'underwriting_year' => 2026, 'period_from' => '2026-07-01', 'period_to' => '2027-06-30', 'type' => 'quota_share', 'cession_bp' => 4000, 'commission_bp' => 2500, 'sbc_share_bp' => 5000,
            'currency' => 'BDT', 'created_by' => $world['admin']]);
        DB::table('ri_treaty_participants')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $t, 'treaty_id' => $treaty, 'reinsurer_id' => $reinsurer, 'share_bp' => 10_000, 'created_at' => now()]);
        DB::table('ri_facultative_placements')->insert(['id' => $placement = (string) Str::uuid7(), 'tenant_id' => $t, 'entity_id' => $ctx['entity_id'], 'policy_id' => $policy->id, 'reinsurer_id' => $reinsurer,
            'share_bp' => 1000, 'ceded_sum_insured_minor' => 0, 'premium_minor' => 1_000, 'commission_bp' => 0, 'commission_minor' => 0, 'placed_on' => '2026-09-01', 'currency' => 'BDT', 'placed_by' => $world['admin']]);
        DB::table('ri_cessions')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $t, 'entity_id' => $ctx['entity_id'], 'policy_id' => $policy->id, 'facultative_placement_id' => $placement,
            'reinsurer_id' => $reinsurer, 'kind' => 'facultative', 'movement' => 'placement', 'share_bp' => 1000, 'ceded_sum_insured_minor' => 0, 'premium_minor' => 1_000, 'commission_minor' => 0,
            'accounting_date' => '2026-09-01', 'currency' => 'BDT', 'created_at' => now()]);
        DB::table('ri_policy_positions')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $t, 'policy_id' => $policy->id, 'sum_insured_minor' => 0, 'net_premium_minor' => 0, 'sbc_sum_insured_minor' => 0,
            'treaty_sum_insured_minor' => 0, 'retained_sum_insured_minor' => 0, 'above_capacity_minor' => 0]);
        DB::table('ri_claim_shares')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $t, 'entity_id' => $ctx['entity_id'], 'claim_id' => $claim->id, 'policy_id' => $policy->id, 'reinsurer_id' => $reinsurer,
            'kind' => 'reserve', 'source_type' => 'claim_reserve', 'source_id' => (string) Str::uuid7(), 'share_bp' => 1000, 'gross_minor' => 0, 'amount_minor' => 0, 'recorded_on' => '2026-09-06', 'currency' => 'BDT', 'created_at' => now()]);
        DB::table('ri_upr_adjustments')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $t, 'entity_id' => $ctx['entity_id'], 'period_id' => (string) Str::uuid7(), 'branch_id' => $ctx['branch_id'],
            'reinsurer_id' => $reinsurer, 'as_of' => '2026-09-30', 'unearned_minor' => 0, 'delta_minor' => 0, 'currency' => 'BDT', 'created_at' => now()]);
        DB::table('ri_statements')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $t, 'entity_id' => $ctx['entity_id'], 'number' => 'RIS-1', 'reinsurer_id' => $reinsurer, 'year' => 2026, 'quarter' => 3,
            'period_from' => '2026-07-01', 'period_to' => '2026-09-30', 'opening_balance_minor' => 0, 'premium_minor' => 0, 'commission_minor' => 0, 'claims_recoverable_minor' => 0,
            'closing_balance_minor' => 0, 'outstanding_claims_share_minor' => 0, 'currency' => 'BDT', 'prepared_by' => $world['admin']]);
    });
    activeRatingPlan($ctx['tenant_id'], ['code' => 'MOTOR-ISOLATION', 'name' => 'Isolation plan', 'class_code' => 'motor', 'effective_from' => '2026-01-01', // Phase 3 R2
        'tables' => [['code' => 'rates', 'name' => 'Rates', 'dimensions' => ['vehicle_type'], 'value_type' => 'rate_pct', 'rows' => [['keys' => ['vehicle_type' => 'private'], 'value_bp' => 250]]]],
        'steps' => [['order_no' => 1, 'code' => 'base', 'kind' => 'base', 'expression' => "pct(sum_insured, lookup('rates', risk.vehicle_type))", 'label_en' => 'Base', 'label_bn' => 'মূল']]]);
    // People and Payroll MVP: reference data, rules, an employee with history, a commission payroll input, a posted and paid run (payslips, lines, bank file).
    $hr = userWithPermissions($ctx['tenant_id'], ['hr.manage_employees', 'payroll.prepare', 'payroll.manage_rules']);
    $payrollApprover = userWithPermissions($ctx['tenant_id'], ['payroll.approve']);
    $payrollPayer = userWithPermissions($ctx['tenant_id'], ['payroll.pay']);
    asTenant($ctx['tenant_id'], function () use ($ctx, $hr, $payrollApprover, $payrollPayer): void {
        $ids = Database\Seeders\PeopleDemoSeeder::configure($ctx['entity_id'], $hr);
        DB::table('parties')->insert(['id' => $party = (string) Str::uuid7(), 'tenant_id' => $ctx['tenant_id'], 'kind' => 'individual', 'display_name' => 'Isolation employee', 'status' => 'active']);
        $employee = app(App\Modules\People\Employee\Application\EmployeeService::class)->hire($ctx['entity_id'], ['party_id' => $party, 'code' => 'ISO-1', 'full_name' => 'Isolation employee',
            'joined_on' => '2024-01-01', 'branch_id' => $ctx['branch_id'], 'department_id' => $ids['departments']['UW'], 'designation_id' => $ids['designations']['Officer'], 'grade_id' => $ids['grades']['G7'],
            'employment_type' => 'permanent', 'basic_minor' => 30_000_00, 'bank_name' => 'City Bank', 'account_no' => '1051000000099'], $hr);
        app(App\Modules\People\Employee\Application\EmployeeService::class)->changeEmployment($employee, 'pay_change', CarbonImmutable::parse('2026-07-01'), ['basic_minor' => 32_000_00], $hr);
        app(App\Modules\People\Payroll\Application\CommissionPayrollEarningConsumer::class)->handle(['commission_statement_id' => (string) Str::uuid7(), 'employee_id' => $employee,
            'amount_minor' => 1_000_00, 'currency' => 'BDT', 'period_end' => '2026-11-30', 'paid_on' => '2026-11-05'], (string) Str::uuid7());
        $runs = app(App\Modules\People\Payroll\Application\PayrollRunService::class);
        $run = $runs->calculate($ctx['entity_id'], 2026, 11, $hr);
        $runs->approve($run, $payrollApprover);
        $runs->pay($run, (string) DB::table('bank_accounts')->value('id'), CarbonImmutable::parse('2026-11-30'), $payrollPayer);
    });
}

it('shows the runtime role none of another tenant\'s rows in any tenant table', function (): void {
    $a = seedDemoTenant('tenant-a');
    $b = seedDemoTenant('tenant-b');
    populateEveryTenantTable($a);
    populateEveryTenantTable($b);

    $tables = array_values(array_map(fn (mixed $name): string => (string) $name, DB::table('information_schema.columns')
        ->where('table_schema', 'public')->where('column_name', 'tenant_id')->orderBy('table_name')->pluck('table_name')->all()));

    DB::statement('SET ROLE erp_app');
    try {
        $empty = [];
        foreach ($tables as $table) {
            $ownRows = asTenant($a['tenant_id'], fn (): int => DB::table($table)->count());
            $otherRows = asTenant($a['tenant_id'], fn (): int => DB::table($table)->where('tenant_id', '<>', $a['tenant_id'])->count());
            $withoutTenant = DB::table($table)->count();
            expect($otherRows)->toBe(0, "{$table} shows tenant B rows to tenant A")
                ->and($withoutTenant)->toBe(0, "{$table} shows rows without a tenant context");
            if ($ownRows === 0) {
                $empty[] = $table;
            }
        }
        expect(array_values(array_diff($empty, TABLES_WITHOUT_SCENARIO_ROWS)))->toBe([], 'populate these tenant tables in populateEveryTenantTable (or list them with a reason)');

        asTenant($b['tenant_id'], function () use ($tables, $b): void {
            foreach ($tables as $table) {
                expect(DB::table($table)->where('tenant_id', '<>', $b['tenant_id'])->count())->toBe(0, "{$table} shows tenant A rows to tenant B");
            }
        });
    } finally {
        DB::statement('RESET ROLE');
    }
});
