<?php

declare(strict_types=1);

use App\Modules\Finance\Bank\Application\BankAccountService;
use App\Modules\Finance\Bank\Application\BankMatcher;
use App\Modules\Finance\Bank\Application\BankReconciliationQuery;
use App\Modules\Finance\Bank\Application\StatementImport;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design §2.4 bank_accounts / bank_statement_lines / bank_matches, §4.2 "bank_accounts.gl_account_id overrides role", §5.7 task 3
 * (unmatched lines), spec §5 Bank: statement import, matching, exception queue.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->cityGl = asTenant($this->ctx['tenant_id'], function (): string {
        $id = (string) Str::uuid7();
        DB::table('accounts')->insert(['id' => $id, 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => '1011',
            'name' => 'Bank - City Bank', 'type' => 'asset', 'normal_side' => 'debit', 'is_postable' => true, 'is_control' => false, 'status' => 'active']);

        return $id;
    });
    $this->bankAccountId = asTenant($this->ctx['tenant_id'], fn (): string => app(BankAccountService::class)
        ->create($this->ctx['entity_id'], $this->cityGl, 'City Bank', '****4471', 'BDT', $this->world['admin'])->id);
    $this->installments = asTenant($this->ctx['tenant_id'], function (): array {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 2), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->pluck('id')->map(fn ($id): string => (string) $id)->all();
    });
    // Records a receipt into the City Bank account and returns the id of its bank journal line.
    $this->bankReceipt = function (int $amount, string $valueDate, string $reference, ?int $allocate = null): string {
        $receipt = app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', $amount, 'BDT',
            CarbonImmutable::parse($valueDate), $this->bankAccountId, $reference, $allocate === null ? [] : [new AllocationLine($this->installments[0], $allocate)]), $this->world['admin']);
        $sourceId = DB::table('receipt_allocations')->where('receipt_id', $receipt->id)->value('id') ?? $receipt->id;

        return (string) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.source_id', $sourceId)->where('l.role_code', 'bank_main')->value('l.id');
    };
});

function statementCsv(string ...$rows): string
{
    return "date,description,reference,amount\n".implode("\n", $rows)."\n";
}

it('keeps bank accounts on postable asset GL accounts of the same entity, for bank.manage_accounts only', function (): void {
    $clerk = userWithPermissions($this->ctx['tenant_id'], ['bank.match']);

    asTenant($this->ctx['tenant_id'], function () use ($clerk): void {
        $service = app(BankAccountService::class);

        expect(fn () => $service->create($this->ctx['entity_id'], $this->cityGl, 'City Bank', '****0001', 'BDT', $clerk))->toThrow(PermissionDenied::class)
            ->and(thrownBy(fn () => $service->create($this->ctx['entity_id'], $this->ctx['accounts']['premium_income'], 'X', '****0002', 'BDT', $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('INVALID_GL_ACCOUNT')
            ->and(thrownBy(fn () => $service->create($this->ctx['entity_id'], (string) Str::uuid7(), 'X', '****0003', 'BDT', $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('INVALID_GL_ACCOUNT')
            ->and(DB::table('bank_accounts')->count())->toBe(1);
    });
});

it('posts a receipt into the GL account of its bank account instead of the bank_main role account (§4.2)', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $lineId = ($this->bankReceipt)(5_000_000, '2026-09-15', 'TRX-778812', 5_000_000);

        expect(DB::table('journal_lines')->where('id', $lineId)->value('account_id'))->toBe($this->cityGl)
            ->and(DB::table('journal_lines')->where('account_id', $this->ctx['accounts']['bank_main'])->count())->toBe(0)
            ->and(DB::table('accounting_events')->where('event_type', 'PREMIUM_RECEIVED')->value('status'))->toBe('posted');

        expect(thrownBy(fn () => app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 100, 'USD',
            CarbonImmutable::parse('2026-09-15'), $this->bankAccountId, null, []), $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('INVALID_BANK_ACCOUNT');
    });
});

it('imports a statement idempotently: re-importing or overlapping files never duplicates a line', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $import = app(StatementImport::class);
        $september = statementCsv('2026-09-16,Transfer RAHIMA,TRX-778812,50000.00', '2026-09-17,Service charge,,-150.00', '2026-09-17,Service charge,,-150.00');

        $first = $import->import($this->bankAccountId, $september, 'sept.csv', $this->world['admin']);
        $again = $import->import($this->bankAccountId, $september, 'sept-copy.csv', $this->world['admin']);
        $overlap = $import->import($this->bankAccountId, statementCsv('2026-09-17,Service charge,,-150.00', '2026-09-17,Service charge,,-150.00', '2026-09-18,Cash deposit,DEP-1,2500.50'), 'overlap.csv', $this->world['admin']);

        expect([$first->imported, $first->duplicates])->toBe([3, 0])
            ->and([$again->imported, $again->duplicates])->toBe([0, 3])
            ->and([$overlap->imported, $overlap->duplicates])->toBe([1, 2])
            ->and(DB::table('bank_statement_lines')->where('bank_account_id', $this->bankAccountId)->count())->toBe(4)
            ->and(DB::table('bank_statement_lines')->orderBy('posted_on')->orderBy('amount_minor')->pluck('amount_minor')->map(fn ($a): int => (int) $a)->all())->toBe([5_000_000, -15_000, -15_000, 250_050])
            ->and(DB::table('bank_statement_lines')->where('match_status', 'unmatched')->count())->toBe(4);
    });
});

it('refuses a statement with invalid rows, importing none of it', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $result = app(StatementImport::class)->import($this->bankAccountId, statementCsv('2026-09-16,ok,,10.00', '16/09/2026,bad date,,10.00', '2026-09-16,bad amount,,1.2.3', '2026-09-16,zero,,0'), 'bad.csv', $this->world['admin']);

        expect($result->imported)->toBe(0)
            ->and(array_keys($result->errors))->toBe([3, 4, 5])
            ->and(DB::table('bank_statement_lines')->count())->toBe(0);
    });
});

it('auto-matches on amount, reference and date window, leaving anything ambiguous unmatched', function (): void {
    config(['erp.bank.auto_match_date_window_days' => 3]);

    asTenant($this->ctx['tenant_id'], function (): void {
        $matchedLine = ($this->bankReceipt)(5_000_000, '2026-09-15', 'TRX-778812', 5_000_000);
        ($this->bankReceipt)(700_000, '2026-09-15', 'TRX-900001');   // statement line 12 days later: outside the window
        ($this->bankReceipt)(300_000, '2026-09-15', 'TRX-900002');   // statement reference differs
        app(StatementImport::class)->import($this->bankAccountId, statementCsv(
            '2026-09-16,Transfer RAHIMA trx-778812,,50000.00',
            '2026-09-27,Transfer,TRX-900001,7000.00',
            '2026-09-15,Transfer,TRX-123456,3000.00',
        ), 'sept.csv', $this->world['admin']);

        expect(app(BankMatcher::class)->autoMatch($this->bankAccountId))->toBe(1)
            ->and(app(BankMatcher::class)->autoMatch($this->bankAccountId))->toBe(0);

        $match = DB::table('bank_matches')->first();
        expect($match?->journal_line_id)->toBe($matchedLine)
            ->and($match?->method)->toBe('auto')
            ->and(DB::table('bank_statement_lines')->where('match_status', 'matched')->value('amount_minor'))->toBe(5_000_000)
            ->and(DB::table('bank_statement_lines')->where('match_status', 'unmatched')->count())->toBe(2);
    });
});

it('matches one deposit to several receipts manually, and explains bank charges, under bank.match', function (): void {
    $clerk = userWithPermissions($this->ctx['tenant_id'], ['bank.import']);
    $matcher = userWithPermissions($this->ctx['tenant_id'], ['bank.match']);

    asTenant($this->ctx['tenant_id'], function () use ($clerk, $matcher): void {
        $first = ($this->bankReceipt)(2_000_000, '2026-09-15', 'AGENT DEPOSIT A');
        $second = ($this->bankReceipt)(3_000_000, '2026-09-15', 'AGENT DEPOSIT B');
        $other = ($this->bankReceipt)(1_000_000, '2026-09-15', 'LATER');
        app(StatementImport::class)->import($this->bankAccountId, statementCsv('2026-09-16,Agent AG-001 cash,,50000.00', '2026-09-30,Charges,,-150.00'), 'sept.csv', $clerk);
        $deposit = (string) DB::table('bank_statement_lines')->where('amount_minor', 5_000_000)->value('id');
        $charge = (string) DB::table('bank_statement_lines')->where('amount_minor', -15_000)->value('id');
        $matcherService = app(BankMatcher::class);

        expect(fn () => $matcherService->match($deposit, [$first, $second], $clerk))->toThrow(PermissionDenied::class)
            ->and(thrownBy(fn () => $matcherService->match($deposit, [$first], $matcher), BusinessRuleViolation::class)->reasonCode)->toBe('MATCH_AMOUNT_MISMATCH')
            ->and(thrownBy(fn () => $matcherService->match($deposit, [(string) DB::table('journal_lines')->where('role_code', 'suspense_receipts')->value('id')], $matcher), BusinessRuleViolation::class)->reasonCode)->toBe('JOURNAL_LINE_NOT_IN_BANK_ACCOUNT');

        $matcherService->match($deposit, [$first, $second], $matcher);
        expect(DB::table('bank_matches')->where('statement_line_id', $deposit)->where('method', 'manual')->count())->toBe(2)
            ->and(thrownBy(fn () => $matcherService->match($charge, [$first], $matcher), BusinessRuleViolation::class)->reasonCode)->toBe('JOURNAL_LINE_ALREADY_MATCHED')
            ->and(thrownBy(fn () => $matcherService->match($deposit, [$other], $matcher), BusinessRuleViolation::class)->reasonCode)->toBe('ALREADY_MATCHED')
            ->and(thrownBy(fn () => $matcherService->explain($charge, ' ', $matcher), BusinessRuleViolation::class)->reasonCode)->toBe('REASON_REQUIRED');

        $matcherService->explain($charge, 'September account maintenance fee', $matcher);
        $queue = app(BankReconciliationQuery::class)->unmatched($this->bankAccountId, CarbonImmutable::parse('2026-09-30'));

        expect(DB::table('bank_statement_lines')->where('id', $charge)->value('match_status'))->toBe('explained')
            ->and($queue['statement_lines'])->toBe([])
            ->and(array_column($queue['journal_lines'], 'journal_line_id'))->toBe([$other])
            ->and($queue['journal_lines'][0]['amount_minor'])->toBe(1_000_000)
            ->and($queue['journal_lines'][0]['reference'])->toBe('LATER');
    });
});

it('exposes import, matching and the unmatched queue over the API with the bank permissions', function (): void {
    $headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
    $importer = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['bank.import'])));
    $matcher = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['bank.match'])));
    $file = UploadedFile::fake()->createWithContent('sept.csv', statementCsv('2026-09-30,Charges,,-150.00'));

    Pest\Laravel\actingAs($matcher)->post("/api/finance/bank-accounts/{$this->bankAccountId}/statements", ['file' => $file], $headers)->assertForbidden();
    Pest\Laravel\actingAs($importer)->post("/api/finance/bank-accounts/{$this->bankAccountId}/statements", ['file' => $file], $headers)
        ->assertCreated()->assertJsonPath('data.imported', 1);
    $lineId = asTenant($this->ctx['tenant_id'], fn () => (string) DB::table('bank_statement_lines')->value('id'));

    Pest\Laravel\actingAs($importer)->postJson("/api/finance/bank-statement-lines/{$lineId}/explain", ['reason' => 'fee'], $headers)->assertForbidden();
    Pest\Laravel\actingAs($matcher)->postJson("/api/finance/bank-statement-lines/{$lineId}/explain", ['reason' => 'fee'], $headers)->assertOk();
    Pest\Laravel\actingAs($matcher)->postJson("/api/finance/bank-accounts/{$this->bankAccountId}/auto-match", [], $headers)->assertOk()->assertJsonPath('data.matched', 0);
    Pest\Laravel\actingAs($matcher)->getJson("/api/finance/bank-accounts/{$this->bankAccountId}/unmatched?as_of=2026-09-30", $headers)
        ->assertOk()->assertJsonPath('data.statement_lines', []);
});
