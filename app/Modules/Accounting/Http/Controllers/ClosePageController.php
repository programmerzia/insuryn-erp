<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Accounting\Application\Close\CloseRunQuery;
use App\Modules\Accounting\Application\Close\PeriodCloseService;
use App\Modules\Accounting\Application\Periods\FiscalPeriodService;
use App\Modules\Platform\Authorization\PermissionChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Month-end close screens (design §5.7): periods of the primary book with their close run, and a run's tasks to execute or skip. */
final class ClosePageController
{
    public const AREA = ['periods.soft_lock', 'periods.lock', 'periods.reopen', 'reports.financial'];

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function index(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $entity = PageSupport::entity();
        $periods = DB::table('fiscal_periods as p')->join('books as b', 'b.id', '=', 'p.book_id')->where('b.is_primary', true)->where('p.entity_id', $entity['id'])
            ->orderBy('p.starts')->get(['p.id', 'p.year', 'p.period', 'p.starts', 'p.ends', 'p.status']);
        $runs = DB::table('period_close_runs')->whereIn('period_id', $periods->pluck('id'))->orderBy('started_at')->get(['id', 'period_id', 'status'])->keyBy('period_id');

        return Inertia::render('close/Index', [
            'periods' => $periods->map(fn (object $p): array => ['id' => (string) $p->id, 'label' => sprintf('%d-%02d', (int) $p->year, (int) $p->period), 'starts' => (string) $p->starts,
                'ends' => (string) $p->ends, 'status' => (string) $p->status,
                'run' => isset($runs[$p->id]) ? ['id' => (string) $runs[$p->id]->id, 'status' => (string) $runs[$p->id]->status] : null])->values()->all(),
            'can' => ['start' => $this->permissions->has($actor, 'periods.soft_lock'), 'reopen' => $this->permissions->has($actor, 'periods.reopen')],
        ]);
    }

    public function start(Request $request, string $period, PeriodCloseService $close): RedirectResponse
    {
        $runId = $close->start($period, PageSupport::actor($request));

        return redirect("/close/runs/{$runId}")->with('status', 'Close started.');
    }

    public function reopen(Request $request, string $period, FiscalPeriodService $periods): RedirectResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $approvalId = $periods->reopen($period, PageSupport::actor($request), $data['reason']);

        return redirect('/close')->with('status', $approvalId === null ? 'Period reopened; its close must be run again.' : 'Reopening sent for approval.');
    }

    public function run(Request $request, string $run, CloseRunQuery $runs): Response
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);
        $detail = $runs->find($run) ?? abort(404);
        $period = DB::table('fiscal_periods')->where('id', $detail['period_id'])->first(['year', 'period', 'status']);

        return Inertia::render('close/Run', [
            'run' => ['id' => $detail['id'], 'status' => $detail['status'], 'started_at' => $detail['started_at'], 'completed_at' => $detail['completed_at'],
                'period' => $period === null ? '' : sprintf('%d-%02d', (int) $period->year, (int) $period->period), 'period_status' => (string) ($period->status ?? '')],
            'tasks' => array_map(fn (array $t): array => ['id' => $t['id'], 'code' => $t['code'], 'order_no' => $t['order_no'], 'owner_role' => $t['owner_role'], 'status' => $t['status'],
                'depends_on' => $t['depends_on'], 'summary' => is_array($t['result']) ? (string) ($t['result']['summary'] ?? ($t['result']['skip_reason'] ?? '')) : null,
                'done_at' => $t['done_at']], $detail['tasks']),
        ]);
    }

    public function execute(Request $request, string $task, PeriodCloseService $close): RedirectResponse
    {
        /** @var array{note?: string|null} $data */
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $status = $close->execute($task, PageSupport::actor($request), $data['note'] ?? null);

        return back()->with('status', $status === 'done' ? 'Task done.' : 'Task blocked — see its result.');
    }

    public function skip(Request $request, string $task, PeriodCloseService $close): RedirectResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $close->skip($task, $data['reason'], PageSupport::actor($request));

        return back()->with('status', 'Task skipped.');
    }
}
