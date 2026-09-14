<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Http\Controllers;

use App\Http\Pages\JournalSources;
use App\Http\Pages\PageSupport;
use App\Modules\Accounting\Application\Reports\FinancialStatementsQuery;
use App\Modules\Insurance\Reports\Application\ClaimsPaidRegisterQuery;
use App\Modules\Insurance\Reports\Application\ExpiryRegisterReportQuery;
use App\Modules\Insurance\Reports\Application\RenewalConversionQuery;
use App\Modules\Insurance\Reports\Application\LossRatioQuery;
use App\Modules\Insurance\Reports\Application\OutstandingClaimsQuery;
use App\Modules\Insurance\Reports\Application\PremiumRegisterQuery;
use App\Modules\Insurance\Reports\Application\ReceivableAgeingQuery;
use App\Modules\Insurance\Reports\Application\UnearnedPremiumQuery;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exports\XlsxWriter;
use App\Modules\Platform\Money\MinorUnits;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report screens (reports.financial). Every report renders through one table page: columns, rows (each optionally linking to account activity or
 * its journal, and cells such as a policy number to their record) and totals, so figures always drill down to the journals behind them. Summary
 * tables under the rows give subtotals (by class, branch or product) and reconciliations to a control account.
 * Flow fix X12: each report's table downloads as CSV or XLSX (GET /reports/{report}/export) with the same filters and defaults, linked from the index.
 */
final class ReportsPageController
{
    private const CATALOGUE = [
        ['key' => 'premium-register', 'title' => 'Premium register', 'description' => 'Written premium per policy transaction, with totals by class and branch.', 'filter' => 'range'],
        ['key' => 'unearned-premium', 'title' => 'Unearned premium', 'description' => 'Unearned premium per policy at a date, by class and product, reconciled to the ledger.', 'filter' => 'as_of'],
        ['key' => 'receivable-ageing', 'title' => 'Receivable ageing', 'description' => 'Unpaid installments by days past due.', 'filter' => 'as_of'],
        ['key' => 'outstanding-claims', 'title' => 'Outstanding claims', 'description' => 'Open reserves and approved-unpaid amounts per claim.', 'filter' => 'as_of'],
        ['key' => 'claims-paid', 'title' => 'Claims paid', 'description' => 'Claim payments released in a period.', 'filter' => 'range'],
        ['key' => 'loss-ratio', 'title' => 'Loss ratio', 'description' => 'Incurred claims over earned premium by product, branch or agent.', 'filter' => 'range_by'],
        // Phase 3 slice R9 (design §4 reports).
        ['key' => 'expiry-register', 'title' => 'Expiry register', 'description' => 'Policies expiring after a date, by bucket, branch and producer.', 'filter' => 'as_of'],
        ['key' => 'renewal-conversion', 'title' => 'Renewal conversion', 'description' => 'Policies expiring in a period renewed or lost, by branch, agent or product, with lapse reasons.', 'filter' => 'range_by'],
        ['key' => 'profit-and-loss', 'title' => 'Profit and loss', 'description' => 'Income and expense for a period.', 'filter' => 'range'],
        ['key' => 'balance-sheet', 'title' => 'Balance sheet', 'description' => 'Assets, liabilities and equity at a date.', 'filter' => 'as_of'],
        // Reinsurance MVP (G4): bordereaux (ReinsuranceReportTables::CATALOGUE).
        ['key' => 'ri-premium-bordereau', 'title' => 'Premium bordereau', 'description' => 'Premium ceded to each reinsurer in a period, per policy, with commission and the net due.', 'filter' => 'range'],
        ['key' => 'ri-claims-bordereau', 'title' => 'Claims bordereau', 'description' => 'Reinsurers\' shares of claim reserves and payments recorded in a period.', 'filter' => 'range'],
        // Slice 2.3 accounts payable.
        ['key' => 'ap-ageing', 'title' => 'AP ageing', 'description' => 'What is owed to suppliers per bill at a date, by days past due, reconciled to accounts payable.', 'filter' => 'as_of'],
    ];

    /** Gap fix GA-12 (ASSUMPTION A-175): the reports a claims desk reads with reports.claims alone; every other report needs reports.financial. */
    public const CLAIMS_REPORTS = ['outstanding-claims', 'claims-paid', 'loss-ratio'];

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function index(Request $request): Response
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), ['reports.financial', 'reports.claims']);
        $financial = $this->permissions->has(PageSupport::actor($request), 'reports.financial');
        $catalogue = $financial ? self::CATALOGUE : array_values(array_filter(self::CATALOGUE, fn (array $r): bool => in_array($r['key'], self::CLAIMS_REPORTS, true)));

        $exports = array_map(fn (array $r): array => $r + ['exports' => ['csv' => "/reports/{$r['key']}/export?format=csv", 'xlsx' => "/reports/{$r['key']}/export?format=xlsx"]], $catalogue);
        if (! $financial) {
            return Inertia::render('reports/Index', ['reports' => $exports]);
        }

        // Gap audit GA-34: the registers on their own screens open there, and export through the same path as the reports above.
        $screens = [];
        foreach (OperationalReportTables::REPORTS as $key => [$title, $description, , $href]) {
            $screens[] = ['key' => null, 'title' => $title, 'description' => $description, 'filter' => null, 'href' => $href,
                'exports' => ['csv' => "/reports/{$key}/export?format=csv", 'xlsx' => "/reports/{$key}/export?format=xlsx"]];
        }

        return Inertia::render('reports/Index', ['reports' => array_merge($exports, $screens)]);
    }

    public function show(Request $request, string $report): Response
    {
        $financial = $this->authorize($request, $report);
        [$page, $filters] = $this->page($request, $report);
        if (! $financial) {
            $page = self::claimsLinksOnly($page);
        }

        return Inertia::render('reports/Show', $page + ['report' => $report, 'filters' => $filters]);
    }

    /** Flow fix X12: the report's table as a CSV or XLSX download — the columns and rows the page shows, on the same filters (defaults: this month, as of today). */
    public function export(Request $request, string $report): StreamedResponse
    {
        $this->authorize($request, $report);
        /** @var array{format: string} $data */
        $data = $request->validate(['format' => ['required', Rule::in(['csv', 'xlsx'])]]);
        abort_unless(in_array($report, array_column(self::CATALOGUE, 'key'), true) || isset(OperationalReportTables::REPORTS[$report]), 404);
        [$page, $filters] = $this->page($request, $report);
        /** @var list<array{key: string, label: string, align: string}> $columns */
        $columns = $page['columns'];
        /** @var list<array{cells: array<string, mixed>}> $rows */
        $rows = $page['rows'];
        $header = array_column($columns, 'label');
        $table = array_map(fn (array $row): array => array_map(fn (array $c): string => is_scalar($row['cells'][$c['key']] ?? null) ? (string) $row['cells'][$c['key']] : '', $columns), $rows);
        $period = in_array($page['filter'], ['range', 'range_by'], true) ? "{$filters['from']}-to-{$filters['to']}" : $filters['as_of'];
        [$body, $type] = $data['format'] === 'xlsx'
            ? [XlsxWriter::workbook(mb_substr((string) $page['title'], 0, 31), $header, $table), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
            : [self::csv($header, $table), 'text/csv; charset=UTF-8'];

        return response()->streamDownload(function () use ($body): void {
            echo $body;
        }, "{$report}-{$period}.{$data['format']}", ['Content-Type' => $type]);
    }

    /**
     * @param list<string> $header
     * @param list<list<string>> $rows
     */
    private static function csv(array $header, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open a temporary stream for the report export.');
        }
        foreach ([$header, ...$rows] as $line) {
            fputcsv($stream, $line, escape: '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * The report's table and the filters it was built on.
     *
     * @return array{0: array<string, mixed>, 1: array{from: string, to: string, as_of: string, by: string}}
     */
    private function page(Request $request, string $report): array
    {
        $entity = PageSupport::entity();
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);
        $from = self::date($request, 'from', app(BusinessClock::class)->today()->startOfMonth());
        $to = self::date($request, 'to', app(BusinessClock::class)->today());
        $asOf = self::date($request, 'as_of', app(BusinessClock::class)->today());
        $by = in_array($request->query('by'), LossRatioQuery::DIMENSIONS, true) ? (string) $request->query('by') : 'product';
        if ($report === 'renewal-conversion' && ! in_array($request->query('by'), RenewalConversionQuery::DIMENSIONS, true)) {
            $by = 'branch'; // slice R9: conversion is read by branch first
        }

        $page = match ($report) {
            'premium-register' => $this->premiumRegister($entity['id'], $from, $to, $money),
            'unearned-premium' => $this->unearnedPremium($entity['id'], $asOf, $money),
            'receivable-ageing' => $this->receivableAgeing($entity['id'], $asOf, $money),
            'outstanding-claims' => $this->outstandingClaims($entity['id'], $asOf, $money),
            'claims-paid' => $this->claimsPaid($entity['id'], $from, $to, $money),
            'loss-ratio' => $this->lossRatio($entity['id'], $from, $to, $by, $money),
            'expiry-register' => $this->expiryRegister($entity['id'], $asOf, $money),
            'renewal-conversion' => $this->renewalConversion($entity['id'], $from, $to, $by),
            'profit-and-loss' => $this->profitAndLoss($entity['id'], $from, $to, $money),
            'balance-sheet' => $this->balanceSheet($entity['id'], $asOf, $money),
            'ri-premium-bordereau', 'ri-claims-bordereau' => \App\Modules\Insurance\Reinsurance\Http\Controllers\ReinsuranceReportTables::table($report, $entity['id'], $from, $to, $money), // reinsurance MVP
            'ap-ageing' => $this->apAgeing($entity['id'], $asOf, $money),
            'account-activity' => $this->accountActivity($entity['id'], $request, $from, $to, $money),
            'suspense-ageing', 'agent-cash', 'commission-statements', 'trial-balance' => OperationalReportTables::table($report, $entity['id'], $from, $to, $asOf, $money), // gap audit GA-34
            default => abort(404),
        };

        return [$page, ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'as_of' => $asOf->toDateString(), 'by' => $by]];
    }

    /**
     * @param callable(int): string $money
     * @return array<string, mixed>
     */
    private function premiumRegister(string $entityId, CarbonImmutable $from, CarbonImmutable $to, callable $money): array
    {
        $result = app(PremiumRegisterQuery::class)->register($entityId, $from, $to);

        // Gap audit GA-34: labels instead of codes (Motor, New business), and stamp duty in its own column (gross = net + VAT + stamp duty).
        $classes = ReportLabels::classes();
        $summary = fn (string $title, string $label, array $groups, bool $classLabels): array => self::summary($title, [['group', $label], ['gross', 'Gross'], ['net', 'Net'], ['tax', 'VAT'], ['stamp_duty', 'Stamp duty']],
            array_map(fn (array $g): array => ['cells' => ['group' => $classLabels ? ReportLabels::productClass($g['group'], $classes) : $g['group'], 'gross' => $money($g['gross_minor']), 'net' => $money($g['net_minor']),
                'tax' => $money($g['tax_minor']), 'stamp_duty' => $money($g['stamp_duty_minor'])], 'link' => null], $groups));

        return self::table('Premium register', 'range', [['accounting_date', 'Date'], ['policy_number', 'Policy'], ['type', 'Transaction'], ['product_code', 'Product'], ['class', 'Class'], ['branch_code', 'Branch'],
            ['gross', 'Gross', 'right'], ['net', 'Net', 'right'], ['tax', 'VAT', 'right'], ['stamp_duty', 'Stamp duty', 'right']],
            array_map(fn (array $r): array => ['cells' => ['accounting_date' => $r['accounting_date'], 'policy_number' => $r['policy_number'], 'type' => ReportLabels::transaction($r['type']), 'product_code' => $r['product_code'],
                'class' => ReportLabels::productClass($r['class'], $classes), 'branch_code' => $r['branch_code'], 'gross' => $money($r['gross_minor']), 'net' => $money($r['net_minor']), 'tax' => $money($r['tax_minor']),
                'stamp_duty' => $money($r['stamp_duty_minor'])],
                'link' => $r['journals'][0]['url'] ?? null, 'links' => ['policy_number' => "/policies/{$r['policy_id']}"]], $result['rows']),
            ['gross' => $money($result['totals']['gross_minor']), 'net' => $money($result['totals']['net_minor']), 'tax' => $money($result['totals']['tax_minor']), 'stamp_duty' => $money($result['totals']['stamp_duty_minor'])],
            [$summary('Totals by class', 'Class', $result['by_class'], true), $summary('Totals by branch', 'Branch', $result['by_branch'], false)]);
    }

    /**
     * @param callable(int): string $money
     * @return array<string, mixed>
     */
    private function unearnedPremium(string $entityId, CarbonImmutable $asOf, callable $money): array
    {
        $result = app(UnearnedPremiumQuery::class)->unearned($entityId, $asOf);
        $classes = ReportLabels::classes(); // gap audit GA-34
        $summary = fn (string $title, string $label, array $groups): array => self::summary($title, [['group', $label], ['policies', 'Policies'], ['net', 'Net premium'], ['earned', 'Earned to date'], ['unearned', 'Unearned']],
            array_map(fn (array $g): array => ['cells' => ['group' => $g['group'], 'policies' => $g['policies'], 'net' => $money($g['net_premium_minor']),
                'earned' => $money($g['earned_minor']), 'unearned' => $money($g['unearned_minor'])], 'link' => null], $groups));
        $recon = $result['reconciliation'];
        $glLink = count($recon['account_ids']) === 1 ? '/reports/account-activity?'.http_build_query(['account_id' => $recon['account_ids'][0], 'to' => $result['as_of']]) : null;

        return self::table('Unearned premium', 'as_of', [['policy_number', 'Policy'], ['product_code', 'Product'], ['class', 'Class'], ['branch_code', 'Branch'],
            ['net', 'Net premium', 'right'], ['earned', 'Earned to date', 'right'], ['unearned', 'Unearned', 'right']],
            array_map(fn (array $r): array => ['cells' => ['policy_number' => $r['policy_number'], 'product_code' => $r['product_code'], 'class' => ReportLabels::productClass((string) $r['class'], $classes), 'branch_code' => $r['branch_code'],
                'net' => $money($r['net_premium_minor']), 'earned' => $money($r['earned_minor']), 'unearned' => $money($r['unearned_minor'])], 'link' => "/policies/{$r['policy_id']}"], $result['rows']),
            ['net' => $money($result['totals']['net_premium_minor']), 'earned' => $money($result['totals']['earned_minor']), 'unearned' => $money($result['totals']['unearned_minor'])],
            [$summary('Totals by class', 'Class', array_map(fn (array $g): array => ['group' => ReportLabels::productClass((string) $g['group'], $classes)] + $g, $result['by_class'])), $summary('Totals by product', 'Product', $result['by_product']),
                self::summary('Reconciliation to the unearned premium control', [['item', 'Item'], ['amount', 'Amount']], [
                    ['cells' => ['item' => 'Unearned premium in this register', 'amount' => $money($recon['register_minor'])], 'link' => null],
                    ['cells' => ['item' => 'Unearned premium reserve in the ledger', 'amount' => $money($recon['gl_minor'])], 'link' => $glLink],
                    ['cells' => ['item' => 'Variance', 'amount' => $money($recon['variance_minor'])], 'link' => null],
                ])]);
    }

    /**
     * @param callable(int): string $money
     * @return array<string, mixed>
     */
    private function apAgeing(string $entityId, CarbonImmutable $asOf, callable $money): array
    {
        $result = app(\App\Modules\Finance\Payables\Application\PayablesQuery::class)->ageing($entityId, $asOf);
        $buckets = \App\Modules\Finance\Payables\Application\PayablesQuery::BUCKETS;
        $ledger = -1 * (int) \Illuminate\Support\Facades\DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('account_role_mappings as m', 'm.account_id', '=', 'l.account_id')->where('m.role_code', 'accounts_payable')->whereNull('m.effective_to')
            ->whereIn('j.status', ['posted', 'reversed'])->where('j.posting_date', '<=', $asOf->toDateString())->whereRaw("l.dims_ext->>'payee_party' is not null")
            ->sum(\Illuminate\Support\Facades\DB::raw("case when l.side = 'debit' then l.amount_minor else -l.amount_minor end"));
        $bucketColumns = array_map(fn (string $key, string $label): array => [$key, $label], array_keys($buckets), $buckets);

        return self::table('AP ageing', 'as_of', [['supplier', 'Supplier'], ['number', 'Bill'], ['reference', 'Invoice'], ['due_date', 'Due'], ['days_past_due', 'Days past due', 'right'],
            ['bucket', 'Bucket'], ['outstanding', 'Outstanding', 'right']],
            array_map(fn (array $r): array => ['cells' => ['supplier' => $r['supplier'], 'number' => $r['number'], 'reference' => $r['supplier_reference'], 'due_date' => $r['due_date'],
                'days_past_due' => $r['days_past_due'], 'bucket' => $buckets[$r['bucket']], 'outstanding' => $money($r['outstanding_minor'])], 'link' => "/payables/bills/{$r['bill_id']}",
                'links' => ['supplier' => "/payables/suppliers/{$r['supplier_id']}"]], $result['rows']),
            ['outstanding' => $money($result['total_minor'])],
            [self::summary('By supplier', [['group', 'Supplier'], ...$bucketColumns, ['total', 'Total']],
                array_map(fn (array $g): array => ['cells' => ['group' => $g['supplier']] + array_map($money, $g['buckets']) + ['total' => $money($g['total_minor'])], 'link' => "/payables/suppliers/{$g['supplier_id']}"], $result['by_supplier'])),
                self::summary('Reconciliation to the ledger', [['item', ''], ['amount', 'Amount']], [
                    ['cells' => ['item' => 'Owed to suppliers in this ageing', 'amount' => $money($result['total_minor'])], 'link' => null],
                    ['cells' => ['item' => 'Accounts payable to suppliers in the ledger', 'amount' => $money($ledger)], 'link' => null],
                    ['cells' => ['item' => 'Variance', 'amount' => $money($result['total_minor'] - $ledger)], 'link' => null],
                ])]);
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
            array_map(fn (array $r): array => ['cells' => ['claim_number' => $r['claim_number'], 'policy_number' => $r['policy_number'], 'loss_date' => $r['loss_date'], 'status' => ReportLabels::words((string) $r['status']),
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
        $labels = \Illuminate\Support\Facades\DB::table(match ($by) { 'agent' => 'producers', 'branch' => 'branches', default => 'products' })->pluck('code', 'id');
        $ratio = fn (?int $bp): string => self::percentText($bp);

        // Gap audit GA-06: gross incurred, recoveries (dated when received) and net incurred side by side; the ratio is on net incurred.
        return self::table("Loss ratio by {$by}", 'range_by', [['group', ucfirst($by)], ['earned', 'Earned premium', 'right'], ['claims', 'Claims incurred', 'right'], ['recoveries', 'Recoveries', 'right'],
            ['incurred', 'Net incurred', 'right'], ['ratio', 'Loss ratio', 'right']],
            array_map(fn (array $r): array => ['cells' => ['group' => $r['dimension_value'] === null ? 'None' : (string) ($labels[$r['dimension_value']] ?? $r['dimension_value']),
                'earned' => $money($r['earned_premium_minor']), 'claims' => $money($r['claims_expense_minor']), 'recoveries' => $money($r['recoveries_minor']),
                'incurred' => $money($r['incurred_claims_minor']), 'ratio' => $ratio($r['loss_ratio_bp'])],
                'link' => isset($r['drill']['claims_expense']) ? self::activityLink($r['drill']['claims_expense']) : null,
                'links' => isset($r['drill']['claims_recovery_income']) ? ['recoveries' => self::activityLink($r['drill']['claims_recovery_income'])] : []], $result['rows']),
            ['earned' => $money($result['totals']['earned_premium_minor']), 'claims' => $money($result['totals']['claims_expense_minor']), 'recoveries' => $money($result['totals']['recoveries_minor']),
                'incurred' => $money($result['totals']['incurred_claims_minor']), 'ratio' => $ratio($result['totals']['loss_ratio_bp'])]);
    }

    /**
     * Slice R9: the expiry register as at a date.
     *
     * @param callable(int): string $money
     * @return array<string, mixed>
     */
    private function expiryRegister(string $entityId, CarbonImmutable $asOf, callable $money): array
    {
        $result = app(ExpiryRegisterReportQuery::class)->register($entityId, $asOf);
        $summary = fn (string $title, string $label, array $groups): array => self::summary($title, [['group', $label], ['policies', 'Policies'], ['gross', 'Gross premium']],
            array_map(fn (array $g): array => ['cells' => ['group' => $g['group'], 'policies' => $g['policies'], 'gross' => $money($g['gross_minor'])], 'link' => null], $groups));

        return self::table('Expiry register', 'as_of', [['policy_number', 'Policy'], ['customer', 'Customer'], ['product_code', 'Product'], ['branch_code', 'Branch'], ['producer_code', 'Producer'],
            ['expiry', 'Expires on'], ['days_left', 'Days left', 'right'], ['bucket', 'Bucket', 'right'], ['status', 'Renewal'], ['renewal_quotation', 'Renewal quotation'], ['gross', 'Gross premium', 'right']],
            array_map(fn (array $r): array => ['cells' => ['policy_number' => $r['policy_number'], 'customer' => $r['customer'], 'product_code' => $r['product_code'], 'branch_code' => $r['branch_code'],
                'producer_code' => $r['producer_code'] ?? 'Direct', 'expiry' => $r['expiry'], 'days_left' => $r['days_left'], 'bucket' => $r['bucket'], 'status' => ReportLabels::words((string) $r['status']),
                'renewal_quotation' => $r['renewal_quotation'], 'gross' => $money($r['gross_minor'])], 'link' => "/policies/{$r['policy_id']}"], $result['rows']),
            ['policies' => $result['totals']['policies'].' policies', 'gross' => $money($result['totals']['gross_minor'])],
            [$summary('By bucket', 'Bucket', $result['by_bucket']), $summary('By branch', 'Branch', $result['by_branch']), $summary('By producer', 'Producer', $result['by_producer'])]);
    }

    /**
     * Slice R9: renewal conversion for policies expiring in a period, grouped by branch, agent (producer) or product, with the reasons policies were not renewed.
     *
     * @return array<string, mixed>
     */
    private function renewalConversion(string $entityId, CarbonImmutable $from, CarbonImmutable $to, string $by): array
    {
        $result = app(RenewalConversionQuery::class)->conversion($entityId, $from, $to, $by, app(BusinessClock::class)->today());
        $percent = fn (?int $bp): string => self::percentText($bp);
        $label = ['branch' => 'Branch', 'agent' => 'Producer', 'product' => 'Product'][$by] ?? 'Branch';

        return self::table("Renewal conversion by {$by}", 'range_by', [['policy_number', 'Expiring policy'], ['group', $label], ['expiry', 'Expires on'], ['outcome', 'Outcome'], ['reason', 'Reason'], ['renewal_policy_number', 'Renewal policy']],
            array_map(fn (array $r): array => ['cells' => ['policy_number' => $r['policy_number'], 'group' => $r['group'], 'expiry' => $r['expiry'], 'outcome' => ReportLabels::words((string) $r['outcome']),
                'reason' => \App\Modules\Insurance\Renewal\Domain\RenewalReasons::label($r['reason']), 'renewal_policy_number' => $r['renewal_policy_number']], 'link' => "/policies/{$r['policy_id']}",
                'links' => $r['renewal_policy_id'] === null ? [] : ['renewal_policy_number' => "/policies/{$r['renewal_policy_id']}"]], $result['rows']),
            ['expiring' => $result['totals']['expiring'].' policies', 'renewed' => $result['totals']['renewed'].' policies', 'not_renewed' => $result['totals']['not_renewed'].' policies',
                'conversion' => $percent($result['totals']['conversion_bp'])],
            [self::summary("Conversion by {$by}", [['group', $label], ['expiring', 'Expiring'], ['renewed', 'Renewed'], ['not_renewed', 'Not renewed'], ['open', 'Open'], ['conversion', 'Conversion']],
                array_map(fn (array $g): array => ['cells' => ['group' => $g['group'], 'expiring' => $g['expiring'], 'renewed' => $g['renewed'], 'not_renewed' => $g['not_renewed'], 'open' => $g['open'],
                    'conversion' => $percent($g['conversion_bp'])], 'link' => null], $result['groups'])),
                self::summary('Not renewed by reason', [['reason', 'Reason'], ['policies', 'Policies']],
                    array_map(fn (array $r): array => ['cells' => ['reason' => (string) \App\Modules\Insurance\Renewal\Domain\RenewalReasons::label($r['reason']), 'policies' => $r['policies']], 'link' => null], $result['reasons']))]);
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
            ['income' => $money($result['total_income_minor']), 'expense' => $money($result['total_expense_minor']), 'amount' => $money($result['net_profit_minor'])])
            + ['related' => self::statements('profit-and-loss', $from, $to)];
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
            ['assets' => $money($result['total_assets_minor']), 'liabilities_and_equity' => $money($result['total_liabilities_minor'] + $result['total_equity_minor'] + $result['current_earnings_minor'])])
            + ['related' => self::statements('balance-sheet', $asOf->startOfMonth(), $asOf)];
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
        // Flow fix X11: the policy, claim or receipt behind each journal in its own column, one click from the figure (the journal stays on the row).
        $sources = JournalSources::forJournals(array_column($result['lines'], 'journal_id'));

        return self::table("Account activity: {$result['account']['code']} {$result['account']['name']}", 'range', [['posting_date', 'Date'], ['journal_number', 'Journal'], ['source', 'Source'], ['description', 'Description'], ['debit', 'Debit', 'right'], ['credit', 'Credit', 'right']],
            array_map(fn (array $l): array => ['cells' => ['posting_date' => $l['posting_date'], 'journal_number' => $l['journal_number'], 'source' => $sources[$l['journal_id']]['label'] ?? null,
                'description' => $l['description'] ?? $l['memo'], 'debit' => $money($l['debit_minor']), 'credit' => $money($l['credit_minor'])], 'link' => $l['url'],
                'links' => isset($sources[$l['journal_id']]) ? ['source' => $sources[$l['journal_id']]['url']] : []], $result['lines']),
            ['opening' => $money($result['opening_minor']), 'closing' => $money($result['closing_minor'])]);
    }

    /**
     * @param list<array{0: string, 1: string, 2?: string}> $columns
     * @param list<array{cells: array<string, mixed>, link: string|null, links?: array<string, string>}> $rows
     * @param array<string, string> $totals
     * @param list<array{title: string, columns: list<array{key: string, label: string}>, rows: list<array{cells: array<string, mixed>, link: string|null}>}> $summaries
     * @return array<string, mixed>
     */
    private static function table(string $title, string $filter, array $columns, array $rows, array $totals, array $summaries = []): array
    {
        return ['title' => $title, 'filter' => $filter, 'columns' => array_map(fn (array $c): array => ['key' => $c[0], 'label' => $c[1], 'align' => $c[2] ?? 'left'], $columns),
            'rows' => $rows, 'totals' => $totals, 'summaries' => $summaries];
    }

    /**
     * A subtotal or reconciliation table shown under the report rows: the first column labels the row, the others are figures.
     *
     * @param list<array{0: string, 1: string}> $columns
     * @param array<int, array{cells: array<string, mixed>, link: string|null}> $rows
     * @return array{title: string, columns: list<array{key: string, label: string}>, rows: list<array{cells: array<string, mixed>, link: string|null}>}
     */
    private static function summary(string $title, array $columns, array $rows): array
    {
        return ['title' => $title, 'columns' => array_map(fn (array $c): array => ['key' => $c[0], 'label' => $c[1]], $columns), 'rows' => array_values($rows)];
    }

    /**
     * Flow fix X11: the other financial statements for the same period — profit and loss for the range, the balance sheet and trial balance at its end — so
     * reviewing all three does not go back through the reports index.
     *
     * @return list<array{label: string, href: string}>
     */
    private static function statements(string $current, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $links = [
            'profit-and-loss' => ['label' => 'Profit and loss', 'href' => '/reports/profit-and-loss?'.http_build_query(['from' => $from->toDateString(), 'to' => $to->toDateString()])],
            'balance-sheet' => ['label' => 'Balance sheet', 'href' => '/reports/balance-sheet?'.http_build_query(['as_of' => $to->toDateString()])],
            'trial-balance' => ['label' => 'Trial balance', 'href' => '/accounting/trial-balance?'.http_build_query(['as_of' => $to->toDateString()])],
        ];
        unset($links[$current]);

        return array_values($links);
    }

    /**
     * Gap audit GA-06: signed basis points as people read a percentage — 123,077 → "1,230.77%", −123,077 → "(1,230.77)%" (negatives in parentheses,
     * UX brief §1.7), null → "—". The sign and the absolute value are formatted apart, so a negative never prints as "-1230.-77%".
     */
    public static function percentText(?int $basisPoints): string
    {
        if ($basisPoints === null) {
            return '—';
        }
        $abs = abs($basisPoints);
        $text = MinorUnits::format($abs, 'BDT');

        return $basisPoints < 0 ? "({$text})%" : "{$text}%";
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

    /** Returns whether the reader holds reports.financial; a claims report also opens with reports.claims (GA-12). */
    private function authorize(Request $request, string $report): bool
    {
        $actor = PageSupport::actor($request);
        if (in_array($report, self::CLAIMS_REPORTS, true) && ! $this->permissions->has($actor, 'reports.financial')) {
            $this->permissions->authorize($actor, 'reports.claims');

            return false;
        }
        $this->permissions->authorize($actor, 'reports.financial');

        return true;
    }

    /**
     * GA-12: a claims-report reader follows links to claims only (journals, account activity and policies need other permissions).
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private static function claimsLinksOnly(array $page): array
    {
        $keep = fn (mixed $link): ?string => is_string($link) && str_starts_with($link, '/claims/') ? $link : null;
        $strip = function (mixed $rows) use ($keep): array {
            $result = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_array($row)) {
                    $row['link'] = $keep($row['link'] ?? null);
                    if (isset($row['links']) && is_array($row['links'])) {
                        $row['links'] = array_filter(array_map($keep, $row['links']));
                    }
                }
                $result[] = $row;
            }

            return $result;
        };
        $page['rows'] = $strip($page['rows'] ?? []);
        $page['summaries'] = array_map(fn (mixed $summary): mixed => is_array($summary) ? ['rows' => $strip($summary['rows'] ?? [])] + $summary : $summary, is_array($page['summaries'] ?? null) ? $page['summaries'] : []);
        unset($page['related']);

        return $page;
    }

    private static function date(Request $request, string $key, CarbonImmutable $default): CarbonImmutable
    {
        $value = $request->query($key);

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::parse($value) : $default;
    }
}
