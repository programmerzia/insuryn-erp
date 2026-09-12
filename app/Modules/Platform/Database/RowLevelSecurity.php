<?php

declare(strict_types=1);

namespace App\Modules\Platform\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Tenant row-level security for a table (design §8.6.6, CONTEXT.md non-negotiable #6). Migrations call
 * this for every table carrying tenant_id; SchemaInvariantsTest fails if one is missed.
 */
final class RowLevelSecurity
{
    /** @param literal-string $table */
    public static function enable(string $table): void
    {
        self::assertIdentifier($table);

        DB::unprepared(<<<SQL
            ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
            ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON {$table};
            CREATE POLICY tenant_isolation ON {$table}
              USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
              WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
            GRANT SELECT, INSERT, UPDATE, DELETE ON {$table} TO erp_app;
            SQL);
    }

    /** @param literal-string $table */
    public static function disable(string $table): void
    {
        self::assertIdentifier($table);

        DB::unprepared("DROP POLICY IF EXISTS tenant_isolation ON {$table}; ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY; ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;");
    }

    /**
     * Runs $statement once per tenant with that tenant's context, so data migrations respect RLS
     * without a bypass role (the same approach as the outbox relay, D-07).
     *
     * @param callable(string $tenantId): mixed $statement
     */
    public static function forEachTenant(callable $statement): void
    {
        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenantId]);
            $statement((string) $tenantId);
        }
        DB::statement("SELECT set_config('app.tenant_id', '', false)");
    }

    private static function assertIdentifier(string $table): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid table name: {$table}");
        }
    }
}
