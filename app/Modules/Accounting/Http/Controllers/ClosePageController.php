<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Accounting\Application\Close\CloseRunQuery;
use App\Modules\Accounting\Application\Close\CloseTaskCatalogue;
use App\Modules\Accounting\Application\Close\PendingDocumentsQuery;
use App\Modules\Accounting\Application\Close\PeriodCloseService;
use App\Modules\Accounting\Application\Contracts\PendingCloseDocument;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Application\Periods\FiscalPeriodService;
use App\Modules\Accounting\Application\Queries\FiscalPeriodQuery;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;
use App\Modules\Accounting\Application\Setup\FiscalYearSetup;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Month-end close screens (design §5.7): periods of the primary book with their close run, and a run's tasks to execute or skip. Slice 2.1b
 * (D-55): both show the documents dated in a period that still wait for approval, release or posting; the lock stays disabled while any remains.
 */
final class ClosePageController
{
    public const AREA = ['periods.soft_lock', 'periods.lock', 'periods.reopen', 'reports.financial'];

    /** Gap fix GA-43 / GA-15: task names the code does not spell out. */
    private const TASK_NAMES = ['upr_reconciliation' => 'Unearned premium reconciliation', 'vat_reconciliation' => 'VAT payable reconciliation',
        'stamp_duty_reconciliation' => 'Stamp duty payable reconciliation', 'year_end_close' => 'Year-end close to retained earnings'];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly PendingDocumentsQuery $pendingDocuments,
        private readonly FiscalPeriodQuery $periodQuery,
        private readonly BusinessClock $clock,
    ) {}

    public function index(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $entity = PageSupport::entity();
        $periods = DB::table('fiscal_periods as p')->join('books as b', 'b.id', '=', 'p.book_id')->where('b.is_primary', true)->where('p.entity_id', $entity['id'])
            ->orderBy('p.starts')->get(['p.id', 'p.year', 'p.period', 'p.starts', 'p.ends', 'p.status']);
        $runs = DB::table('period_close_runs')->whereIn('period_id', $periods->pluck('id'))->orderBy('started_at')->get(['id', 'period_id', 'status'])->keyBy('period_id');
        $today = $this->clock->today($entity['id'])->toDateString();

        return Inertia::render('close/Index', [
            'periods' => $periods->map(fn (object $p): array => ['id' => (string) $p->id, 'label' => sprintf('%d-%02d', (int) $p->year, (int) $p->period), 'starts' => (string) $p->starts,
                'ends' => (string) $p->ends, 'status' => (string) $p->status,
                'run' => isset($runs[$p->id]) ? ['id' => (string) $runs[$p->id]->id, 'status' => (string) $runs[$p->id]->status] : null,
                // Slice 2.1b: a locked period cannot hold pending documents any more; the others list theirs.
                'pending' => $p->status === 'locked' ? [] : $this->pending((string) $p->id, $actor),
                // Slice 2.1b (D-56): soft lock from the last day, lock the day after it (on the business clock).
                'last_day_reached' => $today >= (string) $p->ends, 'ended' => $today > (string) $p->ends,
                'lock_from' => CarbonImmutable::parse((string) $p->ends)->addDay()->toDateString()])->values()->all(),
            'today' => $today,
            'can' => ['start' => $this->permissions->has($actor, 'periods.soft_lock'), 'reopen' => $this->permissions->has($actor, 'periods.reopen')],
            // Gap fix GA-05: when each nightly job last ran, with "Run now" for finance.
            'nightly' => app(\App\Http\Close\NightlyJobs::class)->panel($actor),
            // Gap fix GA-15: the fiscal year after the latest one, for the owner of the fiscal calendar.
            'nextYear' => $this->nextYear($entity['id'], $today, $actor),
        ]);
    }

    /** Gap fix GA-15: opens the fiscal year after the latest one (FiscalYearSetup::openNext). */
    public function openNextYear(Request $request, FiscalYearSetup $years): RedirectResponse
    {
        $entity = PageSupport::entity();
        $opened = $years->openNext($entity['id'], $this->clock->today($entity['id']), PageSupport::actor($request));

        return redirect('/close')->with('status', 'Fiscal year opened: 12 months from '.CarbonImmutable::parse($opened['starts'])->format('j M Y').' to '
            .CarbonImmutable::parse($opened['ends'])->format('j M Y').'.');
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
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $detail = $runs->find($run) ?? abort(404);
        $period = DB::table('fiscal_periods')->where('id', $detail['period_id'])->first(['year', 'period', 'status', 'starts', 'ends', 'entity_id']) ?? abort(404);

        $label = fn (string $code): string => self::TASK_NAMES[$code] ?? ucfirst(str_replace('_', ' ', $code));
        $statusByCode = array_column($detail['tasks'], 'status', 'code');
        // A dependency not in this run (the year-end close outside the year's last month) does not hold a task back.
        $settled = fn (string $code): bool => ! isset($statusByCode[$code]) || in_array($statusByCode[$code], ['done', 'skipped'], true);
        $open = count(array_filter($detail['tasks'], fn (array $t): bool => $t['code'] !== 'period_lock' && ! $settled($t['code'])));
        $variance = DB::table('reconciliation_runs')->where('period_id', $detail['period_id'])->where('status', 'variance')->pluck('subledger')->map(fn ($s): string => (string) $s)->all();
        $pending = $period->status === 'locked' ? [] : $this->pending($detail['period_id'], $actor);
        // Slice 2.1b (D-56): the lock waits for the period's end on the business clock; a CFO (periods.reopen) may lock earlier with a reason.
        $ends = CarbonImmutable::parse((string) $period->ends);
        $today = $this->clock->today((string) $period->entity_id);
        $ended = $today->greaterThan($ends);
        $mayLockEarly = $this->permissions->has($actor, 'periods.lock') && $this->permissions->has($actor, 'periods.reopen');
        $month = CarbonImmutable::parse((string) $period->starts)->format('F Y');

        return Inertia::render('close/Run', [
            'run' => ['id' => $detail['id'], 'status' => $detail['status'], 'started_at' => $detail['started_at'], 'completed_at' => $detail['completed_at'],
                'period' => sprintf('%d-%02d', (int) $period->year, (int) $period->period), 'period_status' => (string) $period->status,
                'starts' => (string) $period->starts, 'ends' => (string) $period->ends],
            'tasks' => array_map(fn (array $t): array => ['id' => $t['id'], 'code' => $t['code'], 'order_no' => $t['order_no'], 'owner_role' => $t['owner_role'], 'status' => $t['status'],
                'depends_on' => $t['depends_on'], 'summary' => is_array($t['result']) ? (string) ($t['result']['summary'] ?? ($t['result']['skip_reason'] ?? '')) : null,
                'done_at' => $t['done_at'],
                // Gap fix GA-09: tasks that post show their journal before running.
                'posts' => in_array($t['code'], CloseTaskCatalogue::POSTING_TASKS, true),
                'blocked_by' => array_values(array_map($label, array_filter($t['depends_on'], fn (string $code): bool => ! $settled($code))))], $detail['tasks']),
            'pending' => $pending,
            // UX brief §6.5: the lock button stays disabled with the reason until the close is clean (the lock itself re-checks everything, design §5.7).
            'lock' => match (true) {
                $detail['status'] !== 'running' => ['ready' => false, 'early' => false, 'reason' => 'This close is not running.'],
                $open > 0 => ['ready' => false, 'early' => false, 'reason' => "Finish or skip {$open} open ".($open === 1 ? 'task' : 'tasks').' before locking.'],
                ! $ended && ! $mayLockEarly => ['ready' => false, 'early' => false,
                    'reason' => "{$month} can be locked once it has ended, from ".$ends->addDay()->format('j M Y').'. A CFO can lock it earlier with a written reason.'],
                $pending !== [] => ['ready' => false, 'early' => false, 'reason' => self::pendingReason(count($pending))],
                $variance !== [] => ['ready' => false, 'early' => false, 'reason' => 'Resolve the reconciliation variance in '.implode(', ', $variance).' before locking.'],
                ! $ended => ['ready' => true, 'early' => true,
                    'reason' => "{$month} has not ended yet (it ends on ".$ends->format('j M Y').'). As CFO you can lock it now with a written reason.'],
                default => ['ready' => true, 'early' => false, 'reason' => null],
            },
            'softLock' => ['allowed' => ! $today->lessThan($ends), 'from' => $ends->toDateString()],
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

    /** Slice 2.1b (D-55): the approver moves a manual journal pending approval to the first day of the next open period, so this period can be locked. */
    public function moveJournal(Request $request, string $journal, ManualJournalService $journals): RedirectResponse
    {
        /** @var array{reason?: string|null} $data */
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $moved = $journals->moveToNextPeriod($journal, PageSupport::actor($request), $data['reason'] ?? null);

        return back()->with('status', 'Journal moved to '.$moved->transaction_date->format('j M Y').'; it waits for approval there.');
    }

    /** @return array{starts: string, ends: string, can_open: bool, opens_from: string}|null null without any fiscal year */
    private function nextYear(string $entityId, string $today, string $actor): ?array
    {
        $latest = DB::table('fiscal_periods')->where('entity_id', $entityId)->orderByDesc('ends')->first(['year', 'ends']);
        if ($latest === null) {
            return null;
        }
        $starts = CarbonImmutable::parse((string) $latest->ends)->addDay()->startOfMonth();
        $opensFrom = (string) DB::table('fiscal_periods')->where('entity_id', $entityId)->where('year', (int) $latest->year)->min('starts');

        return ['starts' => $starts->toDateString(), 'ends' => $starts->addMonths(11)->endOfMonth()->toDateString(), 'opens_from' => $opensFrom,
            'can_open' => $today >= $opensFrom && $this->permissions->has($actor, FiscalYearSetup::PERMISSION)];
    }

    public static function pendingReason(int $count): string
    {
        return $count.' '.($count === 1 ? 'document' : 'documents').' dated in this period '.($count === 1 ? 'is' : 'are')
            .' still waiting. Approve or reject '.($count === 1 ? 'it' : 'each one').', or move a pending manual journal to the next period, before locking.';
    }

    /** @return list<array{type: string, id: string, label: string, date: string, status: string, cleared_by: string, amount_minor: int|null, currency: string|null, link: string|null, movable: bool, amount: string|null, can_move: bool}> */
    private function pending(string $periodId, string $actor): array
    {
        $period = $this->periodQuery->find($periodId);
        if (! $period instanceof FiscalPeriodView) {
            return [];
        }
        $mayApprove = $this->permissions->has($actor, 'accounting.approve_journal');

        return array_map(fn (PendingCloseDocument $d): array => [...$d->toArray(),
            'amount' => $d->amountMinor === null || $d->currency === null ? null : PageSupport::money($d->amountMinor, $d->currency),
            'can_move' => $d->movable && $mayApprove], $this->pendingDocuments->forPeriod($period));
    }
}
