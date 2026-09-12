<?php

declare(strict_types=1);

namespace App\Modules\Platform\Jobs;

use App\Modules\Platform\Tenancy\TenantContext;
use RuntimeException;

/**
 * Every job carries tenant_id and re-establishes context before running (design §8.6.2).
 * Use: `public function handle(): void { $this->withTenant(fn () => $this->run()); }`
 */
trait TenantAware
{
    public string $tenantId;

    protected function withTenant(callable $fn): mixed
    {
        if (! isset($this->tenantId) || $this->tenantId === '') {
            throw new RuntimeException(static::class.' dispatched without tenant_id.');
        }
        return TenantContext::run($this->tenantId, $fn);
    }
}
