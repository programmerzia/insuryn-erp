<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\Close\PeriodCloseService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Gap fix GA-09: every action that posts shows its journal first. Rejecting a claim releases its reserve and the close tasks that post (premium earning,
 * the year-end close) preview the journal they will post; nothing is written by the preview.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-10-01 10:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->preview = [...$this->headers, 'X-Journal-Preview' => '1', 'Accept' => 'application/json'];
    $this->admin = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $this->world['admin']));
    $this->policy = asTenant($this->ctx['tenant_id'], function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return $policy->id;
    });
    $this->counts = fn (): array => asTenant($this->ctx['tenant_id'], fn (): array => ['journals' => DB::table('journals')->count(), 'events' => DB::table('accounting_events')->count(),
        'claims' => DB::table('claims')->pluck('status')->all(), 'tasks' => DB::table('period_close_tasks')->pluck('status', 'code')->all()]);
});

it('previews the reserve release a claim rejection posts', function (): void {
    $claimId = asTenant($this->ctx['tenant_id'], function (): string {
        $claims = app(ClaimService::class);
        $claim = $claims->register($this->policy, CarbonImmutable::parse('2026-09-20'), 'Collision', $this->world['admin'], CarbonImmutable::parse('2026-09-21'));
        $claims->reserve($claim->id, 20_000_000, 'Survey', $this->world['admin'], CarbonImmutable::parse('2026-09-22'));

        return $claim->id;
    });
    $before = ($this->counts)();

    actingAs($this->admin)->postJson("/claims/{$claimId}/reject", ['reason' => 'Not covered', 'on' => '2026-09-30'], $this->preview)->assertOk()
        ->assertJsonPath('posts', true)->assertJsonPath('journals.0.event', 'CLAIM_RESERVE_ADJUSTED')
        ->assertJsonPath('journals.0.lines.1', ['account' => '2200', 'name' => 'Outstanding Claims Reserve', 'debit' => '200,000.00', 'credit' => null, 'role' => 'claims_outstanding'])
        ->assertJsonPath('journals.0.totals', ['debit' => '200,000.00', 'credit' => '200,000.00']);
    expect(($this->counts)())->toBe($before);
});

it('previews the earning and the year-end close journals of posting close tasks, and flags them on the checklist', function (): void {
    travelTo(CarbonImmutable::parse('2027-07-02 10:00'));
    $run = function (string $starts): string {
        $period = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('fiscal_periods')->where('starts', $starts)->value('id'));

        return asTenant($this->ctx['tenant_id'], fn (): string => app(PeriodCloseService::class)->start($period, $this->world['admin']));
    };
    $task = fn (string $runId, string $code): string => asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('period_close_tasks')->where('close_run_id', $runId)->where('code', $code)->value('id'));

    $september = $run('2026-09-01');
    actingAs($this->admin)->get("/close/runs/{$september}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('close/Run')
        ->where('tasks.0.code', 'premium_earning')->where('tasks.0.posts', true)->where('tasks.1.posts', false));
    $before = ($this->counts)();
    actingAs($this->admin)->postJson("/close/tasks/{$task($september, 'premium_earning')}/execute", ['note' => ''], $this->preview)->assertOk()
        ->assertJsonPath('posts', true)->assertJsonPath('journals.0.event', 'PREMIUM_EARNED')->assertJsonPath('journals.0.date', '2026-09-30');
    expect(($this->counts)())->toBe($before);

    // June 2027 closes FY 2026-27: the earning posted, its year-end close task previews income to retained earnings.
    $june = $run('2027-06-01');
    asTenant($this->ctx['tenant_id'], fn () => DB::table('period_close_tasks')->where('close_run_id', $june)->whereNotIn('code', ['year_end_close', 'trial_balance', 'financial_statements', 'sign_off', 'period_lock'])
        ->update(['status' => 'done']));
    actingAs($this->admin)->post("/close/tasks/{$task($september, 'premium_earning')}/execute", ['note' => ''], $this->headers)->assertSessionHasNoErrors();
    $income = asTenant($this->ctx['tenant_id'], fn (): int => (int) DB::table('premium_earning_ledger')->sum('earned_minor'));
    actingAs($this->admin)->get("/close/runs/{$june}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('tasks', fn (mixed $tasks): bool => in_array(['year_end_close', true], array_map(fn (array $t): array => [$t['code'], $t['posts']], (array) json_decode((string) json_encode($tasks), true)), true)));
    $before = ($this->counts)();
    $money = number_format($income / 100, 2);
    actingAs($this->admin)->postJson("/close/tasks/{$task($june, 'year_end_close')}/execute", ['note' => ''], $this->preview)->assertOk()
        ->assertJsonPath('posts', true)->assertJsonPath('journals.0.event', 'YEAR_END_CLOSE')->assertJsonPath('journals.0.date', '2027-06-30')
        ->assertJsonPath('journals.0.lines', [['account' => '4100', 'name' => 'Premium Income', 'debit' => $money, 'credit' => null, 'role' => 'premium_income'],
            ['account' => '3100', 'name' => 'Retained Earnings', 'debit' => null, 'credit' => $money, 'role' => 'retained_earnings']]);
    expect(($this->counts)())->toBe($before)->and($income)->toBeGreaterThan(0);
});
