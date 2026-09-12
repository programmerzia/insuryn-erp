<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-08: CONTEXT.md non-negotiable #6 has no exceptions. Child tables get tenant_id backfilled from
 * their parent, NOT NULL, and the same RLS policy as every other tenant table.
 */
return new class extends Migration
{
    /** child table => [parent table, foreign key column] */
    private const CHILD_TABLES = [
        'role_permissions' => ['roles', 'role_id'],
        'user_roles' => ['users', 'user_id'],
        'approval_decisions' => ['approvals', 'approval_id'],
        'reconciliation_exceptions' => ['reconciliation_runs', 'run_id'],
        'period_close_tasks' => ['period_close_runs', 'close_run_id'],
    ];

    public function up(): void
    {
        foreach (self::CHILD_TABLES as $child => [$parent, $foreignKey]) {
            Schema::table($child, fn (Blueprint $t) => $t->uuid('tenant_id')->nullable()->index());

            // Parents are RLS-protected, so the backfill runs once per tenant in that tenant's context.
            RowLevelSecurity::forEachTenant(fn () => DB::update(
                "update {$child} c set tenant_id = p.tenant_id from {$parent} p where p.id = c.{$foreignKey} and c.tenant_id is null",
            ));

            DB::statement("ALTER TABLE {$child} ALTER COLUMN tenant_id SET NOT NULL");
            RowLevelSecurity::enable($child);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::CHILD_TABLES) as $child) {
            RowLevelSecurity::disable($child);
            Schema::table($child, fn (Blueprint $t) => $t->dropColumn('tenant_id'));
        }
    }
};
