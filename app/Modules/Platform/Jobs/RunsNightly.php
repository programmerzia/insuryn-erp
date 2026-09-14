<?php

declare(strict_types=1);

namespace App\Modules\Platform\Jobs;

use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Gap fix GA-05: a nightly job loops over every tenant (D-07) when the schedule starts it, or runs for one tenant when finance starts it with
 * "Run now" (`forTenant`); each tenant's run is recorded in the JobRunLog under the job's key.
 */
trait RunsNightly
{
    public ?string $onlyTenantId = null;

    public ?string $triggeredBy = null;

    /** Runs the job for this tenant only, started by this user. */
    public function forTenant(string $tenantId, ?string $triggeredBy): static
    {
        $this->onlyTenantId = $tenantId;
        $this->triggeredBy = $triggeredBy;

        return $this;
    }

    /** @param callable(string): mixed $work runs inside each tenant's context */
    protected function eachTenant(callable $work): void
    {
        $tenantIds = $this->onlyTenantId !== null ? [$this->onlyTenantId] : DB::table('tenants')->orderBy('id')->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
        foreach ($tenantIds as $tenantId) {
            TenantContext::run($tenantId, fn (): mixed => $work($tenantId));
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     * @return T
     */
    protected function logged(string $job, callable $work, ?string $entityId = null): mixed
    {
        return app(JobRunLog::class)->record($job, $work, $entityId, $this->triggeredBy);
    }
}
