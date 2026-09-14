<?php

declare(strict_types=1);

namespace App\Modules\Finance\Budget\Application;

use App\Modules\Accounting\Application\Reports\AccountMovementQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design addendum v2 §B.8.1 variance report: actual (posted GL, PD-18) against the approved budget per account and branch, for a fiscal month and the year
 * to date. Favourable = spent less than budget on an expense, earned more on income (shown green); unfavourable in red.
 */
final class BudgetVarianceQuery
{
    public function __construct(private readonly AccountMovementQuery $movements) {}

    /** The approved budget of the fiscal year (the first code alphabetically when there are several), or null. */
    public function approvedBudget(string $entityId, int $fiscalYear): ?\stdClass
    {
        return DB::table('budgets')->where('entity_id', $entityId)->where('fiscal_year', $fiscalYear)->where('status', 'approved')->orderBy('code')->first();
    }

    /**
     * @return array{budget: array{id: string, name: string, version: int}|null, period: array{no: int, starts: string, ends: string},
     *     rows: list<array{account_id: string, code: string, name: string, type: string, group: string, branch_id: string, branch_code: string,
     *         budget_minor: int, actual_minor: int, variance_minor: int, variance_bp: int|null, favourable: bool,
     *         ytd_budget_minor: int, ytd_actual_minor: int, ytd_variance_minor: int, ytd_variance_bp: int|null, ytd_favourable: bool}>}
     */
    public function variance(string $entityId, int $fiscalYear, int $periodNo, ?string $branchId = null): array
    {
        $periods = DB::table('fiscal_periods as p')->join('books as b', 'b.id', '=', 'p.book_id')->where('b.is_primary', true)->where('p.entity_id', $entityId)
            ->where('p.year', $fiscalYear)->orderBy('p.period')->get(['p.period', 'p.starts', 'p.ends'])->keyBy('period');
        $period = $periods[$periodNo] ?? $periods->first();
        $budget = $this->approvedBudget($entityId, $fiscalYear);
        if ($period === null) {
            return ['budget' => null, 'period' => ['no' => $periodNo, 'starts' => '', 'ends' => ''], 'rows' => []];
        }
        $periodNo = (int) $period->period;
        $monthKey = substr((string) $period->starts, 0, 7);
        $yearStart = CarbonImmutable::parse((string) ($periods->first()->starts ?? $period->starts));

        // Budget per account × branch: the month and the year to date.
        $cells = [];
        if ($budget !== null) {
            $lines = DB::table('budget_lines')->where('budget_id', $budget->id)->where('period_no', '<=', $periodNo)
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))->get(['account_id', 'branch_id', 'period_no', 'amount_minor']);
            foreach ($lines as $line) {
                $key = "{$line->account_id}|{$line->branch_id}";
                $cells[$key] ??= ['budget' => 0, 'actual' => 0, 'ytd_budget' => 0, 'ytd_actual' => 0];
                $cells[$key]['ytd_budget'] += (int) $line->amount_minor;
                if ((int) $line->period_no === $periodNo) {
                    $cells[$key]['budget'] += (int) $line->amount_minor;
                }
            }
        }
        // Actuals of the accounts the budget covers (ASSUMPTION A-279: accounts outside the operating budget, such as claims incurred or commission, are left out).
        $budgeted = $budget === null ? null : array_flip(array_map(fn (mixed $id): string => (string) $id, DB::table('budget_lines')->where('budget_id', $budget->id)->distinct()->pluck('account_id')->all()));
        foreach ($this->movements->byAccountBranchMonth($entityId, $yearStart, CarbonImmutable::parse((string) $period->ends)) as $m) {
            if ($m['branch_id'] === null || ($branchId !== null && $m['branch_id'] !== $branchId) || ($budgeted !== null && ! isset($budgeted[$m['account_id']]))) {
                continue;
            }
            $key = "{$m['account_id']}|{$m['branch_id']}";
            $cells[$key] ??= ['budget' => 0, 'actual' => 0, 'ytd_budget' => 0, 'ytd_actual' => 0];
            $cells[$key]['ytd_actual'] += $m['amount_minor'];
            if ($m['month'] === $monthKey) {
                $cells[$key]['actual'] += $m['amount_minor'];
            }
        }

        $accountIds = array_values(array_unique(array_map(fn (string $key): string => explode('|', $key)[0], array_keys($cells))));
        $accounts = DB::table('accounts as a')->leftJoin('accounts as p', 'p.id', '=', 'a.parent_id')->whereIn('a.id', $accountIds)
            ->get(['a.id', 'a.code', 'a.name', 'a.type', 'p.name as parent_name'])->keyBy('id');
        $branches = DB::table('branches')->where('entity_id', $entityId)->pluck('code', 'id');
        $rows = [];
        foreach ($cells as $key => $c) {
            [$accountId, $branch] = explode('|', $key);
            $account = $accounts[$accountId] ?? null;
            if ($account === null) {
                continue;
            }
            $income = $account->type === 'income';
            $variance = $income ? $c['actual'] - $c['budget'] : $c['budget'] - $c['actual'];
            $ytdVariance = $income ? $c['ytd_actual'] - $c['ytd_budget'] : $c['ytd_budget'] - $c['ytd_actual'];
            $rows[] = ['account_id' => $accountId, 'code' => (string) $account->code, 'name' => (string) $account->name, 'type' => (string) $account->type,
                'group' => $account->parent_name !== null ? (string) $account->parent_name : ($income ? 'Income' : 'Expenses'),
                'branch_id' => $branch, 'branch_code' => (string) ($branches[$branch] ?? ''),
                'budget_minor' => $c['budget'], 'actual_minor' => $c['actual'], 'variance_minor' => $variance, 'variance_bp' => self::ratio($variance, $c['budget']), 'favourable' => $variance >= 0,
                'ytd_budget_minor' => $c['ytd_budget'], 'ytd_actual_minor' => $c['ytd_actual'], 'ytd_variance_minor' => $ytdVariance, 'ytd_variance_bp' => self::ratio($ytdVariance, $c['ytd_budget']),
                'ytd_favourable' => $ytdVariance >= 0];
        }
        usort($rows, fn (array $a, array $b): int => [$a['type'] === 'income' ? 0 : 1, $a['code'], $a['branch_code']] <=> [$b['type'] === 'income' ? 0 : 1, $b['code'], $b['branch_code']]);

        return ['budget' => $budget === null ? null : ['id' => (string) $budget->id, 'name' => (string) $budget->name, 'version' => (int) $budget->version],
            'period' => ['no' => $periodNo, 'starts' => (string) $period->starts, 'ends' => (string) $period->ends], 'rows' => $rows];
    }

    /** Variance as basis points of the budget (half up), null without a budget. */
    public static function ratio(int $variance, int $budget): ?int
    {
        if ($budget === 0) {
            return null;
        }
        $bp = intdiv(abs($variance) * 10_000 * 2 + $budget, 2 * $budget);

        return $variance < 0 ? -$bp : $bp;
    }
}
