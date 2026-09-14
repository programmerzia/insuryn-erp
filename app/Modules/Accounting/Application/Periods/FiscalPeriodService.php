<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Periods;

use App\Modules\Accounting\Application\Close\PendingDocumentsQuery;
use App\Modules\Accounting\Application\Contracts\PendingCloseDocument;
use App\Modules\Accounting\Application\Queries\FiscalPeriodQuery;
use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Accounting\Domain\Enums\PeriodStatus;
use App\Modules\Accounting\Exceptions\PeriodTransitionException;
use App\Modules\Platform\Approvals\ApprovalFacts;
use App\Modules\Platform\Approvals\ApprovalService;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Messaging\Outbox;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design §5.3 fiscal period state machine:
 *   open ─soft_lock─▶ soft_locked ─lock─▶ locked;  soft_locked | locked ─reopen(reason)─▶ open.
 * Each transition runs under the period's row lock, requires its periods.* permission, is audited and
 * announced through the outbox (PeriodLocked, PeriodReopened). §5.7 INVARIANT: lock refuses while close
 * tasks are open or a reconciliation shows a variance — a recorded variance run, or a variance recomputed under the
 * period's row lock (postings made after the reconciliation tasks, e.g. while soft-locked, are never trusted as clean) — and, since
 * slice 2.1b (D-55), while documents dated in the period still wait for approval, release or posting.
 */
final class FiscalPeriodService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
        private readonly Outbox $outbox,
        private readonly ApprovalService $approvals,
        private readonly ReconciliationService $reconciliation,
        private readonly PendingDocumentsQuery $pendingDocuments,
        private readonly FiscalPeriodQuery $periodQuery,
        private readonly BusinessClock $clock,
    ) {}

    /** @throws PeriodTransitionException PERIOD_LAST_DAY_NOT_REACHED (slice 2.1b, D-56: soft lock from the period's last day on the business clock) */
    public function softLock(string $periodId, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, 'periods.soft_lock');
        $dates = $this->dates($periodId);
        if ($dates !== null && $dates['today']->lessThan($dates['ends'])) {
            throw new PeriodTransitionException('PERIOD_LAST_DAY_NOT_REACHED', sprintf('Period %s can be soft-locked from its last day, %s.', $periodId, $dates['ends']->format('j M Y')));
        }
        $this->transition($periodId, $actorUserId, 'periods.soft_lock', [PeriodStatus::Open], PeriodStatus::SoftLocked, 'period.soft_locked');
    }

    /**
     * Slice 2.1b (DECISION D-56, CQ-C5): the lock needs the period to have ended on the business clock. Before that it is refused
     * (PERIOD_NOT_ENDED) unless the actor is a CFO — a holder of periods.reopen (ASSUMPTION A-155) — who gives a written reason
     * (EARLY_LOCK_REASON_REQUIRED without one); the reason and `early_lock` are recorded on the `period.locked` audit event.
     *
     * @throws PeriodTransitionException PERIOD_NOT_ENDED | EARLY_LOCK_REASON_REQUIRED | CLOSE_TASKS_OPEN | PERIOD_HAS_PENDING_DOCUMENTS | RECONCILIATION_VARIANCE
     */
    public function lock(string $periodId, string $actorUserId, ?string $earlyLockReason = null): void
    {
        $this->permissions->authorize($actorUserId, 'periods.lock');
        $dates = $this->dates($periodId);
        $early = $dates !== null && ! $dates['today']->greaterThan($dates['ends']);
        $reason = null;
        $after = [];
        if ($early) {
            if (! $this->permissions->has($actorUserId, 'periods.reopen')) {
                throw new PeriodTransitionException('PERIOD_NOT_ENDED', sprintf('Period %s has not ended; it can be locked from %s. Only a CFO can lock it earlier, with a written reason.',
                    $periodId, $dates['ends']->addDay()->format('j M Y')));
            }
            $reason = trim((string) $earlyLockReason);
            if ($reason === '') {
                throw new PeriodTransitionException('EARLY_LOCK_REASON_REQUIRED', sprintf('Period %s ends on %s. Locking it earlier needs a written reason.', $periodId, $dates['ends']->format('j M Y')));
            }
            $after = ['early_lock' => true, 'period_ends' => $dates['ends']->toDateString()];
        }
        $this->transition($periodId, $actorUserId, 'periods.lock', [PeriodStatus::SoftLocked], PeriodStatus::Locked, 'period.locked',
            fn () => $this->assertReadyToLock($periodId), $reason, after: $after);
    }

    /**
     * Whether a period has reached its last day or ended, on its entity's business clock.
     *
     * @return array{today: CarbonImmutable, ends: CarbonImmutable}|null null when the period does not exist
     */
    public function dates(string $periodId): ?array
    {
        $period = DB::table('fiscal_periods')->where('id', $periodId)->first(['entity_id', 'ends']);

        return $period === null ? null : ['today' => $this->clock->today((string) $period->entity_id), 'ends' => CarbonImmutable::parse((string) $period->ends)];
    }

    /**
     * §5.3 reopen(approval + reason). When an approval policy for `fiscal_period_reopen` matches, the reopen
     * waits for it and the approval id is returned; otherwise the period reopens now and null is returned.
     * Reopening invalidates the period's close runs: posting into a reopened period requires the close
     * to be re-executed.
     */
    public function reopen(string $periodId, string $actorUserId, string $reason): ?string
    {
        if (trim($reason) === '') {
            throw new PeriodTransitionException('REASON_REQUIRED', 'Reopening a period requires a reason.');
        }
        $this->permissions->authorize($actorUserId, 'periods.reopen');

        return DB::transaction(function () use ($periodId, $actorUserId, $reason): ?string {
            $this->assertStatusIn($periodId, [PeriodStatus::SoftLocked, PeriodStatus::Locked], PeriodStatus::Open);
            $approvalId = $this->approvals->request('fiscal_period_reopen', $periodId, new ApprovalFacts(0), $actorUserId, $this->clock->today(), ['reason' => trim($reason)]);
            if ($approvalId !== null) {
                $this->audit->record('period.reopen_requested', AuditSubject::of('fiscal_period', $periodId), null, ['approval_id' => $approvalId],
                    trim($reason), 'periods.reopen', Actor::user($actorUserId));

                return $approvalId;
            }
            $this->performReopen($periodId, $actorUserId, trim($reason));

            return null;
        });
    }

    /** Called by PeriodReopenApprovalHandler once the reopen approval is complete. */
    public function completeApprovedReopen(string $periodId, string $requestedBy, string $reason, string $finalApproverId): void
    {
        $this->performReopen($periodId, $requestedBy, $reason);
    }

    private function performReopen(string $periodId, string $actorUserId, string $reason): void
    {
        $this->transition($periodId, $actorUserId, 'periods.reopen', [PeriodStatus::SoftLocked, PeriodStatus::Locked], PeriodStatus::Open, 'period.reopened',
            fn () => DB::table('period_close_runs')->where('period_id', $periodId)->where('status', '<>', 'reopened')->update(['status' => 'reopened']),
            $reason, authorize: false);
    }

    /** @param list<PeriodStatus> $allowedFrom */
    private function assertStatusIn(string $periodId, array $allowedFrom, PeriodStatus $to): PeriodStatus
    {
        $from = $this->lockedStatus($periodId);
        if (! in_array($from, $allowedFrom, true)) {
            throw new PeriodTransitionException('INVALID_PERIOD_TRANSITION', "Period {$periodId} cannot move from {$from->value} to {$to->value}.");
        }

        return $from;
    }

    /**
     * @param list<PeriodStatus> $allowedFrom
     * @param (callable(): mixed)|null $guard runs under the row lock before the status changes
     * @param array<string, mixed> $after recorded on the audit event next to the new status
     */
    private function transition(
        string $periodId,
        string $actorUserId,
        string $permission,
        array $allowedFrom,
        PeriodStatus $to,
        string $auditAction,
        ?callable $guard = null,
        ?string $reason = null,
        bool $authorize = true,
        array $after = [],
    ): void {
        if ($authorize) {
            $this->permissions->authorize($actorUserId, $permission);
        }

        DB::transaction(function () use ($periodId, $actorUserId, $permission, $allowedFrom, $to, $auditAction, $guard, $reason, $after): void {
            $from = $this->assertStatusIn($periodId, $allowedFrom, $to);
            if ($guard !== null) {
                $guard();
            }

            $locked = $to === PeriodStatus::Locked;
            DB::table('fiscal_periods')->where('id', $periodId)->update([
                'status' => $to->value,
                'locked_by' => $locked ? $actorUserId : null,
                'locked_at' => $locked ? CarbonImmutable::now() : null,
            ]);
            $this->audit->record($auditAction, AuditSubject::of('fiscal_period', $periodId), ['status' => $from->value], ['status' => $to->value] + $after,
                $reason, $permission, Actor::user($actorUserId));
            $this->announce($periodId, $to, $actorUserId, $reason);
        });
    }

    private function lockedStatus(string $periodId): PeriodStatus
    {
        $status = DB::table('fiscal_periods')->where('id', $periodId)->lockForUpdate()->value('status');
        if (! is_string($status)) {
            throw new PeriodTransitionException('PERIOD_MISSING', "Period {$periodId} does not exist.");
        }

        return PeriodStatus::from($status);
    }

    private function assertReadyToLock(string $periodId): void
    {
        $openTasks = DB::table('period_close_tasks as t')->join('period_close_runs as r', 'r.id', '=', 't.close_run_id')
            ->where('r.period_id', $periodId)->where('r.status', '<>', 'reopened')->whereNotIn('t.status', ['done', 'skipped'])->count();
        if ($openTasks > 0) {
            throw new PeriodTransitionException('CLOSE_TASKS_OPEN', "Period {$periodId} has {$openTasks} close task(s) not done or skipped.");
        }
        // Slice 2.1b (D-55, CQ-C4): documents dated in the period still waiting for approval, release or posting hold the lock. No override.
        $period = $this->periodQuery->find($periodId);
        $pending = $period === null ? [] : $this->pendingDocuments->forPeriod($period);
        if ($pending !== []) {
            throw new PeriodTransitionException('PERIOD_HAS_PENDING_DOCUMENTS',
                "Period {$periodId} has documents dated in it still waiting: ".PendingDocumentsQuery::summary($pending).'. Approve or reject each one, or move a pending manual journal to the next period, before locking.',
                ['pending' => array_map(fn (PendingCloseDocument $d): array => $d->toArray(), $pending)]);
        }
        if (DB::table('reconciliation_runs')->where('period_id', $periodId)->where('status', 'variance')->exists()) {
            throw new PeriodTransitionException('RECONCILIATION_VARIANCE', "Period {$periodId} has an unresolved reconciliation variance.");
        }
        $variances = $this->reconciliation->currentVariances($periodId);
        if ($variances !== []) {
            $listed = implode(', ', array_map(fn (string $subledger, int $variance): string => "{$subledger} {$variance}", array_keys($variances), $variances));
            throw new PeriodTransitionException('RECONCILIATION_VARIANCE', "Period {$periodId} does not reconcile as the ledger stands (subledger − GL: {$listed}). Rerun the reconciliation tasks.");
        }
    }

    private function announce(string $periodId, PeriodStatus $to, string $actorUserId, ?string $reason): void
    {
        $messageType = match ($to) {
            PeriodStatus::Locked => 'PeriodLocked',
            PeriodStatus::Open => 'PeriodReopened',
            PeriodStatus::SoftLocked => 'PeriodSoftLocked',
        };
        $this->outbox->add($messageType, ['period_id' => $periodId, 'actor_user_id' => $actorUserId, 'reason' => $reason]);
    }
}
