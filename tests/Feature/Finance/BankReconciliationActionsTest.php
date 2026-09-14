<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Finance\Bank\Application\BankAccountService;
use App\Modules\Finance\Bank\Application\BankMatcher;
use App\Modules\Finance\Bank\Application\BankReconciliationQuery;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ChequeBounceService;
use App\Modules\Insurance\Collections\Application\ChequeDetails;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Commission\Application\CommissionPayoutService;
use App\Modules\Insurance\Commission\Application\CommissionPlanService;
use App\Modules\Insurance\Commission\Application\CommissionStatementRun;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Gap fix GA-27: the bank screen books what the ledger is missing instead of only explaining it — ledger lines that cancel out (a bounced cheque and its
 * reversal) are offset, an unknown credit becomes a receipt in suspense in one step, a bank charge opens a prefilled manual journal whose line then
 * matches the statement — and a commission payout's bank line names its statement.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-20 10:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $planner = userWithPermissions($this->ctx['tenant_id'], ['commission.manage_plans']);
    $planId = asTenant($this->ctx['tenant_id'], fn (): string => app(CommissionPlanService::class)->create('P10', 'Plan 10%', 1000, null, null, $planner)->id);
    $this->world = seedInsuranceWorld($this->ctx, 'monthly', true, $planId);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->bankAccountId = asTenant($this->ctx['tenant_id'], fn (): string => app(BankAccountService::class)
        ->create($this->ctx['entity_id'], $this->ctx['accounts']['bank_main'], 'City Bank', '****4471', 'BDT', $this->world['admin'])->id);
    $this->installments = asTenant($this->ctx['tenant_id'], function (): array {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 2), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->pluck('id')->map(fn ($id): string => (string) $id)->all();
    });
    // The seeded accountant template role: bank.match, receipt.allocate, accounting.create_manual_journal and (A-219) receipt.create.
    $this->accountant = asTenant($this->ctx['tenant_id'], function (): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'email' => $id.'@demo.test', 'name' => 'Accountant', 'password' => 'x',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', 'accountant')->value('id'),
            'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);

        return User::query()->findOrFail($id);
    });
    $this->statementLine = fn (string $postedOn, int $amount, string $reference, string $description = ''): string => asTenant($this->ctx['tenant_id'], function () use ($postedOn, $amount, $reference, $description): string {
        DB::table('bank_statement_lines')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'bank_account_id' => $this->bankAccountId, 'posted_on' => $postedOn,
            'amount_minor' => $amount, 'reference' => $reference, 'description' => $description, 'raw' => '{}', 'line_hash' => Str::random(40), 'source_file' => 's.csv', 'match_status' => 'unmatched',
            'imported_by' => $this->world['admin'], 'imported_at' => now()]);

        return $id;
    });
    $this->unmatchedLedger = fn (): array => asTenant($this->ctx['tenant_id'], fn (): array => app(BankReconciliationQuery::class)->unmatched($this->bankAccountId, CarbonImmutable::parse('2026-09-30'))['journal_lines']);
});

it('offsets a bounced cheque against its receipt, refuses lines that do not cancel out, and undoes the offset', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        DB::table('account_role_mappings')->where('role_code', 'cheques_in_clearing')->delete(); // a company whose cheques go straight to the bank
        $receipt = app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cheque', 3_550_000, 'BDT',
            CarbonImmutable::parse('2026-09-10'), $this->bankAccountId, 'CHQ 1', [new AllocationLine($this->installments[0], 3_550_000)], new ChequeDetails('1', 'Sonali Bank', CarbonImmutable::parse('2026-09-10'))), $this->world['admin']);
        app(ChequeBounceService::class)->bounce($receipt->id, 'Insufficient funds', $this->world['admin'], CarbonImmutable::parse('2026-09-12'));
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 100_000, 'BDT',
            CarbonImmutable::parse('2026-09-12'), $this->bankAccountId, 'TRF 2', []), $this->world['admin']);
    });
    $ledger = collect(array_values(($this->unmatchedLedger)()));
    expect($ledger->pluck('amount_minor')->all())->toBe([3_550_000, -3_550_000, 100_000]);
    [$in, $out, $other] = $ledger->pluck('journal_line_id')->all();

    actingAs($this->accountant)->post("/bank/{$this->bankAccountId}/offset", ['journal_line_ids' => [$in, $other]], $this->headers)
        ->assertSessionHasErrors(['form' => 'The chosen ledger lines add up to 36,500.00, not zero, so they do not cancel each other out.']);
    actingAs($this->accountant)->post("/bank/{$this->bankAccountId}/offset", ['journal_line_ids' => [$in]], $this->headers)->assertSessionHasErrors('journal_line_ids');
    actingAs(asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['bank.import']))))
        ->post("/bank/{$this->bankAccountId}/offset", ['journal_line_ids' => [$in, $out]], $this->headers)->assertSessionHasErrors('form');

    actingAs($this->accountant)->post("/bank/{$this->bankAccountId}/offset", ['journal_line_ids' => [$in, $out]], $this->headers)->assertSessionHasNoErrors()
        ->assertSessionHas('status', '2 ledger lines offset against each other.');
    expect(collect(array_values(($this->unmatchedLedger)()))->pluck('journal_line_id')->all())->toBe([$other])
        ->and(asTenant($this->ctx['tenant_id'], fn (): array => DB::table('bank_matches')->get(['method', 'statement_line_id'])->map(fn (object $m): array => (array) $m)->all()))
            ->toBe([['method' => 'contra', 'statement_line_id' => null], ['method' => 'contra', 'statement_line_id' => null]])
        ->and(asTenant($this->ctx['tenant_id'], fn () => thrownBy(fn () => app(BankMatcher::class)->offset($this->bankAccountId, [$in, $out], (string) $this->accountant->id), BusinessRuleViolation::class)->reasonCode))
            ->toBe('JOURNAL_LINE_ALREADY_MATCHED');

    $group = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('bank_matches')->value('contra_group_id'));
    actingAs($this->accountant)->post("/bank/{$this->bankAccountId}/offsets/{$group}/undo", [], $this->headers)->assertSessionHasNoErrors();
    expect(($this->unmatchedLedger)())->toHaveCount(3)
        ->and(asTenant($this->ctx['tenant_id'], fn (): array => DB::table('audit_events')->whereIn('action', ['bank_lines.offset', 'bank_lines.offset_undone'])->orderBy('occurred_at')->pluck('action')->all()))
            ->toBe(['bank_lines.offset', 'bank_lines.offset_undone']);
});

it('records an unknown credit on the statement as a receipt held in suspense and matches the line to it, in one step', function (): void {
    $credit = ($this->statementLine)('2026-09-18', 5_200_000, 'TT 9921', 'KARIM TRADERS');
    $debit = ($this->statementLine)('2026-09-18', -35_000, 'CHG', 'Service charge');
    actingAs($this->accountant)->get("/bank/{$this->bankAccountId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('bank/Show')
        ->where('branches.0.id', $this->ctx['branch_id'])->where('account.gl_account_id', $this->ctx['accounts']['bank_main']));

    actingAs($this->accountant)->postJson("/bank/lines/{$credit}/receipt", ['branch_id' => $this->ctx['branch_id']], [...$this->headers, 'X-Journal-Preview' => '1'])->assertOk()
        ->assertJsonPath('journals.0.event', 'RECEIPT_RECORDED')->assertJsonPath('journals.0.lines.1.role', 'suspense_receipts');
    expect(asTenant($this->ctx['tenant_id'], fn (): int => DB::table('receipts')->count()))->toBe(0);

    actingAs($this->accountant)->post("/bank/lines/{$credit}/receipt", ['branch_id' => $this->ctx['branch_id']], $this->headers)->assertSessionHasNoErrors();
    asTenant($this->ctx['tenant_id'], function () use ($credit): void {
        $receipt = DB::table('receipts')->sole(['id', 'number', 'amount_minor', 'value_date', 'bank_account_id', 'reference', 'channel', 'created_by']);
        expect([(int) $receipt->amount_minor, (string) $receipt->value_date, $receipt->bank_account_id, $receipt->reference, $receipt->channel, $receipt->created_by])
            ->toBe([5_200_000, '2026-09-18', $this->bankAccountId, 'TT 9921 KARIM TRADERS', 'bank_transfer', (string) $this->accountant->id])
            ->and((int) DB::table('suspense_items')->where('receipt_id', $receipt->id)->value('amount_minor'))->toBe(5_200_000)
            ->and(DB::table('bank_statement_lines')->where('id', $credit)->value('match_status'))->toBe('matched');
    });
    actingAs($this->accountant)->post("/bank/lines/{$debit}/receipt", ['branch_id' => $this->ctx['branch_id']], $this->headers)->assertSessionHasErrors('form');
    actingAs($this->accountant)->post("/bank/lines/{$credit}/receipt", ['branch_id' => $this->ctx['branch_id']], $this->headers)->assertSessionHasErrors('form');
});

it('prefills a bank charge journal from a statement line, whose posted line then matches the statement by its memo', function (): void {
    $charge = ($this->statementLine)('2026-09-19', -35_000, 'SC-0919', 'Account maintenance fee');
    $query = http_build_query(['prefill' => ['date' => '2026-09-19', 'amount' => '350.00', 'debit_role' => 'bank_charges', 'credit_account' => $this->ctx['accounts']['bank_main'],
        'memo' => 'SC-0919 Account maintenance fee', 'description' => 'Bank charges, City Bank ****4471', 'reason' => 'Charge on the bank statement']]);
    actingAs($this->accountant)->get("/accounting/journals/create?{$query}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('accounting/journals/Create')
        ->where('prefill.transaction_date', '2026-09-19')->where('prefill.description', 'Bank charges, City Bank ****4471')
        ->where('prefill.lines.0', ['account_id' => $this->ctx['accounts']['bank_charges'], 'side' => 'debit', 'amount' => '350.00', 'memo' => 'SC-0919 Account maintenance fee'])
        ->where('prefill.lines.1', ['account_id' => $this->ctx['accounts']['bank_main'], 'side' => 'credit', 'amount' => '350.00', 'memo' => 'SC-0919 Account maintenance fee']));
    actingAs($this->accountant)->get('/accounting/journals/create?'.http_build_query(['prefill' => ['credit_account' => (string) Str::uuid7(), 'amount' => 'lots']]), $this->headers)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('prefill.lines.1.account_id', '')->where('prefill.lines.0.amount', ''));

    actingAs($this->accountant)->post('/accounting/journals', ['transaction_date' => '2026-09-19', 'description' => 'Bank charges, City Bank ****4471', 'kind' => 'manual', 'reason' => 'Charge on the bank statement',
        'lines' => [['account_id' => $this->ctx['accounts']['bank_charges'], 'side' => 'debit', 'amount' => '350.00', 'branch_id' => $this->ctx['branch_id'], 'memo' => 'SC-0919 Account maintenance fee'],
            ['account_id' => $this->ctx['accounts']['bank_main'], 'side' => 'credit', 'amount' => '350.00', 'branch_id' => $this->ctx['branch_id'], 'memo' => 'SC-0919 Account maintenance fee']]], $this->headers)
        ->assertSessionHasNoErrors();
    asTenant($this->ctx['tenant_id'], function () use ($charge): void {
        app(ManualJournalService::class)->approve((string) DB::table('journals')->where('kind', 'manual')->value('id'), userWithPermissions($this->ctx['tenant_id'], ['accounting.approve_journal']));
        $suggestion = collect(app(BankMatcher::class)->suggestions($this->bankAccountId, CarbonImmutable::parse('2026-09-30')))->firstWhere('statement_line_id', $charge);
        expect($suggestion['confidence'] ?? null)->toBe(100)
            ->and(app(BankMatcher::class)->autoMatch($this->bankAccountId))->toBe(1)
            ->and(DB::table('bank_statement_lines')->where('id', $charge)->value('match_status'))->toBe('matched');
    });
});

it('names the commission statement on the payout\'s bank line', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 6_000_000, 'BDT',
            CarbonImmutable::parse('2026-09-10'), $this->bankAccountId, 'TRF 1', [new AllocationLine($this->installments[0], 6_000_000)]), $this->world['admin']);
        // W3 (D-75): plan commission is approved in the monthly statement run and paid through CommissionPayoutService::pay.
        $approver = userWithPermissions($this->ctx['tenant_id'], ['commission.approve']);
        $drafts = app(CommissionStatementRun::class)->prepare($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-30'), $approver);
        expect($drafts)->toHaveCount(1);
        $statement = app(CommissionStatementRun::class)->approve($drafts[0], $approver, CarbonImmutable::parse('2026-09-15'));
        app(CommissionPayoutService::class)->pay($statement->id, $this->bankAccountId, userWithPermissions($this->ctx['tenant_id'], ['commission.pay']), CarbonImmutable::parse('2026-09-16'));

        expect($statement->net_minor)->toBeGreaterThan(0);
        $payout = collect(app(BankReconciliationQuery::class)->unmatched($this->bankAccountId, CarbonImmutable::parse('2026-09-30'))['journal_lines'])->firstWhere('amount_minor', -$statement->net_minor);
        expect($payout['reference'] ?? null)->toBe($statement->number);
    });
});
