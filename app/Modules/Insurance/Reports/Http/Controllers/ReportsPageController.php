<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Accounting\Application\Reports\FinancialStatementsQuery;
use App\Modules\Insurance\Reports\Application\ClaimsPaidRegisterQuery;
use App\Modules\Insurance\Reports\Application\LossRatioQuery;
use App\Modules\Insurance\Reports\Application\OutstandingClaimsQuery;
use App\Modules\Insurance\Reports\Application\PremiumRegisterQuery;
use App\Modules\Insurance\Reports\Application\ReceivableAgeingQuery;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Report screens (reports.financial). Every report renders through one table page: columns, rows (each optionally linking to account activity or
 * its journal) and totals, so figures always drill down to the journals behind them.
 */
final class ReportsPageController
{
    private const CATALOGUE = [
        ['key' => 'premium-register', 'title' => 'Premium register', 'description' => 'Written premium per policy transaction.', 'filter' => 'range'],
        ['key' => 'receivable-ageing', 'title' => 'Receivable ageing', 'description' => 'Unpaid installments by days past due.', 'filter' => 'as_of'],
        ['key' => 'outstanding-claims', 'title' => 'Outstanding claims', 'description' => 'Open reserves and approved-unpaid amounts per claim.', 'filter' => 'as_of'],
        ['key' => 'claims-paid', 'title' => 'Claims paid', 'description' => 'Claim payments released in a period.', 'filter' => 'range'],
        ['key' => 'loss-ratio', 'title' => 'Loss ratio', 'description' => 'Incurred claims over earned premium by product, branch or agent.', 'filter' => 'range_by'],
        ['key' => 'profit-and-loss', 'title' => 'Profit and loss', 'description' => 'Income and expense for a period.', 'filter' => 'range'],
        ['key' => 'balance-sheet', 'title' => 'Balance sheet', 'description' => 'Assets, liabilities and equity at a date.', 'filter' => 'as_of'],
    ];

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function index(Request $request): Response
    {
        $this->authorize($request);

        return Inertia::render('reports/Index', ['reports' => array_merge(self::CATALOGUE, [
            ['key' => null, 'title' => 'Suspense ageing', 'description' => 'Unidentified receipts by age.', 'filter' => null, 'href' => '/suspense'],
            ['key' => null, 'title' => 'Agent cash', 'description' => 'Collections, deposits and the ledger per agent.', 'filter' => null, 'href' => '/agent-cash'],
            ['key' => null, 'title' => 'Commission statements', 'description' => 'Per agent, with payouts.', 'filter' => null, 'href' => '/commission'],
            ['key' => null, 'title' => 'Trial balance', 'description' => 'Debits and credits per account.', 'filter' => null, 'href' => '/accounting/trial-balance'],
        ])]);
    }

    public function show(Request $request, string $report): Response
    {
        $this->authorize($request);
        $entity = PageSupport::entity();
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);
        $from = self::date($request, 'from', CarbonImmutable::today()->startOfMonth());
        $to = self::date($request, 'to', CarbonImmutable::today());
        $asOf = self::date($request, 'as_of', CarbonImmutable::today());
        $by = in_array($request->query('by'), LossRatioQuery::DIMENSIONS, true) ? (string) $request->query('by') : 'product';

        $page = match ($report) {
            'premium-register' => $this->premiumRegister($entity['id'], $from, $to, $money),
            'receivable-ageing' => $this->receivableAgeing($entity['id'], $asOf, $money),
            'outstanding-claims' => $this->outstandingClaims($entity['id'], $asOf, $money),
            'claims-paid' => $this->claimsPaid($entity['id'], $from, $to, $money),
            'loss-ratio' => $this->lossRatio($entity['id'], $from, $to, $by, $money),
            'profit-and-loss' => $this->profitAndLoss($entity['id'], $from, $to, $money),
            'balance-sheet' => $this->balanceSheet($entity['id'], $asOf, $money),
            'account-activity' => $this->accountActivity($entity['id'], $request, $from, $to, $money),
            default => abort(404),
        };

        return Inertia::render('reports/Show', $page + ['report' => $report, 'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'as_of' => $asOf->toDateString(), 'by' => $by]]);
    }

    /**
     * @param callable(int): string $money
     * @return array<string, mixed>
     */
    private function premiumRegister(string $entityId, CarbonImmutable $from, CarbonImmutable $to, callable $money): array
    {
        $result = app(PremiumRegisterQuery::class)->register($entityId, $from, $to);

        return self::table('Premium register', 'range', [['accounting_date', 'Date'], ['policy_number', 'Policy'], ['type', 'Transaction'], ['product_code', 'Product'], ['gross', 'Gross', 'right'], ['net', 'Net', 'right'], ['tax', 'Tax', 'right']],
            array_map(fn (array $r): array => ['cells' => ['accounting_date' => $r['accounting_date'], 'policy_number' => $r['policy_number'], 'type' => $r['type'], 'product_code' => $r['product_code'],
                'gross' => $money($r['gross_minor']), 'net' => $money($r['net_minor']), 'tax' => $money($r['tax_minor'])], 'link' => $r['journals'][0]['url'] ?? null], $result['rows']),
            ['gross' => $money($result['totals']['gross_minor']), 'net' => $money($result['totals']['net_minor']), 'tax' => $money($result['totals']['tax_minor'])]);
    }

    /**
     * @param callable(int): string $money
     * @return array<string, mixed>
     */
    private function receivableAgeing(string $entityId, CarbonImmutable $asOf, callable $money): array
    {
        $result = app(ReceivableAgeingQuery::class)->ageing($entityId, $asOf);

        return self::table('Receivable ageing', 'as_of', [['policy_number', 'Policy'], ['installment_no', 'Installment'], ['due_date', 'Due'], ['days_past_due', 'Days past due', 'right'], ['bucket', 'Bucket'], ['outstanding', 'Outstanding', 'right']],
            array_map(fn (array $r): array => ['cells' => ['policy_number' => $r['policy_number'], 'installment_no' => $r['installment_no'], 'due_date' => $r['due_date'], 'days_past_due' => $r['days_past_due'],
                'bucket' => $r['bucket'], 'outstanding' => $money($r['outstanding_minor'])], 'link' => "/policies/{$r['policy_id']}"], $result['rows']),
            ['outstanding' => $money($result['total_minor'])] + array_map($money, $result['buckets']));
    }

    /**
     * @param callable(int): string $money
     * @return array<string, mixed>
     */
    private function outstandingClaims(string $entityId, CarbonImmutable $asOf, callable $money): array
    {
        $result = app(OutstandingClaimsQuery::class)->outstanding($entityId, $asOf);

        return self::table('Outstanding claims', 'as_of', [['claim_number', 'Claim'], ['policy_number', 'Policy'], ['loss_date', 'Loss'], ['status', 'Status'], ['reserve', 'Open reserve', 'right'], ['unpaid', 'Approved unpaid', 'right']],
            array_map(fn (array $r): array => ['cells' => ['claim_number' => $r['claim_number'], 'policy_number' => $r['policy_number'], 'loss_date' => $r['loss_date'], 'status' => $r['status'],
                'reserve' => $money($r['outstanding_reserve_minor']), 'unpaid' => $money($r['approved_unpaid_minor'])], 'link' => "/claims/{$r['claim_id']}"], $result['rows']),
            ['reserve' => $money($result['totals']['outstanding_reserve_minor']), 'unpaid' => $money($result['totals']['approved_unpaid_minor'])]);
    }

    /**
     * @param callable(int): string $money
     * @return array<string, mixed>
     */
    private function claimsPaid(string $entityId, CarbonImmutable $from, CarbonImmutable $to, callable $money): array
    {
        $result = app(ClaimsPaidRegisterQuery::class)->register($entityId, $from, $to);

        return self::table('Claims paid', 'range', [['paid_on', 'Paid'], ['claim_number', 'Claim'], ['policy_number', 'Policy'], ['product_code', 'Product'], ['amount', 'Amount', 'right']],
            array_map(fn (array $r): array => ['cells' => ['paid_on' => $r['paid_on'], 'claim_number' => $r['claim_number'], 'policy_number' => $r['policy_number'], 'product_code' => $r['product_code'],
                'amount' => $money($r['amount_minor'])], 'link' => end($r['journals'])['url'] ?? null], $result['rows']),
            ['amount' => $money($result['totals']['amount_minor'])]);
    }

    /**
     * @param callable(int): string $money
     * @return array<string, mixed>
     */
    private function lossRatio(string $entityId, CarbonImmutable $from, CarbonImmutable $to, string $by, callable $money): array
    {
        $result = app(LossRatioQuery::class)->lossRatio($entityId, $from, $to, $by);
        $labels = \Illuminate\Support\Facades\DB::table(match ($by) { 'agent' => 'agents', 'branch' => 'branches', default => 'products' })->pluck('code', 'id');
        $ratio = fn (?int $bp): string => $bp === null ? '—' : sprintf('%d.%02d%%', intdiv($bp, 100), $bp % 100);

        return self::table("Loss ratio by {$by}", 'range_by', [['group', ucfirst($by)], ['earned', 'Earned premium', 'right'], ['incurred', 'Incurred claims', 'right'], ['ratio', 'Loss ratio', 'right']],
            array_map(fn (array $r): array => ['cells' => ['group' => $r['dimension_value'] === null ? 'None' : (string) ($labels[$r['dimension_value']] ?? $r['dimension_value']),
                'earned' => $money($r['earned_premium_minor']), 'incurred' => $money($r['incurred_claims_minor']), 'ratio' => $ratio($r['loss_ratio_bp'])],
                'link' => isset($r['drill']['claims_expense']) ? self::activityLink($r['drill']['claims_expense']) : null], $result['rows']),
            ['earned' => $money($result['totals']['earned_premium_minor']), 'incurred' => $money($result['totals']['incurred_claims_minor']), 'ratio' => $ratio($result['totals']['loss_ratio_bp'])]);
    }

    /**
     * @param callable(int): string $money
     * @return array<string, mixed>
     */
    private function profitAndLoss(string $entityId, CarbonImmutable $from, CarbonImmutable $to, callable $money): array
    {
        $result = app(FinancialStatementsQuery::class)->profitAndLoss($entityId, $from, $to);
        $rows = [];
        foreach (['income' => 'Income', 'expense' => 'Expense'] as $section => $label) {
            foreach ($result[$section] as $r) {
                $rows[] = ['cells' => ['section' => $label, 'code' => $r['code'], 'name' => $r['name'], 'amount' => $money($r['amount_minor'])], 'link' => self::activityLink($r['url'])];
            }
        }

        return self::table('Profit and loss', 'range', [['section', 'Section'], ['code', 'Account'], ['name', 'Name'], ['amount', 'Amount', 'right']], $rows,
            ['income' => $money($result['total_income_minor']), 'expense' => $money($result['total_expense_minor']), 'amount' => $money($result['net_profit_minor'])]);
    }

    /**
     * @param callable(int): string $money
     * @return array<string, mixed>
     */
    private function balanceSheet(string $entityId, CarbonImmutable $asOf, callable $money): array
    {
        $result = app(FinancialStatementsQuery::class)->balanceSheet($entityId, $asOf);
        $rows = [];
        foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'] as $section => $label) {
            foreach ($result[$section] as $r) {
                $rows[] = ['cells' => ['section' => $label, 'code' => $r['code'], 'name' => $r['name'], 'amount' => $money($r['amount_minor'])], 'link' => self::activityLink($r['url'])];
            }
        }
        $rows[] = ['cells' => ['section' => 'Equity', 'code' => '', 'name' => 'Current earnings', 'amount' => $money($result['current_earnings_minor'])], 'link' => null];

        return self::table('Balance sheet', 'as_of', [['section', 'Section'], ['code', 'Account'], ['name', 'Name'], ['amount', 'Amount', 'right']], $rows,
            ['assets' => $money($result['total_assets_minor']), 'liabilities_and_equity' => $money($result['total_liabilities_minor'] + $result['total_equity_minor'] + $result['current_earnings_minor'])]);
    }

    /**
     * @param callable(int): string $money
     * @return array<string, mixed>
     */
    private function accountActivity(string $entityId, Request $request, CarbonImmutable $from, CarbonImmutable $to, callable $money): array
    {
        $accountId = (string) $request->query('account_id', '');
        $dimension = in_array($request->query('dimension'), ['branch', 'product', 'agent', 'policy', 'claim', 'customer', 'channel', 'lob'], true) ? (string) $request->query('dimension') : null;
        $result = app(FinancialStatementsQuery::class)->accountActivity($entityId, $accountId, $request->query('from') === null ? null : $from, $to, $dimension,
            $dimension === null ? null : (string) $request->query('value', ''));
        abort_if($result['account'] === null, 404);

        return self::table("Account activity: {$result['account']['code']} {$result['account']['name']}", 'range', [['posting_date', 'Date'], ['journal_number', 'Journal'], ['description', 'Description'], ['debit', 'Debit', 'right'], ['credit', 'Credit', 'right']],
            array_map(fn (array $l): array => ['cells' => ['posting_date' => $l['posting_date'], 'journal_number' => $l['journal_number'], 'description' => $l['description'] ?? $l['memo'],
                'debit' => $money($l['debit_minor']), 'credit' => $money($l['credit_minor'])], 'link' => $l['url']], $result['lines']),
            ['opening' => $money($result['opening_minor']), 'closing' => $money($result['closing_minor'])]);
    }

    /**
     * @param list<array{0: string, 1: string, 2?: string}> $columns
     * @param list<array{cells: array<string, mixed>, link: string|null}> $rows
     * @param array<string, string> $totals
     * @return array<string, mixed>
     */
    private static function table(string $title, string $filter, array $columns, array $rows, array $totals): array
    {
        return ['title' => $title, 'filter' => $filter, 'columns' => array_map(fn (array $c): array => ['key' => $c[0], 'label' => $c[1], 'align' => $c[2] ?? 'left'], $columns),
            'rows' => $rows, 'totals' => $totals];
    }

    /** API drill URL → the account activity report page with the same filters. */
    private static function activityLink(string $apiUrl): string
    {
        $parts = parse_url($apiUrl);
        preg_match('#/accounts/([0-9a-f-]{36})/activity#', (string) ($parts['path'] ?? ''), $match);
        parse_str((string) ($parts['query'] ?? ''), $query);
        unset($query['entity_id']);

        return '/reports/account-activity?'.http_build_query(['account_id' => $match[1] ?? ''] + $query);
    }

    private function authorize(Request $request): void
    {
        $this->permissions->authorize(PageSupport::actor($request), 'reports.financial');
    }

    private static function date(Request $request, string $key, CarbonImmutable $default): CarbonImmutable
    {
        $value = $request->query($key);

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::parse($value) : $default;
    }
}
