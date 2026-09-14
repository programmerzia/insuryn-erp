<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Periods\FiscalPeriodService;
use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Exceptions\PeriodTransitionException;
use App\Modules\Platform\Authorization\PermissionDenied;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\travelTo;

/**
 * Design §5.3 fiscal period: open ─soft_lock─▶ soft_locked ─lock─▶ locked, reopen(reason) back to open,
 * each transition behind its periods.* permission and audited. §5.7: lock refuses open close tasks and
 * reconciliation variances.
 */
beforeEach(function (): void {
    // Slice 2.1b (D-56): a month is locked only once it has ended on the business clock; these cases close September, so they run on 1 October.
    travelTo(CarbonImmutable::parse('2026-10-01 10:00'));
    Queue::fake();
    $this->ctx = seedDemoTenant();
    $this->closer = userWithPermissions($this->ctx['tenant_id'], ['periods.soft_lock', 'periods.lock', 'periods.reopen']);
    $this->september = asTenant($this->ctx['tenant_id'], fn () => (string) DB::table('fiscal_periods')->where('period', 3)->value('id'));
});

function periods(): FiscalPeriodService
{
    return app(FiscalPeriodService::class);
}

/** @return array{status: string, locked_by: string|null, locked_at: string|null} */
function periodState(string $tenantId, string $periodId): array
{
    return asTenant($tenantId, function () use ($periodId): array {
        $row = DB::table('fiscal_periods')->where('id', $periodId)->first(['status', 'locked_by', 'locked_at']);

        return ['status' => (string) $row?->status, 'locked_by' => $row?->locked_by, 'locked_at' => $row?->locked_at];
    });
}

it('soft-locks then locks a period, recording who locked it, with audit and outbox messages', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        periods()->softLock($this->september, $this->closer);
        expect(periodState($this->ctx['tenant_id'], $this->september)['status'])->toBe('soft_locked');

        periods()->lock($this->september, $this->closer);
        $state = periodState($this->ctx['tenant_id'], $this->september);
        expect($state['status'])->toBe('locked')->and($state['locked_by'])->toBe($this->closer)->and($state['locked_at'])->not->toBeNull();

        $audits = DB::table('audit_events')->where('object_id', $this->september)->orderBy('occurred_at')->orderBy('id')->get(['action', 'permission', 'actor_user_id']);
        expect($audits->pluck('action')->all())->toBe(['period.soft_locked', 'period.locked'])
            ->and($audits->pluck('permission')->all())->toBe(['periods.soft_lock', 'periods.lock'])
            ->and($audits->pluck('actor_user_id')->unique()->all())->toBe([$this->closer])
            ->and(DB::table('outbox')->where('message_type', 'PeriodLocked')->count())->toBe(1);
    });
});

it('requires the permission for each transition', function (string $transition, string $missing): void {
    $withoutPermission = userWithPermissions($this->ctx['tenant_id'], array_values(array_diff(['periods.soft_lock', 'periods.lock', 'periods.reopen'], [$missing])));

    asTenant($this->ctx['tenant_id'], function () use ($transition, $withoutPermission, $missing): void {
        if ($transition !== 'softLock') {
            periods()->softLock($this->september, $this->closer);
        }
        if ($transition === 'reopen') {
            periods()->lock($this->september, $this->closer);
        }
        $before = periodState($this->ctx['tenant_id'], $this->september)['status'];

        $denied = thrownBy(fn () => match ($transition) {
            'softLock' => periods()->softLock($this->september, $withoutPermission),
            'lock' => periods()->lock($this->september, $withoutPermission),
            default => periods()->reopen($this->september, $withoutPermission, 'late invoice'),
        }, PermissionDenied::class);

        expect($denied->permission)->toBe($missing)
            ->and(periodState($this->ctx['tenant_id'], $this->september)['status'])->toBe($before);
    });
})->with([
    'soft lock' => ['softLock', 'periods.soft_lock'],
    'lock' => ['lock', 'periods.lock'],
    'reopen' => ['reopen', 'periods.reopen'],
]);

it('refuses transitions that are not in the state machine', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        expect(thrownBy(fn () => periods()->lock($this->september, $this->closer), PeriodTransitionException::class)->reasonCode)->toBe('INVALID_PERIOD_TRANSITION')
            ->and(thrownBy(fn () => periods()->reopen($this->september, $this->closer, 'nothing to reopen'), PeriodTransitionException::class)->reasonCode)->toBe('INVALID_PERIOD_TRANSITION');

        periods()->softLock($this->september, $this->closer);
        expect(thrownBy(fn () => periods()->softLock($this->september, $this->closer), PeriodTransitionException::class)->reasonCode)->toBe('INVALID_PERIOD_TRANSITION');
    });
});

it('reopens a locked period only with a reason, invalidating the close run and emitting PeriodReopened', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        periods()->softLock($this->september, $this->closer);
        periods()->lock($this->september, $this->closer);
        $closeRunId = (string) Str::uuid7();
        DB::table('period_close_runs')->insert(['id' => $closeRunId, 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
            'period_id' => $this->september, 'status' => 'completed', 'started_by' => $this->closer, 'started_at' => now(), 'completed_at' => now()]);

        expect(thrownBy(fn () => periods()->reopen($this->september, $this->closer, ' '), PeriodTransitionException::class)->reasonCode)->toBe('REASON_REQUIRED');

        periods()->reopen($this->september, $this->closer, 'supplier invoice received late');

        $state = periodState($this->ctx['tenant_id'], $this->september);
        $audit = DB::table('audit_events')->where('object_id', $this->september)->where('action', 'period.reopened')->first();
        expect($state)->toBe(['status' => 'open', 'locked_by' => null, 'locked_at' => null])
            ->and($audit?->reason)->toBe('supplier invoice received late')
            ->and(json_decode((string) $audit?->before, true))->toBe(['status' => 'locked'])
            ->and(DB::table('outbox')->where('message_type', 'PeriodReopened')->count())->toBe(1)
            ->and(DB::table('period_close_runs')->where('id', $closeRunId)->value('status'))->toBe('reopened');
    });
});

it('refuses to lock while close tasks are open or a reconciliation shows a variance', function (string $blocker): void {
    asTenant($this->ctx['tenant_id'], function () use ($blocker): void {
        periods()->softLock($this->september, $this->closer);
        if ($blocker === 'close_task') {
            $runId = (string) Str::uuid7();
            DB::table('period_close_runs')->insert(['id' => $runId, 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
                'period_id' => $this->september, 'started_by' => $this->closer, 'started_at' => now()]);
            DB::table('period_close_tasks')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'close_run_id' => $runId,
                'code' => 'bank_reconciliation', 'order_no' => 3, 'owner_role' => 'treasury', 'status' => 'pending']);
        } else {
            DB::table('reconciliation_runs')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
                'subledger' => 'premium', 'period_id' => $this->september, 'run_at' => now(), 'subledger_balance_minor' => 100, 'gl_balance_minor' => 90,
                'variance_minor' => 10, 'status' => 'variance']);
        }

        $refusal = thrownBy(fn () => periods()->lock($this->september, $this->closer), PeriodTransitionException::class);

        expect($refusal->reasonCode)->toBe($blocker === 'close_task' ? 'CLOSE_TASKS_OPEN' : 'RECONCILIATION_VARIANCE')
            ->and(periodState($this->ctx['tenant_id'], $this->september)['status'])->toBe('soft_locked');
    });
})->with(['close_task', 'reconciliation_variance']);

it('accepts postings again once a locked period is reopened', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        periods()->softLock($this->september, $this->closer);
        periods()->lock($this->september, $this->closer);
        $post = function (string $key): array {
            $event = DB::transaction(fn () => app(SubmitAccountingEvent::class)(
                $this->ctx['entity_id'], 'PREMIUM_RECEIVED', 'test', (string) Str::uuid7(), $key,
                CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'BDT', ['amount' => 1_000],
                ['branch' => $this->ctx['branch_id'], 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7()]));

            return app(PostingEngine::class)->post($event->id);
        };

        expect($post('while-locked'))->toBe([]);

        periods()->reopen($this->september, $this->closer, 'correction needed');

        expect($post('after-reopen'))->toHaveCount(1);
    });
});
