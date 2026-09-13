<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/** Slice 1C.11 screens: month-end close (design §5.7), reports drilling to journals, manual journals and reversals with maker ≠ checker. */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->admin = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $this->world['admin']));
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    $this->september = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('fiscal_periods')->where('starts', '2026-09-01')->value('id'));
    asTenant($this->ctx['tenant_id'], function (): void {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 5_000_000, 'BDT',
            CarbonImmutable::parse('2026-09-10'), null, 'r', [new AllocationLine((string) DB::table('installments')->value('id'), 5_000_000)]), $this->world['admin']);
    });
});

it('runs the month-end close from the close screens and reopens a locked period', function (): void {
    actingAs(($this->userWith)(['receipt.create']))->get('/close', $this->headers)->assertForbidden();
    actingAs($this->admin)->get('/close', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('close/Index')->has('periods', 12)
        ->where('periods.2.status', 'open')->where('periods.2.run', null));

    actingAs($this->admin)->post("/close/periods/{$this->september}", [], $this->headers)->assertSessionHasNoErrors();
    $runId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('period_close_runs')->value('id'));
    $task = fn (string $code): string => asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('period_close_tasks')->where('close_run_id', $runId)->where('code', $code)->value('id'));

    actingAs($this->admin)->post("/close/tasks/{$task('trial_balance')}/execute", [], $this->headers)->assertSessionHasErrors('form'); // waits for its dependencies
    foreach (['premium_earning', 'suspense_review', 'bank_reconciliation', 'premium_reconciliation', 'claims_reconciliation', 'commission_reconciliation'] as $code) {
        actingAs($this->admin)->post("/close/tasks/{$task($code)}/execute", [], $this->headers)->assertSessionHasNoErrors();
    }
    actingAs($this->admin)->post("/close/tasks/{$task('accruals')}/skip", ['reason' => 'No accruals this month'], $this->headers)->assertSessionHasNoErrors();
    foreach (['trial_balance', 'financial_statements', 'sign_off', 'period_lock'] as $code) {
        actingAs($this->admin)->post("/close/tasks/{$task($code)}/execute", [], $this->headers)->assertSessionHasNoErrors();
    }

    actingAs($this->admin)->get("/close/runs/{$runId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('close/Run')
        ->where('run.status', 'completed')->has('tasks', 11)->where('tasks.6.status', 'skipped')->where('tasks.0.summary', 'Every policy on cover has its earning row.'));

    actingAs($this->admin)->post("/close/periods/{$this->september}/reopen", ['reason' => 'Late invoice'], $this->headers)->assertSessionHasNoErrors();
    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('fiscal_periods')->where('id', $this->september)->value('status')))->toBe('open');
});

it('serves the reports to reports.financial, drilling from accounts to activity and journals', function (): void {
    $reader = ($this->userWith)(['reports.financial']);
    $clerk = ($this->userWith)(['policy.create']);
    $reports = ['premium-register?from=2026-09-01&to=2026-09-30', 'receivable-ageing?as_of=2026-09-30', 'outstanding-claims?as_of=2026-09-30', 'claims-paid?from=2026-09-01&to=2026-09-30',
        'loss-ratio?from=2026-09-01&to=2026-09-30&by=product', 'profit-and-loss?from=2026-09-01&to=2026-09-30', 'balance-sheet?as_of=2026-09-30'];

    actingAs($reader)->get('/reports', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('reports/Index')->has('reports'));
    foreach ($reports as $report) {
        actingAs($clerk)->get("/reports/{$report}", $this->headers)->assertForbidden();
        actingAs($reader)->get("/reports/{$report}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('reports/Show')->has('columns')->has('rows'));
    }

    $bs = actingAs($reader)->get('/reports/balance-sheet?as_of=2026-09-30', $this->headers);
    $bs->assertInertia(fn (AssertableInertia $page) => $page->where('title', 'Balance sheet')->where('rows.0.cells.code', '1010')->where('rows.0.cells.amount', '50,000.00')
        ->where('rows.0.link', fn (string $link): bool => str_starts_with($link, '/reports/account-activity?account_id=')));

    $accountId = asTenant($this->ctx['tenant_id'], fn (): string => $this->ctx['accounts']['bank_main']);
    actingAs($reader)->get("/reports/account-activity?account_id={$accountId}&to=2026-09-30", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('title', 'Account activity: 1010 Bank - Main')->has('rows', 1)->where('rows.0.link', fn (string $link): bool => str_starts_with($link, '/accounting/journals/')));
    actingAs($reader)->get('/reports/premium-register?from=2026-09-01&to=2026-09-30', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 1)->where('totals.gross', '120,000.00'));
});

it('creates a manual journal from the form, approves it by someone else and reverses it through a request', function (): void {
    $maker = ($this->userWith)(['accounting.view_journals', 'accounting.create_manual_journal', 'accounting.reverse_journal']);
    $checker = ($this->userWith)(['accounting.view_journals', 'accounting.approve_journal']);

    actingAs($maker)->get('/accounting/journals/create', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('accounting/journals/Create')->has('accounts')->has('branches', 1));
    actingAs($maker)->post('/accounting/journals', ['transaction_date' => '2026-09-25', 'description' => 'Office rent accrual', 'kind' => 'manual', 'reason' => 'September rent',
        'lines' => [
            ['account_id' => $this->ctx['accounts']['salary_expense'], 'side' => 'debit', 'amount' => '15,000.00', 'branch_id' => $this->ctx['branch_id'], 'memo' => 'rent'],
            ['account_id' => $this->ctx['accounts']['bank_main'], 'side' => 'credit', 'amount' => '15,000.00', 'branch_id' => $this->ctx['branch_id'], 'memo' => null],
        ]], $this->headers)->assertSessionHasNoErrors();
    $journalId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('journals')->where('description', 'Office rent accrual')->value('id'));

    actingAs($maker)->get("/accounting/journals/{$journalId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('journal.status', 'pending_approval')->where('actions.approve', false));
    actingAs($maker)->post("/accounting/journals/{$journalId}/approve", [], $this->headers)->assertSessionHasErrors('form');
    actingAs($checker)->get("/accounting/journals/{$journalId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('actions.approve', true));
    actingAs($checker)->post("/accounting/journals/{$journalId}/approve", [], $this->headers)->assertSessionHasNoErrors();

    actingAs($maker)->post("/accounting/journals/{$journalId}/reversal-requests", ['on' => '2026-09-28', 'reason' => 'Wrong account'], $this->headers)->assertSessionHasNoErrors();
    actingAs($checker)->get("/accounting/journals/{$journalId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('journal.status', 'posted')
        ->where('reversalRequest.status', 'pending')->where('actions.decideReversal', true));
    $requestId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('journal_reversal_requests')->value('id'));
    actingAs($checker)->post("/accounting/reversal-requests/{$requestId}/approve", [], $this->headers)->assertSessionHasNoErrors();
    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('journals')->where('id', $journalId)->value('status')))->toBe('reversed');
});
