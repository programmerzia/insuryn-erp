<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\travelTo;

/**
 * Session S2: `php artisan erp:demo` seeds exactly the market cross-check Part A story ("a week in a non-life insurer") in its own tenant, through
 * the application services — 3 products, 5 customers, 2 producers (one on commission, one salaried with none), 7 policies at different stages sold through
 * quotation, proposal and policy on the placeholder tariffs (Phase 3 R7) and a quotation to follow up,
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

    expect(($this->count)($tenantId))->toMatchArray(['products' => 3, 'customers' => 5, 'producers' => 2, 'policies' => 7, 'claims' => 2]);
    asTenant($tenantId, function (): void {
        $policies = DB::table('policies')->pluck('status')->countBy()->all();
        expect(array_keys($policies))->toContain('cancelled')
            ->and(($policies['issued'] ?? 0) + ($policies['active'] ?? 0))->toBe(6);
        // Phase 3 R7: the products are rated, so the quote to follow up is an issued quotation (a typed-premium quote is refused), and every policy was issued
        // from its approved proposal on its frozen rating, with stamp duty on its own line.
        expect(DB::table('quotations')->where('status', 'issued')->count())->toBe(1)->and(DB::table('policies')->where('status', 'quote')->count())->toBe(0)
            ->and(DB::table('policies')->whereNull('rating_result')->orWhereNull('proposal_id')->count())->toBe(0)
            ->and(DB::table('proposals')->where('status', 'issued')->count())->toBe(7)
            ->and(DB::table('policies')->where('stamp_duty_minor', '>', 0)->count())->toBe(7)
            ->and((int) DB::table('policies')->sum(DB::raw('gross_premium_minor - net_premium_minor - tax_minor - stamp_duty_minor')))->toBe(0);

        // One commission producer accrues commission on receipts; the salaried one has none.
        expect(DB::table('commission_entries')->distinct()->count('agent_id'))->toBe(1);

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
