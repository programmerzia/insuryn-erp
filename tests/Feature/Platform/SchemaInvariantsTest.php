<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * CONTEXT.md non-negotiable #6 (D-08: no exceptions): every business table carries a NOT NULL
 * tenant_id and row-level security that is enabled, forced and governed by the tenant policy.
 * Only global catalogues and framework infrastructure are exempt, each for a stated reason.
 */
const GLOBAL_TABLES = [
    'migrations',            // framework: schema history
    'tenants',               // platform root; resolving a tenant must not need a tenant (§8.6.5)
    'permissions',           // global permission catalogue (§7.1)
    'account_roles',         // global semantic account roles (§3.4)
    'product_classes',       // global insurance class catalogue (Phase 3 design §1, D-18)
    'password_reset_tokens', // framework auth infrastructure
    'sessions',              // framework session store
    'cache', 'cache_locks',  // framework cache store
    'jobs', 'job_batches', 'failed_jobs', // framework queue infrastructure; jobs carry tenant_id in payload (§8.6.2)
];

it('protects every business table with tenant_id and forced row-level security', function (): void {
    $tables = DB::select(<<<'SQL'
        select c.relname as name,
               c.relrowsecurity as rls_enabled,
               c.relforcerowsecurity as rls_forced,
               exists(select 1 from pg_policy p where p.polrelid = c.oid and p.polname = 'tenant_isolation') as has_policy,
               exists(select 1 from information_schema.columns col
                      where col.table_schema = 'public' and col.table_name = c.relname
                        and col.column_name = 'tenant_id' and col.is_nullable = 'NO') as has_tenant_id
        from pg_class c join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public' and c.relkind = 'r'
        order by c.relname
        SQL);

    $violations = [];
    foreach ($tables as $table) {
        /** @var object{name: string, rls_enabled: bool, rls_forced: bool, has_policy: bool, has_tenant_id: bool} $table */
        if (in_array($table->name, GLOBAL_TABLES, true)) {
            continue;
        }
        $missing = array_keys(array_filter([
            'tenant_id NOT NULL' => ! $table->has_tenant_id,
            'RLS enabled' => ! $table->rls_enabled,
            'RLS forced' => ! $table->rls_forced,
            'tenant_isolation policy' => ! $table->has_policy,
        ]));
        if ($missing !== []) {
            $violations[] = $table->name.': '.implode(', ', $missing);
        }
    }

    expect($tables)->not->toBeEmpty()
        ->and($violations)->toBe([]);
});
