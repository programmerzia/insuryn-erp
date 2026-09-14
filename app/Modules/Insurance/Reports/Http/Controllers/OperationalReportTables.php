<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Http\Controllers;

use App\Modules\Accounting\Application\LedgerQuery;
use App\Modules\Insurance\Collections\Application\AgentCashPositionQuery;
use App\Modules\Insurance\Reports\Application\SuspenseAgeingReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gap audit GA-34: the reports index lists four registers that live on their own screens — suspense ageing, agent cash, commission statements and
 * the trial balance — with no export. These are their tables in the shape of ReportsPageController's report pages, so the X12 export path
 * (GET /reports/{key}/export?format=csv|xlsx, same filters and defaults) downloads them like every other report.
 */
final class OperationalReportTables
{
    /** key → [title, description, filter, screen] */
    public const REPORTS = [
        'suspense-ageing' => ['Suspense ageing', 'Unidentified receipts by age.', 'as_of', '/suspense'],
        'agent-cash' => ['Agent cash', 'Collections, deposits and the ledger per agent.', 'as_of', '/agent-cash'],
        'commission-statements' => ['Commission statements', 'Per agent, with payouts.', 'range', '/commission'],
        'trial-balance' => ['Trial balance', 'Debits and credits per account.', 'as_of', '/accounting/trial-balance'],
    ];

    /**
     * The table of one of REPORTS.
     *
     * @param callable(int): string $money
     * @return array{title: string, filter: string, columns: list<array{key: string, label: string, align: string}>, rows: list<array{cells: array<string, mixed>, link: string|null}>, totals: array<string, string>, summaries: list<mixed>}
     */
    public static function table(string $key, string $entityId, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $asOf, callable $money): array
    {
        return match ($key) {
            'suspense-ageing' => self::suspense($entityId, $asOf, $money),
            'agent-cash' => self::agentCash($entityId, $asOf, $money),
            'commission-statements' => self::commissionStatements($entityId, $from, $to, $money),
            'trial-balance' => self::trialBalance($entityId, $asOf, $money),
            default => abort(404),
        };
    }

    /**
     * @param callable(int): string $money
     * @return array{title: string, filter: string, columns: list<array{key: string, label: string, align: string}>, rows: list<array{cells: array<string, mixed>, link: string|null}>, totals: array<string, string>, summaries: list<mixed>}
     */
    private static function suspense(string $entityId, CarbonImmutable $asOf, callable $money): array
    {
        $result = app(SuspenseAgeingReport::class)->ageing($entityId, $asOf);

        return self::shape('Suspense ageing', 'as_of', [['receipt_number', 'Receipt'], ['reference', 'Reference'], ['aged_since', 'Held since'], ['days', 'Days', 'right'], ['open', 'Open amount', 'right']],
            array_map(fn (array $i): array => ['cells' => ['receipt_number' => $i['receipt_number'], 'reference' => $i['reference'], 'aged_since' => $i['aged_since'], 'days' => $i['days'],
                'open' => $money($i['open_minor'])], 'link' => "/receipts/{$i['receipt_id']}"], $result['items']),
            ['open' => $money($result['total_minor'])]);
    }

    /**
     * @param callable(int): string $money
     * @return array{title: string, filter: string, columns: list<array{key: string, label: string, align: string}>, rows: list<array{cells: array<string, mixed>, link: string|null}>, totals: array<string, string>, summaries: list<mixed>}
     */
    private static function agentCash(string $entityId, CarbonImmutable $asOf, callable $money): array
    {
        $result = app(AgentCashPositionQuery::class)->position($entityId, $asOf);

        return self::shape('Agent cash', 'as_of', [['agent_code', 'Agent'], ['collected', 'Collected', 'right'], ['deposited', 'Deposited', 'right'], ['undeposited', 'Not deposited', 'right'],
            ['gl', 'Ledger', 'right'], ['difference', 'Difference', 'right'], ['oldest_undeposited_on', 'Oldest not deposited'], ['days_undeposited', 'Days held', 'right']],
            array_map(fn (array $r): array => ['cells' => ['agent_code' => $r['agent_code'], 'collected' => $money($r['collected_minor']), 'deposited' => $money($r['deposited_minor']),
                'undeposited' => $money($r['undeposited_minor']), 'gl' => $money($r['gl_minor']), 'difference' => $money($r['difference_minor']),
                'oldest_undeposited_on' => $r['oldest_undeposited_on'], 'days_undeposited' => $r['days_undeposited']], 'link' => "/distribution/producers/{$r['agent_id']}"], $result['rows']),
            ['collected' => $money($result['totals']['collected_minor']), 'deposited' => $money($result['totals']['deposited_minor']),
                'undeposited' => $money($result['totals']['undeposited_minor']), 'gl' => $money($result['totals']['gl_minor'])]);
    }

    /**
     * Statements whose period ends in the range.
     *
     * @param callable(int): string $money
     * @return array{title: string, filter: string, columns: list<array{key: string, label: string, align: string}>, rows: list<array{cells: array<string, mixed>, link: string|null}>, totals: array<string, string>, summaries: list<mixed>}
     */
    private static function commissionStatements(string $entityId, CarbonImmutable $from, CarbonImmutable $to, callable $money): array
    {
        $statements = DB::table('commission_statements as s')->join('producers as p', 'p.id', '=', 's.agent_id')->leftJoin('parties as pa', 'pa.id', '=', 'p.party_id')
            ->where('s.entity_id', $entityId)->whereBetween('s.up_to', [$from->toDateString(), $to->toDateString()])->orderBy('s.up_to')->orderBy('p.code')
            ->get(['s.agent_id', 's.number', 'p.code', 'pa.display_name', 's.up_to', 's.gross_minor', 's.withholding_minor', 's.net_minor', 's.status', 's.paid_on']);
        $status = fn (string $s): string => ucfirst(str_replace('_', ' ', $s));

        return self::shape('Commission statements', 'range', [['number', 'Statement'], ['agent_code', 'Producer'], ['name', 'Name'], ['up_to', 'Up to'], ['gross', 'Commission', 'right'],
            ['withholding', 'Tax withheld', 'right'], ['net', 'Net', 'right'], ['status', 'Status'], ['paid_on', 'Paid on']],
            array_values($statements->map(fn (object $s): array => ['cells' => ['number' => (string) $s->number, 'agent_code' => (string) $s->code, 'name' => (string) ($s->display_name ?? ''),
                'up_to' => (string) $s->up_to, 'gross' => $money((int) $s->gross_minor), 'withholding' => $money((int) $s->withholding_minor), 'net' => $money((int) $s->net_minor),
                'status' => $status((string) $s->status), 'paid_on' => $s->paid_on === null ? null : (string) $s->paid_on], 'link' => "/commission/agents/{$s->agent_id}"])->all()),
            ['gross' => $money((int) $statements->sum('gross_minor')), 'withholding' => $money((int) $statements->sum('withholding_minor')), 'net' => $money((int) $statements->sum('net_minor'))]);
    }

    /**
     * @param callable(int): string $money
     * @return array{title: string, filter: string, columns: list<array{key: string, label: string, align: string}>, rows: list<array{cells: array<string, mixed>, link: string|null}>, totals: array<string, string>, summaries: list<mixed>}
     */
    private static function trialBalance(string $entityId, CarbonImmutable $asOf, callable $money): array
    {
        $bookId = DB::table('books')->where('is_primary', true)->value('id');
        $rows = is_string($bookId) ? app(LedgerQuery::class)->trialBalance($entityId, $bookId, $asOf) : [];

        return self::shape('Trial balance', 'as_of', [['code', 'Account'], ['name', 'Name'], ['type', 'Type'], ['debit', 'Debit', 'right'], ['credit', 'Credit', 'right'], ['balance', 'Balance', 'right']],
            array_map(fn (array $r): array => ['cells' => ['code' => $r['code'], 'name' => $r['name'], 'type' => ucfirst($r['type']), 'debit' => $money($r['debit']), 'credit' => $money($r['credit']),
                'balance' => $money($r['debit'] - $r['credit'])], 'link' => '/reports/account-activity?'.http_build_query(['account_id' => $r['account_id'], 'to' => $asOf->toDateString()])], $rows),
            ['debit' => $money(array_sum(array_column($rows, 'debit'))), 'credit' => $money(array_sum(array_column($rows, 'credit')))]);
    }

    /**
     * @param list<array{0: string, 1: string, 2?: string}> $columns
     * @param list<array{cells: array<string, mixed>, link: string|null}> $rows
     * @param array<string, string> $totals
     * @return array{title: string, filter: string, columns: list<array{key: string, label: string, align: string}>, rows: list<array{cells: array<string, mixed>, link: string|null}>, totals: array<string, string>, summaries: list<mixed>}
     */
    private static function shape(string $title, string $filter, array $columns, array $rows, array $totals): array
    {
        return ['title' => $title, 'filter' => $filter, 'columns' => array_map(fn (array $c): array => ['key' => $c[0], 'label' => $c[1], 'align' => $c[2] ?? 'left'], $columns),
            'rows' => $rows, 'totals' => $totals, 'summaries' => []];
    }
}
