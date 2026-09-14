<?php

declare(strict_types=1);

namespace App\Http\Close;

use App\Modules\Accounting\Infrastructure\Jobs\ReconciliationJob;
use App\Modules\Distribution\Infrastructure\Jobs\LicenceExpiryAlertJob;
use App\Modules\Insurance\CoverNote\Infrastructure\Jobs\CoverNoteExpiryJob;
use App\Modules\Insurance\Policy\Infrastructure\Jobs\DunningJob;
use App\Modules\Insurance\Policy\Infrastructure\Jobs\PremiumEarningJob;
use App\Modules\Insurance\Quotation\Infrastructure\Jobs\QuotationExpiryJob;
use App\Modules\Insurance\Renewal\Infrastructure\Jobs\RenewalRunJob;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Jobs\JobRunLog;
use App\Modules\Platform\Tenancy\BusinessClock;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Gap fix GA-05: the nightly lifecycle made visible. The close screen lists each nightly job with when it last ran (JobRunLog) and what it did,
 * and finance runs one now for this tenant only. Composition: the jobs live in their modules; this lists them in the order the schedule runs them
 * (routes/console.php, A-152).
 *
 * ASSUMPTION A-201: "Run now" needs `periods.soft_lock` — the permission of the close's own system tasks (premium earning is close task 1), held by the
 * finance manager and CFO templates — rather than a new permission. It runs the job synchronously for the signed-in tenant and is audited `job.run_now`.
 */
final class NightlyJobs
{
    public const PERMISSION = 'periods.soft_lock';

    /** @var array<string, array{class: class-string, label: string, does: string, at: string}> in schedule order */
    private const CATALOGUE = [
        QuotationExpiryJob::KEY => ['class' => QuotationExpiryJob::class, 'label' => 'Quotation expiry', 'does' => 'Issued quotations past their validity expire.', 'at' => '00:15'],
        CoverNoteExpiryJob::KEY => ['class' => CoverNoteExpiryJob::class, 'label' => 'Cover note expiry', 'does' => 'Cover notes past their last day expire.', 'at' => '00:20'],
        RenewalRunJob::KEY => ['class' => RenewalRunJob::class, 'label' => 'Renewals', 'does' => 'Fills the expiry register, prepares renewal quotations 45 days ahead and sends renewal notices.', 'at' => '00:30'],
        PremiumEarningJob::KEY => ['class' => PremiumEarningJob::class, 'label' => 'Policy start, expiry and premium earning', 'does' => 'Issued policies whose cover has started become Active, ended cover expires, and months that have ended are earned.', 'at' => '01:00'],
        DunningJob::KEY => ['class' => DunningJob::class, 'label' => 'Payment reminders and lapse', 'does' => 'Sends reminders for overdue premium and lapses active policies unpaid past the grace period.', 'at' => '01:30'],
        LicenceExpiryAlertJob::KEY => ['class' => LicenceExpiryAlertJob::class, 'label' => 'Licence expiry alerts', 'does' => 'Warns about producer licences that are about to expire.', 'at' => '01:45'],
        ReconciliationJob::KEY => ['class' => ReconciliationJob::class, 'label' => 'Subledger reconciliation', 'does' => 'Reconciles every subledger to the general ledger for the open months.', 'at' => '02:00'],
    ];

    public function __construct(
        private readonly JobRunLog $log,
        private readonly PermissionChecker $permissions,
        private readonly BusinessClock $clock,
        private readonly Audit $audit,
    ) {}

    /** @return list<string> job keys in schedule order */
    public static function keys(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /** @return class-string */
    public static function jobClass(string $key): string
    {
        return self::CATALOGUE[$key]['class'] ?? throw new BusinessRuleViolation('JOB_UNKNOWN', "There is no nightly job {$key}.");
    }

    /**
     * What the close screen shows about the nightly jobs, for the signed-in user.
     *
     * @return array{can_run: bool, zone: string, jobs: list<array{key: string, label: string, does: string, at: string, last: array{status: string, started_at: string, finished_at: string|null, when: string, by: string|null, error: string|null, summary: array<string, int|string>}|null}>}
     */
    public function panel(string $actorUserId): array
    {
        $zone = $this->clock->timezone();
        $latest = $this->log->latest();
        $jobs = [];
        foreach (self::CATALOGUE as $key => $job) {
            $last = $latest[$key] ?? null;
            $jobs[] = ['key' => $key, 'label' => $job['label'], 'does' => $job['does'], 'at' => $job['at'],
                'last' => $last === null ? null : ['status' => $last['status'], 'started_at' => $last['started_at'], 'finished_at' => $last['finished_at'],
                    'when' => CarbonImmutable::parse($last['started_at'])->setTimezone($zone)->format('j M Y, H:i'), 'by' => $last['triggered_by'],
                    'error' => $last['error'], 'summary' => $last['summary']]];
        }

        return ['can_run' => $this->permissions->has($actorUserId, self::PERMISSION), 'zone' => $zone, 'jobs' => $jobs];
    }

    /**
     * Runs one nightly job now for the current tenant, as the schedule would, and records who started it.
     *
     * @return array<string, int|string> what the run did
     *
     * @throws BusinessRuleViolation JOB_UNKNOWN
     */
    public function runNow(string $key, string $actorUserId): array
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION);
        $class = self::jobClass($key);
        $job = new $class();
        $handle = method_exists($job, 'forTenant') ? [$job->forTenant(TenantContext::id(), $actorUserId), 'handle'] : null;
        if (! is_callable($handle)) {
            throw new BusinessRuleViolation('JOB_UNKNOWN', "Nightly job {$key} cannot be run for one tenant.");
        }
        $failure = null;
        try {
            app()->call($handle);
        } catch (Throwable $e) {
            $failure = $e;
        }
        $run = DB::table('job_runs')->where('job', $key)->where('triggered_by', $actorUserId)->orderByDesc('started_at')->orderByDesc('id')->first(['id', 'status', 'summary', 'error']);
        /** @var array<string, int|string> $summary */
        $summary = $run === null || $run->summary === null ? [] : (array) json_decode((string) $run->summary, true);
        if ($run !== null) {
            $this->audit->record('job.run_now', AuditSubject::of('job_run', (string) $run->id), null, ['job' => $key, 'status' => (string) $run->status, 'summary' => $summary],
                null, self::PERMISSION, Actor::user($actorUserId));
        }
        if ($failure !== null) {
            report($failure);

            throw new BusinessRuleViolation('JOB_FAILED', self::CATALOGUE[$key]['label'].' did not finish. The error is in the application log.');
        }

        return $summary;
    }
}
