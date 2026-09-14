<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * UX brief §1.1 and §5 (slice U6): every role's home is a list of work queues — title, count, top five rows, a link to the queue — and the
 * sidebar badges are the same counts for the same user. A user holding several roles sees each role's queues once.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-13 10:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->asRole = function (string ...$codes): User {
        return asTenant($this->ctx['tenant_id'], function () use ($codes): User {
            $id = (string) Str::uuid7();
            DB::table('users')->insert(['id' => $id, 'tenant_id' => $this->ctx['tenant_id'], 'email' => $id.'@demo.test', 'name' => implode(' + ', $codes),
                'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            foreach ($codes as $code) {
                DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $code)->value('id'),
                    'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);
            }

            return User::query()->findOrFail($id);
        });
    };

    asTenant($this->ctx['tenant_id'], function (): void {
        $admin = $this->world['admin'];
        $lifecycle = app(PolicyLifecycle::class);
        $quote = fn (string $inception, int $count = 1) => $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], null, CarbonImmutable::parse($inception), 12_000_000, 'BDT', $count), $admin);
        $quote('2026-09-20');                                          // a quote to follow up
        $dueThisWeek = $quote('2026-09-15');                           // first installment due 15 Sep
        $lifecycle->issue($dueThisWeek->id, CarbonImmutable::parse('2026-09-15'), $admin);
        $lapsing = $quote('2026-08-01', 12);                           // first installment 1 Aug unpaid: 43 days overdue
        $lifecycle->issue($lapsing->id, CarbonImmutable::parse('2026-08-01'), $admin);

        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 250_000, 'BDT',
            CarbonImmutable::parse('2026-09-02'), null, 'unreadable', []), $admin);  // suspense

        DB::table('bank_accounts')->insert(['id' => $bankId = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
            'gl_account_id' => $this->ctx['accounts']['bank_main'], 'bank_name' => 'City Bank', 'account_no_masked' => '****1', 'currency' => 'BDT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bank_statement_lines')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'bank_account_id' => $bankId, 'posted_on' => '2026-09-05',
            'amount_minor' => 1_800_000, 'reference' => 'TT 1', 'description' => 'Deposit', 'raw' => '{}', 'line_hash' => 'h1', 'source_file' => 'f.csv', 'match_status' => 'unmatched',
            'imported_by' => $admin, 'imported_at' => now()]);

        $maker = userWithPermissions($this->ctx['tenant_id'], ['accounting.create_manual_journal']);
        $journals = app(ManualJournalService::class);
        $journal = $journals->create(new ManualJournalRequest($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-10'), 'Rent', JournalKind::Manual, 'Rent', 'BDT', [
            new ManualJournalLine($this->ctx['accounts']['salary_expense'], Side::Debit, 100_000, ['branch' => $this->ctx['branch_id']]),
            new ManualJournalLine($this->ctx['accounts']['bank_main'], Side::Credit, 100_000, ['branch' => $this->ctx['branch_id']]),
        ]), $maker);
        $journals->submit($journal->id, $maker);

        $claims = app(ClaimService::class);
        $claims->register($lapsing->id, CarbonImmutable::parse('2026-09-01'), 'Awaiting reserve', $admin, CarbonImmutable::parse('2026-09-02'));
        $reserved = $claims->register($lapsing->id, CarbonImmutable::parse('2026-08-10'), 'Collision', $admin, CarbonImmutable::parse('2026-08-11'));
        $claims->reserve($reserved->id, 5_000_000, 'Initial', userWithPermissions($this->ctx['tenant_id'], ['claim.reserve']), CarbonImmutable::parse('2026-08-12'));
        $toSettle = $claims->register($lapsing->id, CarbonImmutable::parse('2026-08-20'), 'Windscreen', $admin, CarbonImmutable::parse('2026-08-21')); // reserved, no payment yet
        $claims->reserve($toSettle->id, 800_000, 'Initial', userWithPermissions($this->ctx['tenant_id'], ['claim.reserve']), CarbonImmutable::parse('2026-08-22'));
        $payment = app(ClaimPaymentService::class)->approve($reserved->id, 2_000_000, $this->world['policyholder_id'], userWithPermissions($this->ctx['tenant_id'], ['claim.approve']), CarbonImmutable::parse('2026-08-20'));
        app(ClaimPaymentService::class)->requestRelease($payment->id, userWithPermissions($this->ctx['tenant_id'], ['claim.pay_request']), null);

        DB::table('reconciliation_runs')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'subledger' => 'premium',
            'period_id' => DB::table('fiscal_periods')->where('starts', '2026-08-01')->value('id'), 'run_at' => now(), 'subledger_balance_minor' => 100, 'gl_balance_minor' => 0,
            'variance_minor' => 100, 'status' => 'variance']);
    });
});

it('gives every seeded role its own work queues', function (string $role, array $titles): void {
    actingAs(($this->asRole)($role))->get('/home', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('home/Index')
        ->where('queues', fn ($queues): bool => array_column(json_decode((string) json_encode($queues), true), 'title') === $titles));
})->with([
    'branch officer' => ['branch_officer', ['Installments due this week', 'Lapsing policies', 'Receipts to record', 'Quotes to follow up']],
    'branch manager' => ['branch_manager', ['Installments due this week', 'Lapsing policies', 'Receipts to record', 'Quotes to follow up']],
    'accountant' => ['accountant', ['Unallocated receipts', 'Unmatched bank lines', 'Journals awaiting my approval', 'Failed accounting events']],
    'claims officer' => ['claims_officer', ['Claims awaiting reserve', 'Awaiting my approval', 'Payments to release']],
    'claims manager' => ['claims_manager', ['Claims awaiting reserve', 'Claims to settle', 'Awaiting my approval', 'Payments to release']],
    'finance manager' => ['finance_manager', ['Close progress', 'Reconciliation variances', 'Approvals over threshold', 'Cash position', 'Payments to release']],
    'cfo' => ['cfo', ['Close progress', 'Reconciliation variances', 'Approvals over threshold', 'Cash position', 'Payments to release']],
    'auditor' => ['auditor', ['Recent reversals and adjustments', 'Period reopen events', 'Control-account manual postings']],
    'tenant admin' => ['tenant_admin', []],
]);

it('counts and lists what needs action, and the sidebar badges show the same counts', function (): void {
    $officer = ($this->asRole)('branch_officer');
    actingAs($officer)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues.0.key', 'installments_due')->where('queues.0.count', 1)->where('queues.0.rows.0.cells.due', '2026-09-15')->where('queues.0.href', '/receipts/create')
        ->where('queues.1.key', 'lapsing_policies')->where('queues.1.count', 1)
        ->where('queues.2.key', 'receipts_to_record')->where('queues.2.count', 1)->where('queues.2.rows.0.cells.amount', '18,000.00')
        ->where('queues.3.key', 'quotes')->where('queues.3.count', 1)
        ->where('shell.badges.policies', 1)->where('shell.badges.bank', 1));

    $accountant = ($this->asRole)('accountant');
    actingAs($accountant)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues.0.count', 1)->where('queues.0.rows.0.cells.amount', '2,500.00')
        ->where('queues.1.count', 1)->where('queues.2.count', 0)->where('queues.3.count', 0) // the accountant template cannot approve journals (§7.2)
        ->where('shell.badges.suspense', 1)->where('shell.badges.bank', 1));
    actingAs(($this->asRole)('accountant', 'finance_manager'))->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues.2.key', 'journals_to_approve')->where('queues.2.count', 1)->where('queues.2.rows.0.cells.description', 'Rent')->where('shell.badges.journals', 1));

    $claims = ($this->asRole)('claims_manager');
    actingAs($claims)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues.0.count', 1)->where('queues.1.key', 'claims_to_settle')->where('queues.1.count', 1)->where('queues.1.rows.0.cells.reserve', '8,000.00')
        ->where('queues.3.key', 'payments_to_release')->where('queues.3.count', 1)->where('shell.badges.claims', 3));

    $finance = ($this->asRole)('finance_manager');
    actingAs($finance)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues.1.count', 1)->where('queues.1.rows.0.cells.variance', '1.00')->where('queues.3.cash.balance', fn (string $balance): bool => $balance !== '')
        ->has('queues.3.cash.days', 30)->where('queues.4.key', 'payments_to_release')->where('queues.4.count', 1)->where('queues.4.rows.0.cells.status', 'release_requested')
        ->where('shell.badges.close', 1)->where('shell.badges.claims', 1));
});

it('shows each queue once for a user with several roles, and lands everyone on home after sign-in', function (): void {
    actingAs(($this->asRole)('branch_manager', 'branch_officer', 'accountant'))->get('/home', $this->headers)
        ->assertInertia(fn (AssertableInertia $page) => $page->has('queues', 8));
    expect(config('fortify.home'))->toBe('/home');
    actingAs(($this->asRole)('auditor'))->get('/', $this->headers)->assertRedirect('/home');
});
