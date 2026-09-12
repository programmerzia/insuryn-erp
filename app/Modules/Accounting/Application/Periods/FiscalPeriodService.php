<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Periods;

use App\Modules\Accounting\Domain\Enums\PeriodStatus;
use App\Modules\Accounting\Exceptions\PeriodTransitionException;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Messaging\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design §5.3 fiscal period state machine:
 *   open ─soft_lock─▶ soft_locked ─lock─▶ locked;  soft_locked | locked ─reopen(reason)─▶ open.
 * Each transition runs under the period's row lock, requires its periods.* permission, is audited and
 * announced through the outbox (PeriodLocked, PeriodReopened). §5.7 INVARIANT: lock refuses while close
 * tasks are open or a reconciliation shows a variance.
 */
final class FiscalPeriodService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
        private readonly Outbox $outbox,
    ) {}

    public function softLock(string $periodId, string $actorUserId): void
    {
        $this->transition($periodId, $actorUserId, 'periods.soft_lock', [PeriodStatus::Open], PeriodStatus::SoftLocked, 'period.soft_locked');
    }

    public function lock(string $periodId, string $actorUserId): void
    {
        $this->transition($periodId, $actorUserId, 'periods.lock', [PeriodStatus::SoftLocked], PeriodStatus::Locked, 'period.locked',
            fn () => $this->assertReadyToLock($periodId));
    }

    /**
     * Reopening invalidates the period's close runs: posting into a reopened period requires the close
     * to be re-executed (§5.3).
     */
    public function reopen(string $periodId, string $actorUserId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new PeriodTransitionException('REASON_REQUIRED', 'Reopening a period requires a reason.');
        }
        $this->transition($periodId, $actorUserId, 'periods.reopen', [PeriodStatus::SoftLocked, PeriodStatus::Locked], PeriodStatus::Open, 'period.reopened',
            fn () => DB::table('period_close_runs')->where('period_id', $periodId)->where('status', '<>', 'reopened')->update(['status' => 'reopened']),
            trim($reason));
    }

    /**
     * @param list<PeriodStatus> $allowedFrom
     * @param (callable(): mixed)|null $guard runs under the row lock before the status changes
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
    ): void {
        $this->permissions->authorize($actorUserId, $permission);

        DB::transaction(function () use ($periodId, $actorUserId, $permission, $allowedFrom, $to, $auditAction, $guard, $reason): void {
            $from = $this->lockedStatus($periodId);
            if (! in_array($from, $allowedFrom, true)) {
                throw new PeriodTransitionException('INVALID_PERIOD_TRANSITION', "Period {$periodId} cannot move from {$from->value} to {$to->value}.");
            }
            if ($guard !== null) {
                $guard();
            }

            $locked = $to === PeriodStatus::Locked;
            DB::table('fiscal_periods')->where('id', $periodId)->update([
                'status' => $to->value,
                'locked_by' => $locked ? $actorUserId : null,
                'locked_at' => $locked ? CarbonImmutable::now() : null,
            ]);
            $this->audit->record($auditAction, AuditSubject::of('fiscal_period', $periodId), ['status' => $from->value], ['status' => $to->value],
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
        if (DB::table('reconciliation_runs')->where('period_id', $periodId)->where('status', 'variance')->exists()) {
            throw new PeriodTransitionException('RECONCILIATION_VARIANCE', "Period {$periodId} has an unresolved reconciliation variance.");
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
