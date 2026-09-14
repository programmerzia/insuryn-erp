<?php

declare(strict_types=1);

namespace App\Modules\Platform\Jobs;

use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Gap fix GA-05: when each nightly job last ran for a tenant (and entity, for jobs that work per entity), who started it (null for the schedule),
 * whether it finished and what it did. One row per run in `job_runs` (tenant table under row-level security), written in the current tenant.
 * The row is committed before the work starts and updated when it ends, so a run that fails or never finishes still shows.
 */
final class JobRunLog
{
    /**
     * Runs $work inside the current tenant and records the run. A failure is recorded with its message and rethrown.
     *
     * @template T
     *
     * @param callable(): T $work
     * @return T
     */
    public function record(string $job, callable $work, ?string $entityId = null, ?string $triggeredBy = null): mixed
    {
        $id = (string) Str::uuid7();
        DB::table('job_runs')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'job' => $job, 'entity_id' => $entityId, 'status' => 'running',
            'triggered_by' => $triggeredBy, 'started_at' => CarbonImmutable::now()]);
        try {
            $result = $work();
        } catch (Throwable $failure) {
            DB::table('job_runs')->where('id', $id)->update(['status' => 'failed', 'finished_at' => CarbonImmutable::now(),
                'error' => mb_substr($failure->getMessage(), 0, 2000)]);

            throw $failure;
        }
        DB::table('job_runs')->where('id', $id)->update(['status' => 'succeeded', 'finished_at' => CarbonImmutable::now(),
            'summary' => json_encode(self::summary($result), JSON_THROW_ON_ERROR)]);

        return $result;
    }

    /**
     * The latest run of each job in the current tenant.
     *
     * @return array<string, array{status: string, started_at: string, finished_at: string|null, triggered_by: string|null, error: string|null, summary: array<string, int|string>}>
     */
    public function latest(): array
    {
        $rows = DB::table('job_runs as r')->leftJoin('users as u', 'u.id', '=', 'r.triggered_by')
            ->selectRaw('distinct on (r.job) r.job, r.status, r.started_at, r.finished_at, r.error, r.summary, u.name as triggered_by')
            ->orderBy('r.job')->orderByDesc('r.started_at')->orderByDesc('r.id')->get();
        $latest = [];
        foreach ($rows as $row) {
            /** @var array<string, int|string>|null $summary */
            $summary = $row->summary === null ? null : json_decode((string) $row->summary, true);
            $latest[(string) $row->job] = ['status' => (string) $row->status, 'started_at' => (string) $row->started_at,
                'finished_at' => $row->finished_at === null ? null : (string) $row->finished_at, 'triggered_by' => $row->triggered_by === null ? null : (string) $row->triggered_by,
                'error' => $row->error === null ? null : (string) $row->error, 'summary' => $summary ?? []];
        }

        return $latest;
    }

    /** @return array<string, int|string> scalar counts and names from the job's result (an int is "count") */
    private static function summary(mixed $result): array
    {
        if (is_int($result)) {
            return ['count' => $result];
        }
        $values = is_object($result) ? get_object_vars($result) : (is_array($result) ? $result : []);

        return array_filter($values, fn (mixed $value, int|string $key): bool => is_string($key) && (is_int($value) || is_string($value)), ARRAY_FILTER_USE_BOTH);
    }
}
