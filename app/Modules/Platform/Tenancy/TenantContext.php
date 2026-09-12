<?php

declare(strict_types=1);

namespace App\Modules\Platform\Tenancy;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Single source of the current tenant. Sets the Postgres session variable that RLS policies read
 * (design §8.6). Business queries outside a tenant context return zero rows by construction.
 */
final class TenantContext
{
    private static ?string $tenantId = null;

    public static function set(string $tenantId): void
    {
        self::$tenantId = $tenantId;
        // SET (not SET LOCAL) so it survives across statements on this connection for the request/job.
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
    }

    public static function clear(): void
    {
        self::$tenantId = null;
        DB::statement("SELECT set_config('app.tenant_id', '', false)");
    }

    public static function id(): string
    {
        return self::$tenantId ?? throw new RuntimeException('No tenant context. Wrap the operation in TenantContext::run().');
    }

    public static function has(): bool
    {
        return self::$tenantId !== null;
    }

    /** @template T @param callable():T $fn @return T */
    public static function run(string $tenantId, callable $fn): mixed
    {
        $previous = self::$tenantId;
        self::set($tenantId);
        try {
            return $fn();
        } finally {
            $previous === null ? self::clear() : self::set($previous);
        }
    }
}
