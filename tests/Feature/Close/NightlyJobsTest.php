<?php

declare(strict_types=1);

use App\Http\Close\NightlyJobs;
use App\Models\User;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Policy\Infrastructure\Jobs\DunningJob;
use App\Modules\Insurance\Policy\Infrastructure\Jobs\PremiumEarningJob;
use App\Modules\Platform\Jobs\JobRunLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Gap fix GA-05: the nightly lifecycle is visible and running — every scheduled job records each tenant's run (JobRunLog), the close screen says when each
 * last ran and what it did, finance runs one now for its own company only, and `composer dev` starts the scheduler and a worker on every queue.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-14 10:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->user = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    // A policy issued in August whose cover starts on 1 September: still Issued until the nightly job runs.
    $this->policy = asTenant($this->ctx['tenant_id'], function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-08-25'), $this->world['admin']);

        return $policy->id;
    });
});

it('records each tenant\'s scheduled run with what it did, per entity for payment reminders', function (): void {
    app()->call([new PremiumEarningJob(), 'handle']);
    app()->call([new DunningJob(), 'handle']);

    asTenant($this->ctx['tenant_id'], function (): void {
        expect(DB::table('policies')->where('id', $this->policy)->value('status'))->toBe('active');
        $runs = DB::table('job_runs')->orderBy('job')->get(['job', 'entity_id', 'status', 'triggered_by', 'summary', 'finished_at']);
        expect($runs->pluck('job')->all())->toBe(['dunning', 'policy_lifecycle'])
            ->and($runs->pluck('status')->unique()->all())->toBe(['succeeded'])
            ->and($runs->whereNotNull('triggered_by')->count())->toBe(0)
            ->and($runs->whereNull('finished_at')->count())->toBe(0)
            ->and($runs->pluck('entity_id', 'job')->all()['dunning'])->toBe($this->ctx['entity_id'])
            ->and(json_decode((string) $runs->pluck('summary', 'job')->all()['policy_lifecycle'], true))->toBe(['activated' => 1, 'expired' => 0, 'periods_earned' => 2, 'policies_earned' => 0]);

        $latest = app(JobRunLog::class)->latest();
        expect(array_keys($latest))->toBe(['dunning', 'policy_lifecycle'])->and($latest['policy_lifecycle']['summary']['activated'])->toBe(1);
    });
});

it('records a failed run with its reason and lets the failure through', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        expect(fn () => app(JobRunLog::class)->record('renewals', fn () => throw new RuntimeException('The expiry register could not be read.')))->toThrow(RuntimeException::class);
        expect((array) DB::table('job_runs')->first(['job', 'status', 'error']))->toBe(['job' => 'renewals', 'status' => 'failed', 'error' => 'The expiry register could not be read.'])
            ->and(DB::table('job_runs')->whereNull('finished_at')->count())->toBe(0);
        expect(app(JobRunLog::class)->latest()['renewals']['status'])->toBe('failed');
    });
});

it('shows each nightly job on the close screen with when it last ran, and Run now only for finance', function (): void {
    $finance = ($this->user)(['periods.soft_lock', 'periods.lock']);
    $auditor = ($this->user)(['reports.financial']);

    actingAs($auditor)->get('/close', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('close/Index')
        ->where('nightly.can_run', false)->where('nightly.zone', 'Asia/Dhaka')->has('nightly.jobs', 7)
        ->where('nightly.jobs.3.key', 'policy_lifecycle')->where('nightly.jobs.3.at', '01:00')->where('nightly.jobs.3.last', null));

    app()->call([new PremiumEarningJob(), 'handle']); // 14 Sep 2026 10:00 UTC = 16:00 in Dhaka
    actingAs($finance)->get('/close', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('nightly.can_run', true)
        ->where('nightly.jobs.3.last.status', 'succeeded')->where('nightly.jobs.3.last.when', '14 Sep 2026, 16:00')->where('nightly.jobs.3.last.by', null)
        ->where('nightly.jobs.3.last.summary.activated', 1)->where('nightly.jobs.4.last', null));
});

it('runs a job now for this company only, as the signed-in finance user, audited', function (): void {
    $other = seedDemoTenant('other');
    $otherWorld = seedInsuranceWorld($other, 'monthly');
    $otherPolicy = asTenant($other['tenant_id'], function () use ($other, $otherWorld): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($other['entity_id'], $other['branch_id'], $otherWorld['product_id'],
            $otherWorld['policyholder_id'], null, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $otherWorld['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-08-25'), $otherWorld['admin']);

        return $policy->id;
    });
    $finance = ($this->user)(['periods.soft_lock']);

    actingAs(($this->user)(['reports.financial', 'periods.lock']))->post('/close/jobs/policy_lifecycle/run', [], $this->headers)->assertSessionHasErrors('form');
    asTenant($this->ctx['tenant_id'], fn () => expect(DB::table('job_runs')->count())->toBe(0));

    actingAs($finance)->post('/close/jobs/policy_lifecycle/run', [], $this->headers)->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Ran now: 1 activated, 0 expired, 2 periods earned, 0 policies earned.');
    actingAs($finance)->post('/close/jobs/no_such_job/run', [], $this->headers)->assertSessionHasErrors('form');

    asTenant($this->ctx['tenant_id'], function () use ($finance): void {
        expect(DB::table('policies')->where('id', $this->policy)->value('status'))->toBe('active')
            ->and(DB::table('job_runs')->get(['job', 'triggered_by'])->map(fn (object $r): array => (array) $r)->all())->toBe([['job' => 'policy_lifecycle', 'triggered_by' => $finance->id]]);
        $audit = DB::table('audit_events')->where('action', 'job.run_now')->first(['actor_user_id', 'permission', 'after']);
        expect($audit?->actor_user_id)->toBe($finance->id)->and($audit?->permission)->toBe(NightlyJobs::PERMISSION)
            ->and(json_decode((string) $audit?->after, true)['job'])->toBe('policy_lifecycle');
    });
    asTenant($other['tenant_id'], fn () => expect(DB::table('policies')->where('id', $otherPolicy)->value('status'))->toBe('issued')
        ->and(DB::table('job_runs')->count())->toBe(0));
});

it('schedules every job the close screen lists, and composer dev starts the scheduler and a worker on every queue', function (): void {
    $scheduled = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->description)->all();
    foreach (NightlyJobs::keys() as $key) {
        expect($scheduled)->toContain(NightlyJobs::jobClass($key));
    }

    $dev = collect(DevCommands::commands())->keyBy('name')->all();
    expect($dev)->toHaveKeys(['worker', 'scheduler', 'server'])->and(array_keys($dev))->not->toContain('horizon', 'queue')
        ->and($dev['scheduler']['command'])->toBe('php artisan schedule:work')
        ->and($dev['worker']['command'])->toContain('--queue=posting,batch,recon,default');
});
