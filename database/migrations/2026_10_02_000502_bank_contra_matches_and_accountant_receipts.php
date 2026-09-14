<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gap fix GA-27 (bank reconciliation actions):
 * - `bank_matches` can offset ledger lines against each other: a group of unmatched ledger lines of the bank account that nets to zero (a bounced cheque's
 *   receipt and its reversal) is matched as one `contra_group_id`, with no statement line. A match row has a statement line or a contra group, never both.
 * - The accountant records an unknown credit on the statement as a receipt held in suspense, from the bank screen: `receipt.create` for the accountant
 *   template role of existing tenants (ASSUMPTION A-219), as RoleTemplates now gives new tenants. Roles already edited keep everything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bank_matches ALTER COLUMN statement_line_id DROP NOT NULL');
        DB::statement('ALTER TABLE bank_matches ADD COLUMN contra_group_id uuid NULL');
        DB::statement('CREATE INDEX bank_matches_contra_group_id_index ON bank_matches (contra_group_id)');
        DB::statement('ALTER TABLE bank_matches DROP CONSTRAINT bank_matches_method_valid');
        DB::statement("ALTER TABLE bank_matches ADD CONSTRAINT bank_matches_method_valid CHECK (method IN ('auto','manual','contra'))");
        DB::statement("ALTER TABLE bank_matches ADD CONSTRAINT bank_matches_target_valid CHECK ((statement_line_id IS NOT NULL AND contra_group_id IS NULL AND method <> 'contra') OR (statement_line_id IS NULL AND contra_group_id IS NOT NULL AND method = 'contra'))");

        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            $roleId = DB::table('roles')->where('code', 'accountant')->value('id');
            if ($roleId !== null) {
                DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => (string) $roleId, 'permission_code' => 'receipt.create']);
            }
        });
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(function (): void {
            DB::table('role_permissions')->where('permission_code', 'receipt.create')->whereIn('role_id', DB::table('roles')->where('code', 'accountant')->select('id'))->delete();
        });
        DB::table('bank_matches')->whereNotNull('contra_group_id')->delete();
        DB::statement('ALTER TABLE bank_matches DROP CONSTRAINT IF EXISTS bank_matches_target_valid');
        DB::statement('ALTER TABLE bank_matches DROP CONSTRAINT bank_matches_method_valid');
        DB::statement("ALTER TABLE bank_matches ADD CONSTRAINT bank_matches_method_valid CHECK (method IN ('auto','manual'))");
        DB::statement('DROP INDEX IF EXISTS bank_matches_contra_group_id_index');
        DB::statement('ALTER TABLE bank_matches DROP COLUMN contra_group_id');
        DB::statement('ALTER TABLE bank_matches ALTER COLUMN statement_line_id SET NOT NULL');
    }
};
