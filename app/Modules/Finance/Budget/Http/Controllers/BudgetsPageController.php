<?php

declare(strict_types=1);

namespace App\Modules\Finance\Budget\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Finance\Budget\Application\BudgetService;
use App\Modules\Finance\Budget\Application\BudgetVarianceQuery;
use App\Modules\Finance\FixedAssets\Http\Controllers\FixedAssetsPageController;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use App\Modules\Platform\Tenancy\FiscalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Budget screens (design addendum v2 §B.8.1): budget versions, the account × month grid per branch with paste and copy-from-actuals, approval, and the variance report. */
final class BudgetsPageController
{
    public const AREA = ['budget.prepare', 'budget.approve', 'reports.financial'];

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function index(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeArea($actor, self::AREA);
        $entity = PageSupport::entity();

        return Inertia::render('budgets/Index', [
            'budgets' => DB::table('budgets as b')->leftJoin('users as p', 'p.id', '=', 'b.prepared_by')->leftJoin('users as a', 'a.id', '=', 'b.approved_by')->where('b.entity_id', $entity['id'])
                ->orderByDesc('b.fiscal_year')->orderBy('b.code')->orderByDesc('b.version')
                ->get(['b.id', 'b.fiscal_year', 'b.code', 'b.name', 'b.version', 'b.status', 'p.name as prepared_by', 'a.name as approved_by', 'b.approved_at', 'b.updated_at'])
                ->map(fn (object $b): array => ['id' => (string) $b->id, 'year' => self::yearLabel((int) $b->fiscal_year), 'code' => (string) $b->code, 'name' => (string) $b->name, 'version' => (int) $b->version,
                    'status' => (string) $b->status, 'prepared_by' => (string) ($b->prepared_by ?? ''), 'approved_by' => $b->approved_by, 'total' => PageSupport::money((int) DB::table('budget_lines')->where('budget_id', $b->id)->sum('amount_minor'), $entity['currency'])])
                ->values()->all(),
            'years' => DB::table('fiscal_periods')->where('entity_id', $entity['id'])->distinct()->orderBy('year')->pluck('year')->map(fn (mixed $y): array => ['value' => (string) $y, 'label' => self::yearLabel((int) $y)])->values()->all(),
            'defaultYear' => (string) app(FiscalCalendar::class)->fiscalYear(app(BusinessClock::class)->today()),
            'can' => ['prepare' => $this->permissions->has($actor, 'budget.prepare', AuthorizationScope::entity($entity['id']))],
        ]);
    }

    public function store(Request $request, BudgetService $budgets): RedirectResponse
    {
        /** @var array{fiscal_year: string, code: string, name: string} $data */
        $data = $request->validate(['fiscal_year' => ['required', 'integer'], 'code' => ['required', 'string', 'max:32'], 'name' => ['required', 'string', 'max:255']]);
        $id = $budgets->create(PageSupport::entity()['id'], (int) $data['fiscal_year'], strtoupper($data['code']), $data['name'], PageSupport::actor($request));

        return redirect("/budgets/{$id}")->with('status', 'Budget created. Enter it per branch, paste it from a spreadsheet or copy last year\'s actuals.');
    }

    public function show(Request $request, string $budget, BudgetService $budgets): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeArea($actor, self::AREA);
        $row = DB::table('budgets')->where('id', $budget)->first() ?? abort(404);
        $entityId = (string) $row->entity_id;
        $currency = (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency');
        $branches = DB::table('branches')->where('entity_id', $entityId)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']);
        $branchId = is_string($request->query('branch')) ? $request->query('branch') : (string) ($branches->first()->id ?? '');
        $accounts = DB::table('accounts')->where('entity_id', $entityId)->whereIn('type', ['income', 'expense'])->where('is_postable', true)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'type']);
        $lines = DB::table('budget_lines')->where('budget_id', $budget)->where('branch_id', $branchId)->get(['account_id', 'period_no', 'amount_minor']);
        $grid = [];
        foreach ($lines as $line) {
            $grid[(string) $line->account_id] ??= array_fill(0, 12, '');
            $grid[(string) $line->account_id][(int) $line->period_no - 1] = PageSupport::money((int) $line->amount_minor, $currency);
        }
        $months = DB::table('fiscal_periods')->where('entity_id', $entityId)->where('year', $row->fiscal_year)->orderBy('period')->pluck('starts')
            ->map(fn (mixed $s): string => CarbonImmutable::parse((string) $s)->format('M y'))->values()->all();
        $canPrepare = $this->permissions->has($actor, 'budget.prepare', AuthorizationScope::entity($entityId));
        $canApprove = $this->permissions->has($actor, 'budget.approve', AuthorizationScope::entity($entityId));

        return Inertia::render('budgets/Show', [
            'budget' => ['id' => (string) $row->id, 'year' => self::yearLabel((int) $row->fiscal_year), 'code' => (string) $row->code, 'name' => (string) $row->name, 'version' => (int) $row->version,
                'status' => (string) $row->status, 'note' => $row->note, 'prepared_by' => (string) DB::table('users')->where('id', $row->prepared_by)->value('name'),
                'total' => PageSupport::money((int) DB::table('budget_lines')->where('budget_id', $budget)->sum('amount_minor'), $currency),
                'branch_totals' => DB::table('budget_lines as l')->join('branches as b', 'b.id', '=', 'l.branch_id')->where('l.budget_id', $budget)->groupBy('b.code')->orderBy('b.code')
                    ->selectRaw('b.code, sum(l.amount_minor) as total')->get()->map(fn (object $t): array => ['branch' => (string) $t->code, 'total' => PageSupport::money((int) $t->total, $currency)])->values()->all()],
            'months' => $months,
            'branches' => $branches->map(fn (object $b): array => ['id' => (string) $b->id, 'label' => "{$b->code} · {$b->name}"])->values()->all(),
            'branchId' => $branchId,
            'accounts' => $accounts->map(fn (object $a): array => ['id' => (string) $a->id, 'code' => (string) $a->code, 'name' => (string) $a->name, 'type' => (string) $a->type])->values()->all(),
            'grid' => array_map(fn (string $accountId, array $amounts): array => ['account_id' => $accountId, 'amounts' => $amounts], array_keys($grid), array_values($grid)),
            'can' => ['edit' => $canPrepare && $row->status === 'draft', 'submit' => $canPrepare && $row->status === 'draft',
                'decide' => $canApprove && $row->status === 'submitted' && $row->prepared_by !== $actor, 'revise' => $canPrepare && in_array($row->status, ['approved', 'superseded'], true)],
        ]);
    }

    public function saveRows(Request $request, string $budget, BudgetService $budgets): RedirectResponse
    {
        /** @var array{branch_id: string, rows: list<array{account_id: string, amounts: list<string|null>}>} $data */
        $data = $request->validate(['branch_id' => ['required', 'uuid'], 'rows' => ['present', 'array'], 'rows.*.account_id' => ['required', 'uuid'], 'rows.*.amounts' => ['required', 'array', 'size:12']]);
        $currency = PageSupport::entity()['currency'];
        $rows = [];
        foreach ($data['rows'] as $i => $row) {
            $rows[] = ['account_id' => $row['account_id'], 'amounts' => array_map(fn (int $m, ?string $v): int => trim((string) $v) === '' ? 0 : PageSupport::minor("rows.{$i}.amounts.{$m}", $v, $currency),
                array_keys($row['amounts']), $row['amounts'])];
        }
        $budgets->saveBranchRows($budget, $data['branch_id'], $rows, PageSupport::actor($request));

        return back()->with('status', 'Budget saved.');
    }

    public function paste(Request $request, string $budget, BudgetService $budgets): RedirectResponse
    {
        /** @var array{branch_id: string, text: string} $data */
        $data = $request->validate(['branch_id' => ['required', 'uuid'], 'text' => ['required', 'string', 'max:200000']]);
        $result = $budgets->paste($budget, $data['branch_id'], $data['text'], PageSupport::actor($request));
        if ($result['errors'] !== []) {
            throw ValidationException::withMessages(['text' => implode(' ', array_slice($result['errors'], 0, 5))]);
        }

        return back()->with('status', "{$result['rows']} account row(s) pasted.");
    }

    public function copyActuals(Request $request, string $budget, BudgetService $budgets): RedirectResponse
    {
        /** @var array{percent: string} $data */
        $data = $request->validate(['percent' => ['required', 'string', 'regex:/^-?\d{1,3}(\.\d{1,2})?$/']], ['percent.regex' => 'Enter a percentage like 8 or -2.5.']);
        $negative = str_starts_with($data['percent'], '-');
        $bp = PageSupport::basisPoints('percent', ltrim($data['percent'], '-'));
        $written = $budgets->copyLastYearActuals($budget, $negative ? -$bp : $bp, PageSupport::actor($request));

        return back()->with('status', "{$written} monthly amount(s) copied from last year's actuals.");
    }

    public function transition(Request $request, string $budget, string $action, BudgetService $budgets): RedirectResponse
    {
        $actor = PageSupport::actor($request);
        switch ($action) {
            case 'submit':
                $budgets->submit($budget, $actor);

                return back()->with('status', 'Budget sent for approval.');
            case 'approve':
                $budgets->approve($budget, $actor);

                return back()->with('status', 'Budget approved. The variance report now compares actuals with it.');
            case 'return':
                /** @var array{reason: string} $data */
                $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
                $budgets->returnToDraft($budget, $data['reason'], $actor);

                return back()->with('status', 'Budget returned to the preparer.');
            default:
                $id = $budgets->newVersion($budget, $actor);

                return redirect("/budgets/{$id}")->with('status', 'New draft version made from this budget.');
        }
    }

    public function variance(Request $request, BudgetVarianceQuery $query): Response
    {
        $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);
        $entity = PageSupport::entity();
        [$year, $periodNo, $branchId] = $this->varianceFilters($request, $entity['id']);
        $result = $query->variance($entity['id'], $year, $periodNo, $branchId);
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);
        $percent = fn (?int $bp): ?string => self::signedPercent($bp);

        return Inertia::render('budgets/Variance', [
            'budget' => $result['budget'],
            'filters' => ['year' => (string) $year, 'period' => (string) $result['period']['no'], 'branch' => $branchId ?? ''],
            'periodLabel' => $result['period']['starts'] === '' ? '' : CarbonImmutable::parse($result['period']['starts'])->format('F Y'),
            'years' => DB::table('budgets')->where('entity_id', $entity['id'])->distinct()->orderBy('fiscal_year')->pluck('fiscal_year')->map(fn (mixed $y): array => ['value' => (string) $y, 'label' => self::yearLabel((int) $y)])->values()->all(),
            'periods' => DB::table('fiscal_periods')->where('entity_id', $entity['id'])->where('year', $year)->orderBy('period')->get(['period', 'starts'])
                ->map(fn (object $p): array => ['value' => (string) $p->period, 'label' => CarbonImmutable::parse((string) $p->starts)->format('F Y')])->values()->all(),
            'branches' => DB::table('branches')->where('entity_id', $entity['id'])->orderBy('code')->get(['id', 'code'])->map(fn (object $b): array => ['value' => (string) $b->id, 'label' => (string) $b->code])->values()->all(),
            'rows' => array_map(fn (array $r): array => ['account_id' => $r['account_id'], 'account' => "{$r['code']} {$r['name']}", 'group' => $r['group'], 'branch' => $r['branch_code'],
                'budget' => $money($r['budget_minor']), 'actual' => $money($r['actual_minor']), 'variance' => $money($r['variance_minor']), 'variance_pct' => $percent($r['variance_bp']), 'favourable' => $r['favourable'],
                'ytd_budget' => $money($r['ytd_budget_minor']), 'ytd_actual' => $money($r['ytd_actual_minor']), 'ytd_variance' => $money($r['ytd_variance_minor']), 'ytd_variance_pct' => $percent($r['ytd_variance_bp']),
                'ytd_favourable' => $r['ytd_favourable'], 'activity' => '/reports/account-activity?'.http_build_query(['account_id' => $r['account_id'], 'from' => $result['period']['starts'], 'to' => $result['period']['ends']])],
                $result['rows']),
            'totals' => self::totals($result['rows'], $money),
        ]);
    }

    public function exportVariance(Request $request, BudgetVarianceQuery $query): StreamedResponse
    {
        $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);
        $entity = PageSupport::entity();
        [$year, $periodNo, $branchId] = $this->varianceFilters($request, $entity['id']);
        $result = $query->variance($entity['id'], $year, $periodNo, $branchId);
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);
        $columns = array_map(fn (array $c): array => ['key' => $c[0], 'label' => $c[1], 'align' => 'left'], [['group', 'Group'], ['account', 'Account'], ['branch', 'Branch'], ['budget', 'Budget (month)'],
            ['actual', 'Actual (month)'], ['variance', 'Variance (month)'], ['variance_pct', 'Variance %'], ['ytd_budget', 'Budget (YTD)'], ['ytd_actual', 'Actual (YTD)'], ['ytd_variance', 'Variance (YTD)'], ['ytd_variance_pct', 'Variance % (YTD)']]);
        $rows = array_map(fn (array $r): array => ['cells' => ['group' => $r['group'], 'account' => "{$r['code']} {$r['name']}", 'branch' => $r['branch_code'], 'budget' => $money($r['budget_minor']),
            'actual' => $money($r['actual_minor']), 'variance' => $money($r['variance_minor']), 'variance_pct' => self::signedPercent($r['variance_bp']) ?? '',
            'ytd_budget' => $money($r['ytd_budget_minor']), 'ytd_actual' => $money($r['ytd_actual_minor']), 'ytd_variance' => $money($r['ytd_variance_minor']),
            'ytd_variance_pct' => self::signedPercent($r['ytd_variance_bp']) ?? '']], $result['rows']);
        $format = $request->query('format') === 'xlsx' ? 'xlsx' : 'csv';

        return FixedAssetsPageController::download('Budget variance', $columns, $rows, "budget-variance-{$year}-{$result['period']['no']}", $format);
    }

    /** @return array{0: int, 1: int, 2: string|null} fiscal year, fiscal month (defaults: this year and month) and branch */
    private function varianceFilters(Request $request, string $entityId): array
    {
        $today = app(BusinessClock::class)->today();
        $year = is_numeric($request->query('year')) ? (int) $request->query('year') : app(FiscalCalendar::class)->fiscalYear($today);
        $current = DB::table('fiscal_periods')->where('entity_id', $entityId)->where('starts', '<=', $today->toDateString())->where('ends', '>=', $today->toDateString())->value('period');
        $period = is_numeric($request->query('period')) ? (int) $request->query('period') : (int) ($current ?? 1);
        $branch = $request->query('branch');

        return [$year, $period, is_string($branch) && preg_match('/^[0-9a-f-]{36}$/', $branch) === 1 ? $branch : null];
    }

    /**
     * @param list<array{type: string, budget_minor: int, actual_minor: int, ytd_budget_minor: int, ytd_actual_minor: int}> $rows
     * @param callable(int): string $money
     * @return array<string, string>
     */
    private static function totals(array $rows, callable $money): array
    {
        $expense = array_values(array_filter($rows, fn (array $r): bool => $r['type'] === 'expense'));

        return ['expense_budget' => $money(array_sum(array_column($expense, 'budget_minor'))), 'expense_actual' => $money(array_sum(array_column($expense, 'actual_minor'))),
            'expense_ytd_budget' => $money(array_sum(array_column($expense, 'ytd_budget_minor'))), 'expense_ytd_actual' => $money(array_sum(array_column($expense, 'ytd_actual_minor')))];
    }

    public static function signedPercent(?int $bp): ?string
    {
        return $bp === null ? null : ($bp < 0 ? '-' : '').PageSupport::percent(abs($bp));
    }

    public static function yearLabel(int $fiscalYear): string
    {
        $startMonth = (int) (DB::table('tenants')->value('fiscal_year_start_month') ?? 1);

        return $startMonth === 1 ? "FY{$fiscalYear}" : 'FY'.$fiscalYear.'–'.substr((string) ($fiscalYear + 1), 2);
    }
}
