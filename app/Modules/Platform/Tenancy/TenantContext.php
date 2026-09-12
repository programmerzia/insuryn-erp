<?php

declare(strict_types=1);

namespace App\Modules\Platform\Tenancy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Single source of the current tenant. Sets the Postgres session variable that RLS policies read
 * (design §8.6). Business queries outside a tenant context return zero rows by construction.
 *
 * DEVIATION (Phase 0): session-level set_config instead of §8.6.1 SET LOCAL in a per-request
 * transaction. Safe with one server connection per PHP process (php-fpm, queue workers, session-mode
 * pooling); NOT with transaction-mode pooling (e.g. PgBouncer transaction mode). A reconnect gets the
 * tenant re-applied by reapplyTo().
 */
final class TenantContext
{
    private static ?string $tenantId = null;

    public static function set(string $tenantId): void
    {
        self::$tenantId = $tenantId;
        self::applyTo(DB::connection(), $tenantId);
    }

    public static function clear(): void
    {
        self::$tenantId = null;
        self::applyTo(DB::connection(), '');
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

    /** A new database session starts without the tenant variable: restore it for the active tenant. */
    public static function reapplyTo(ConnectionInterface $connection): void
    {
        if (self::$tenantId !== null) {
            self::applyTo($connection, self::$tenantId);
        }
    }

    private static function applyTo(ConnectionInterface $connection, string $tenantId): void
    {
        // Session scope (is_local = false) so it survives across statements on this connection for the request/job.
        $connection->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
    }
}
