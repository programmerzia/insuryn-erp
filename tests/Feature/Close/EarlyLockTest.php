<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\Close\PeriodCloseService;
use App\Modules\Accounting\Application\Periods\FiscalPeriodService;
use App\Modules\Accounting\Exceptions\PeriodTransitionException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Slice 2.1b (DECISION D-56, CQ-C5): on the business clock (Asia/Dhaka), a month may be soft-locked from its last day and locked once it has
 * ended. Before that the lock is refused (PERIOD_NOT_ENDED), except for a CFO — a holder of periods.reopen — who gives a written reason
 * (EARLY_LOCK_REASON_REQUIRED without one); the early lock is audited with that reason.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->september = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('fiscal_periods')->where('starts', '2026-09-01')->value('id'));
    $this->financeManager = userWithPermissions($this->ctx['tenant_id'], ['periods.soft_lock', 'periods.lock', 'reports.financial']);
    $this->cfo = userWithPermissions($this->ctx['tenant_id'], ['periods.soft_lock', 'periods.lock', 'periods.reopen', 'reports.financial']);
    $this->status = fn (): string => asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('fiscal_periods')->where('id', $this->september)->value('status'));
    $this->periods = fn (): FiscalPeriodService => app(FiscalPeriodService::class);
});

it('refuses a soft lock before the last day of the month in Dhaka, and allows it from that day', function (): void {
    travelTo(CarbonImmutable::parse('2026-09-29 17:59')); // 23:59 on 29 September in Dhaka
    asTenant($this->ctx['tenant_id'], function (): void {
        $refusal = thrownBy(fn () => ($this->periods)()->softLock($this->september, $this->cfo), PeriodTransitionException::class);
        expect($refusal->reasonCode)->toBe('PERIOD_LAST_DAY_NOT_REACHED')->and($refusal->getMessage())->toContain('30 Sep 2026')
            ->and(($this->status)())->toBe('open');
    });

    travelTo(CarbonImmutable::parse('2026-09-29 18:00')); // midnight: 30 September in Dhaka, still the 29th in UTC
    asTenant($this->ctx['tenant_id'], fn () => ($this->periods)()->softLock($this->september, $this->financeManager));
    expect(($this->status)())->toBe('soft_locked');
});

it('refuses the lock until the month has ended, then locks without a reason', function (): void {
    travelTo(CarbonImmutable::parse('2026-09-30 10:00')); // the last day
    asTenant($this->ctx['tenant_id'], function (): void {
        ($this->periods)()->softLock($this->september, $this->financeManager);
        $refusal = thrownBy(fn () => ($this->periods)()->lock($this->september, $this->financeManager, 'Everything is in'), PeriodTransitionException::class);
        expect($refusal->reasonCode)->toBe('PERIOD_NOT_ENDED')->and($refusal->getMessage())->toContain('1 Oct 2026')->and(($this->status)())->toBe('soft_locked');
    });

    travelTo(CarbonImmutable::parse('2026-09-30 18:00')); // 1 October 00:00 in Dhaka
    asTenant($this->ctx['tenant_id'], function (): void {
        ($this->periods)()->lock($this->september, $this->financeManager);
        $audit = DB::table('audit_events')->where('object_id', $this->september)->where('action', 'period.locked')->first(['reason', 'after']);
        expect(($this->status)())->toBe('locked')->and($audit?->reason)->toBeNull()->and(json_decode((string) $audit?->after, true))->toBe(['status' => 'locked']);
    });
});

it('lets a CFO lock before the month has ended only with a written reason, audited as an early lock', function (): void {
    travelTo(CarbonImmutable::parse('2026-09-30 10:00'));
    asTenant($this->ctx['tenant_id'], function (): void {
        ($this->periods)()->softLock($this->september, $this->cfo);
        expect(thrownBy(fn () => ($this->periods)()->lock($this->september, $this->cfo), PeriodTransitionException::class)->reasonCode)->toBe('EARLY_LOCK_REASON_REQUIRED')
            ->and(thrownBy(fn () => ($this->periods)()->lock($this->september, $this->cfo, '   '), PeriodTransitionException::class)->reasonCode)->toBe('EARLY_LOCK_REASON_REQUIRED')
            ->and(($this->status)())->toBe('soft_locked');

        ($this->periods)()->lock($this->september, $this->cfo, 'Board pack goes out tonight; no more September postings expected');
        $audit = DB::table('audit_events')->where('object_id', $this->september)->where('action', 'period.locked')->first(['reason', 'after', 'permission', 'actor_user_id']);
        expect(($this->status)())->toBe('locked')
            ->and($audit?->reason)->toBe('Board pack goes out tonight; no more September postings expected')
            ->and(json_decode((string) $audit?->after, true))->toBe(['status' => 'locked', 'early_lock' => true, 'period_ends' => '2026-09-30'])
            ->and($audit?->permission)->toBe('periods.lock')->and($audit?->actor_user_id)->toBe($this->cfo);
    });
});

it('shows the lock disabled with the explanation before month end, and asks a CFO for the reason', function (): void {
    travelTo(CarbonImmutable::parse('2026-09-30 10:00'));
    $world = seedInsuranceWorld($this->ctx, 'monthly');
    $runId = asTenant($this->ctx['tenant_id'], function () use ($world): string {
        $close = app(PeriodCloseService::class);
        $runId = $close->start($this->september, $world['admin']);
        foreach (DB::table('period_close_tasks')->where('close_run_id', $runId)->where('code', '<>', 'period_lock')->orderBy('order_no')->get(['id', 'code']) as $task) {
            $close->execute((string) $task->id, $world['admin'], $task->code === 'accruals' ? 'None' : null);
        }

        return $runId;
    });
    $lockTask = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('period_close_tasks')->where('close_run_id', $runId)->where('code', 'period_lock')->value('id'));
    $user = fn (string $id): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail($id));

    actingAs($user($this->financeManager))->get("/close/runs/{$runId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('close/Run')
        ->where('lock.ready', false)->where('lock.early', false)
        ->where('lock.reason', 'September 2026 can be locked once it has ended, from 1 Oct 2026. A CFO can lock it earlier with a written reason.'));
    actingAs($user($this->cfo))->get("/close/runs/{$runId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('close/Run')
        ->where('lock.ready', true)->where('lock.early', true)
        ->where('lock.reason', 'September 2026 has not ended yet (it ends on 30 Sep 2026). As CFO you can lock it now with a written reason.'));

    actingAs($user($this->financeManager))->from("/close/runs/{$runId}")->post("/close/tasks/{$lockTask}/execute", ['note' => 'please'], $this->headers)
        ->assertSessionHasErrors(['reason' => 'PERIOD_NOT_ENDED']);
    actingAs($user($this->cfo))->from("/close/runs/{$runId}")->post("/close/tasks/{$lockTask}/execute", [], $this->headers)
        ->assertSessionHasErrors(['reason' => 'EARLY_LOCK_REASON_REQUIRED']);
    expect(($this->status)())->toBe('soft_locked');
    actingAs($user($this->cfo))->from("/close/runs/{$runId}")->post("/close/tasks/{$lockTask}/execute", ['note' => 'Auditors start on 1 October'], $this->headers)
        ->assertSessionHasNoErrors();
    expect(($this->status)())->toBe('locked')
        ->and(asTenant($this->ctx['tenant_id'], fn (): mixed => DB::table('audit_events')->where('object_id', $this->september)->where('action', 'period.locked')->value('reason')))->toBe('Auditors start on 1 October');
});

it('blocks the trial balance task before the last day with the reason, and runs it on the last day', function (): void {
    travelTo(CarbonImmutable::parse('2026-09-14 10:00'));
    $world = seedInsuranceWorld($this->ctx, 'monthly');
    asTenant($this->ctx['tenant_id'], function () use ($world): void {
        $close = app(PeriodCloseService::class);
        $runId = $close->start($this->september, $world['admin']);
        foreach (DB::table('period_close_tasks')->where('close_run_id', $runId)->whereNotIn('code', ['trial_balance', 'financial_statements', 'sign_off', 'period_lock'])->get(['id', 'code']) as $task) {
            $close->execute((string) $task->id, $world['admin'], $task->code === 'accruals' ? 'None' : null);
        }
        $trialBalance = (string) DB::table('period_close_tasks')->where('close_run_id', $runId)->where('code', 'trial_balance')->value('id');

        expect($close->execute($trialBalance, $world['admin']))->toBe('blocked')
            ->and(json_decode((string) DB::table('period_close_tasks')->where('id', $trialBalance)->value('result'), true)['details']['reason'])->toBe('PERIOD_LAST_DAY_NOT_REACHED')
            ->and(($this->status)())->toBe('open');

        travelTo(CarbonImmutable::parse('2026-09-30 01:00'));
        expect($close->execute($trialBalance, $world['admin']))->toBe('done')->and(($this->status)())->toBe('soft_locked');
    });
});
