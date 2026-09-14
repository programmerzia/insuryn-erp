<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\travelTo;

/**
 * Session S2: `php artisan erp:demo` seeds exactly the market cross-check Part A story ("a week in a non-life insurer") in its own tenant, through
 * the application services — 4 products, 5 customers, 2 producers (one on commission through a compensation scheme, one salaried with none), 8 policies at
 * different stages sold through quotation, proposal and policy on the placeholder tariffs (Phase 3 R7), a quotation to follow up, a cover note, and (GA-35) a
 * Chittagong branch whose branch-scoped officer sold a short-period policy expiring within 20 days that has its renewal quotation,
 * receipts including one still in suspense, a bank statement CSV with matches and 2 exceptions, 2 claims (one paid, one reserved), August
 * closed and locked, September open. Rerunning changes nothing; it runs in local and staging only.
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-13 10:00'));
    $this->count = function (string $tenantId): array {
        return asTenant($tenantId, fn (): array => [
            'products' => DB::table('products')->count(),
            'customers' => DB::table('party_roles')->where('role', 'customer')->count(),
            'producers' => DB::table('producers')->count(),
            'policies' => DB::table('policies')->count(),
            'receipts' => DB::table('receipts')->count(),
            'journals' => DB::table('journals')->count(),
            'statement_lines' => DB::table('bank_statement_lines')->count(),
            'claims' => DB::table('claims')->count(),
        ]);
    };
});

it('seeds the Part A story through the services', function (): void {
    expect(Artisan::call('erp:demo'))->toBe(0);
    $tenantId = (string) DB::table('tenants')->where('slug', 'nonlife')->value('id');

    expect(($this->count)($tenantId))->toMatchArray(['products' => 4, 'customers' => 5, 'producers' => 2, 'policies' => 8, 'claims' => 2]);
    asTenant($tenantId, function (): void {
        $policies = DB::table('policies')->pluck('status')->countBy()->all();
        expect(array_keys($policies))->toContain('cancelled')
            ->and(($policies['issued'] ?? 0) + ($policies['active'] ?? 0))->toBe(7);
        // Phase 3 R7: the products are rated, so the quote to follow up is an issued quotation (a typed-premium quote is refused), and every policy was issued
        // from its approved proposal on its frozen rating, with stamp duty on its own line.
        expect(DB::table('quotations')->where('status', 'issued')->whereNull('renewal_of_policy_id')->count())->toBe(1)->and(DB::table('policies')->where('status', 'quote')->count())->toBe(0)
            ->and(DB::table('policies')->whereNull('rating_result')->orWhereNull('proposal_id')->count())->toBe(0)
            ->and(DB::table('proposals')->where('status', 'issued')->count())->toBe(8)
            ->and(DB::table('policies')->where('stamp_duty_minor', '>', 0)->count())->toBe(8)
            ->and((int) DB::table('policies')->sum(DB::raw('gross_premium_minor - net_premium_minor - tax_minor - stamp_duty_minor')))->toBe(0);

        // One commission producer accrues commission on receipts, through the compensation scheme (GA-35); the salaried one has none.
        expect(DB::table('commission_entries')->distinct()->count('agent_id'))->toBe(1)
            ->and(DB::table('commission_entries')->whereNull('scheme_id')->count())->toBe(0)
            ->and(DB::table('compensation_schemes')->pluck('code')->all())->toBe(['AGENCY-NL'])
            ->and(DB::table('commission_plans')->count())->toBe(0);

        // GA-35: a second branch with a branch-scoped officer; a policy expiring within 20 days of the story's last day with its renewal quotation; a cover note.
        $ctg = (string) DB::table('branches')->where('code', 'CTG')->value('id');
        $officer = DB::table('users')->where('email', 'branch.officer.ctg@nonlife.local')->value('id');
        expect(DB::table('user_roles')->where('user_id', $officer)->get(['scope_type', 'scope_id'])->map(fn (object $r): array => [$r->scope_type, $r->scope_id])->all())->toBe([['branch', $ctg]]);
        $expiring = DB::table('policies')->where('branch_id', $ctg)->first(['id', 'number', 'expiry']);
        expect($expiring?->number)->toStartWith('POL-CTG-2026-')
            ->and($expiring?->expiry)->toBe('2026-10-02')
            ->and(DB::table('expiry_register')->where('policy_id', $expiring?->id)->value('status'))->toBe('renewal_offered')
            ->and(DB::table('quotations')->where('renewal_of_policy_id', $expiring?->id)->where('status', 'issued')->count())->toBe(1)
            ->and(DB::table('cover_notes')->where('status', 'active')->count())->toBe(1)
            ->and(DB::table('proposals')->where('status', 'approved')->count())->toBe(1);

        // GA-35: the opening bank balance is paid-up share capital, not retained earnings.
        $opening = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.description', 'Bank balance brought forward')->where('l.side', 'credit')->get(['a.code', 'a.name', 'l.amount_minor']);
        expect($opening->map(fn (object $l): array => [$l->code, $l->name, (int) $l->amount_minor])->all())->toBe([['3000', 'Share Capital', 200_000_000]]);

        // Receipts: one still unallocated in suspense, one that came in without a reference and was allocated from suspense.
        expect(DB::table('suspense_items')->where('status', 'open')->count())->toBe(1)
            ->and(DB::table('suspense_items')->where('status', '!=', 'open')->count())->toBe(1);

        // Bank: August fully matched; September has lines to match and exactly two exceptions nothing in the ledger can explain.
        $september = DB::table('bank_statement_lines')->where('posted_on', '>=', '2026-09-01')->where('match_status', 'unmatched')->get(['id', 'amount_minor']);
        expect(DB::table('bank_statement_lines')->where('posted_on', '<', '2026-09-01')->where('match_status', 'unmatched')->count())->toBe(0)
            ->and($september->count())->toBeGreaterThanOrEqual(4);
        $bankAccount = (string) DB::table('bank_accounts')->value('id');
        $suggested = collect(app(App\Modules\Finance\Bank\Application\BankMatcher::class)->suggestions($bankAccount, CarbonImmutable::parse('2026-09-30')))->pluck('statement_line_id')->unique();
        expect($september->pluck('id')->diff($suggested)->count())->toBe(2);

        // Claims: one paid and closed (leftover reserve released), one reserved and open.
        expect(DB::table('claims')->orderBy('status')->pluck('status')->all())->toBe(['closed', 'reserved'])
            ->and((int) DB::table('claim_payments')->where('status', 'paid')->sum('amount_minor'))->toBe(18_000_000);

        // August closed and locked, September open.
        expect(DB::table('fiscal_periods')->where('starts', '2026-08-01')->value('status'))->toBe('locked')
            ->and(DB::table('fiscal_periods')->where('starts', '2026-09-01')->value('status'))->toBe('open')
            ->and(DB::table('period_close_runs')->where('status', 'completed')->count())->toBe(1);
    });
    expect(File::exists(storage_path('app/demo/city-bank-2026-09.csv')))->toBeTrue()
        ->and(substr_count((string) File::get(storage_path('app/demo/city-bank-2026-09.csv')), "\n"))->toBeGreaterThanOrEqual(5);

    // Fix F3: the default approval limits are set after the story (which kept its own approvals).
    expect(asTenant($tenantId, fn (): array => DB::table('approval_policies')->orderBy('object_type')->pluck('effective_from', 'object_type')->all()))
        ->toBe(['claim_payment' => '2026-09-13', 'claim_payment_release' => '2026-09-13', 'journal' => '2026-09-13', 'journal_reversal' => '2026-09-13']);
});

it('runs the nightly lifecycle once, so the demo shows active policies, payment reminders and renewals and when the jobs ran (GA-05)', function (): void {
    expect(Artisan::call('erp:demo'))->toBe(0);
    $tenantId = (string) DB::table('tenants')->where('slug', 'nonlife')->value('id');

    asTenant($tenantId, function (): void {
        // Every policy whose cover has started (all of the story's, dated August–September) is Active, none left Issued; the cancelled one stays cancelled.
        expect(DB::table('policies')->where('status', 'issued')->where('inception', '<=', '2026-09-13')->count())->toBe(0)
            ->and(DB::table('policies')->where('status', 'active')->count())->toBe(7) // the story's six plus the CTG short-period fire policy (GA-35)
            // POL-2's second installment has been overdue since 5 September: its first reminder is out.
            ->and(DB::table('dunning_notices')->count())->toBeGreaterThan(0);
        $runs = DB::table('job_runs')->orderBy('job')->get(['job', 'status', 'triggered_by']);
        expect($runs->pluck('job')->unique()->values()->all())->toBe(['dunning', 'policy_lifecycle', 'renewals'])
            ->and($runs->pluck('status')->unique()->all())->toBe(['succeeded'])
            ->and($runs->whereNotNull('triggered_by')->count())->toBe(0);
    });
});

it('changes nothing when run again', function (): void {
    expect(Artisan::call('erp:demo'))->toBe(0);
    $tenantId = (string) DB::table('tenants')->where('slug', 'nonlife')->value('id');
    $before = ($this->count)($tenantId);

    expect(Artisan::call('erp:demo'))->toBe(0)
        ->and(Artisan::output())->toContain('already');
    expect(($this->count)($tenantId))->toBe($before)
        ->and(DB::table('tenants')->where('slug', 'nonlife')->count())->toBe(1);
});

it('refuses to run outside local and staging', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    expect(Artisan::call('erp:demo'))->toBe(1);
    expect(DB::table('tenants')->where('slug', 'nonlife')->exists())->toBeFalse();
});
