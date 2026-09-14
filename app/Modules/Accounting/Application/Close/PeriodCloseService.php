<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Close;

use App\Modules\Accounting\Application\Contracts\CloseCheckResult;
use App\Modules\Accounting\Application\Periods\FiscalPeriodService;
use App\Modules\Accounting\Application\Queries\FiscalPeriodQuery;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;
use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Accounting\Exceptions\AccountingException;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design §5.7 month-end close as a first-class workflow: a run per period with the catalogue's tasks. A task runs only after its
 * dependencies are done or skipped; its outcome is done or blocked (rerunnable). Task 13 soft-locks the period, task 16 locks it through
 * FiscalPeriodService, whose INVARIANT refuses while tasks are open or a reconciliation has a variance. Reopening a period marks its run
 * `reopened`; a new run starts the close again.
 */
final class PeriodCloseService
{
    public function __construct(
        private readonly CloseTaskCatalogue $catalogue,
        private readonly CloseTaskExecutor $executor,
        private readonly FiscalPeriodQuery $periodQuery,
        private readonly FiscalPeriodService $periods,
        private readonly ReconciliationService $reconciliation,
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation PERIOD_NOT_OPEN | CLOSE_ALREADY_RUNNING */
    public function start(string $periodId, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, 'periods.soft_lock');

        return DB::transaction(function () use ($periodId, $actorUserId): string {
            $period = DB::table('fiscal_periods')->where('id', $periodId)->lockForUpdate()->first(['id', 'entity_id', 'status']);
            if ($period === null || ! in_array($period->status, ['open', 'soft_locked'], true)) {
                throw new BusinessRuleViolation('PERIOD_NOT_OPEN', "Period {$periodId} is not open for closing.");
            }
            if (DB::table('period_close_runs')->where('period_id', $periodId)->where('status', 'running')->exists()) {
                throw new BusinessRuleViolation('CLOSE_ALREADY_RUNNING', "Period {$periodId} already has a close run in progress.");
            }
            $runId = (string) Str::uuid7();
            DB::table('period_close_runs')->insert(['id' => $runId, 'tenant_id' => TenantContext::id(), 'entity_id' => $period->entity_id, 'period_id' => $periodId,
                'status' => 'running', 'started_by' => $actorUserId, 'started_at' => CarbonImmutable::now()]);
            DB::table('period_close_tasks')->insert(array_map(fn (CloseTaskDefinition $task): array => ['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(),
                'close_run_id' => $runId, 'code' => $task->code, 'order_no' => $task->orderNo, 'depends_on' => json_encode($task->dependsOn, JSON_THROW_ON_ERROR),
                'owner_role' => $task->ownerRole, 'status' => 'pending'], $this->catalogue->tasks()));
            $this->audit->record('close.started', AuditSubject::of('period_close_run', $runId), null, ['period_id' => $periodId], null, 'periods.soft_lock', Actor::user($actorUserId));

            return $runId;
        });
    }

    /** @throws BusinessRuleViolation CLOSE_RUN_NOT_ACTIVE | TASK_ALREADY_DONE | DEPENDENCIES_OPEN */
    public function execute(string $taskId, string $actorUserId, ?string $note = null): string
    {
        [$task, $definition, $period] = $this->prepare($taskId, $actorUserId);
        $this->assertDependenciesClear($task->close_run_id, $definition);
        if ($definition->kind === CloseTaskKind::PeriodLock) {
            return $this->lockPeriod($task->id, $task->close_run_id, $period, $actorUserId, $note);
        }

        try {
            $result = $this->executor->execute($definition, $period, $task->close_run_id, $actorUserId, $note);
        } catch (BusinessRuleViolation|AccountingException $refused) {
            $result = CloseCheckResult::blocked($refused->getMessage(), ['reason' => $refused->reasonCode]);
        }

        return $this->record($task->id, $result->passed ? 'done' : 'blocked', ['summary' => $result->summary, 'details' => $result->details], $definition, $actorUserId, $note);
    }

    /** @throws BusinessRuleViolation REASON_REQUIRED | TASK_NOT_SKIPPABLE | CLOSE_RUN_NOT_ACTIVE | TASK_ALREADY_DONE */
    public function skip(string $taskId, string $reason, string $actorUserId): string
    {
        if (trim($reason) === '') {
            throw new BusinessRuleViolation('REASON_REQUIRED', 'Skipping a close task requires a reason.');
        }
        [$task, $definition] = $this->prepare($taskId, $actorUserId);
        if (! $definition->skippable) {
            throw new BusinessRuleViolation('TASK_NOT_SKIPPABLE', "Close task {$definition->code} cannot be skipped; clear its blocking condition.");
        }

        return $this->record($task->id, 'skipped', ['skip_reason' => trim($reason)], $definition, $actorUserId, trim($reason));
    }

    /** @return array{0: object{id: string, close_run_id: string, code: string, status: string}, 1: CloseTaskDefinition, 2: FiscalPeriodView} */
    private function prepare(string $taskId, string $actorUserId): array
    {
        /** @var object{id: string, close_run_id: string, code: string, status: string}|null $task */
        $task = DB::table('period_close_tasks')->where('id', $taskId)->first(['id', 'close_run_id', 'code', 'status']);
        if ($task === null) {
            throw new BusinessRuleViolation('CLOSE_TASK_MISSING', "Close task {$taskId} does not exist.");
        }
        $definition = $this->catalogue->find($task->code);
        $this->permissions->authorize($actorUserId, $definition->permission);
        $run = DB::table('period_close_runs')->where('id', $task->close_run_id)->first(['period_id', 'status']);
        if ($run === null || $run->status !== 'running') {
            throw new BusinessRuleViolation('CLOSE_RUN_NOT_ACTIVE', 'This close run is no longer in progress.');
        }
        if (in_array($task->status, ['done', 'skipped'], true)) {
            throw new BusinessRuleViolation('TASK_ALREADY_DONE', "Close task {$task->code} is already {$task->status}.");
        }
        $period = $this->periodQuery->find((string) $run->period_id) ?? throw new BusinessRuleViolation('PERIOD_MISSING', 'The close run\'s period does not exist.');

        return [$task, $definition, $period];
    }

    private function assertDependenciesClear(string $closeRunId, CloseTaskDefinition $definition): void
    {
        $open = DB::table('period_close_tasks')->where('close_run_id', $closeRunId)->whereIn('code', $definition->dependsOn)
            ->whereNotIn('status', ['done', 'skipped'])->orderBy('order_no')->pluck('code')->all();
        if ($open !== []) {
            throw new BusinessRuleViolation('DEPENDENCIES_OPEN', "Close task {$definition->code} waits for: ".implode(', ', $open).'.');
        }
    }

    /**
     * Task 16: every subledger is reconciled again and the run recorded first (committed, so a refusal leaves its variance and exceptions to
     * drill into), then the task is marked done and the period locked in one transaction, so FiscalPeriodService's open-task guard sees it
     * done and its variance guard judges the ledger as it stands. Slice 2.1b (D-56): the task's note is the CFO's written reason when the period
     * has not ended yet; after the period's end it is not needed.
     */
    private function lockPeriod(string $taskId, string $closeRunId, FiscalPeriodView $period, string $actorUserId, ?string $note): string
    {
        $this->reconciliation->runAll($period->id);

        return DB::transaction(function () use ($taskId, $closeRunId, $period, $actorUserId, $note): string {
            $this->record($taskId, 'done', ['summary' => 'Period locked.', 'details' => ['period_id' => $period->id]], $this->catalogue->find('period_lock'), $actorUserId, null);
            $this->periods->lock($period->id, $actorUserId, $note);
            DB::table('period_close_runs')->where('id', $closeRunId)->update(['status' => 'completed', 'completed_at' => CarbonImmutable::now()]);

            return 'done';
        });
    }

    /** @param array<string, mixed> $result */
    private function record(string $taskId, string $status, array $result, CloseTaskDefinition $definition, string $actorUserId, ?string $note): string
    {
        return DB::transaction(function () use ($taskId, $status, $result, $definition, $actorUserId, $note): string {
            $current = DB::table('period_close_tasks')->where('id', $taskId)->lockForUpdate()->value('status');
            if (in_array($current, ['done', 'skipped'], true)) {
                throw new BusinessRuleViolation('TASK_ALREADY_DONE', "Close task {$definition->code} is already {$current}.");
            }
            $finished = $status !== 'blocked';
            DB::table('period_close_tasks')->where('id', $taskId)->update(['status' => $status, 'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'done_by' => $finished ? $actorUserId : null, 'done_at' => $finished ? CarbonImmutable::now() : null]);
            $this->audit->record("close_task.{$status}", AuditSubject::of('period_close_task', $taskId), ['status' => $current], ['status' => $status, 'code' => $definition->code],
                $note, $definition->permission, Actor::user($actorUserId));

            return $status;
        });
    }
}
