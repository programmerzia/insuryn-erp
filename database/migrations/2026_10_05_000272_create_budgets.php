<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Design addendum v2 §B.8.1 budgets (MVP): versions per fiscal year (draft → submitted → approved → superseded) with lines per account × branch × month.
 * INVARIANT: an approved or superseded version's lines never change (trigger); one approved version per entity, year and code. Budgets post nothing (PD-18).
 */
return new class extends Migration
{
    private const GRANTS = ['accountant' => ['budget.prepare'], 'finance_manager' => ['budget.approve'], 'cfo' => ['budget.approve']];

    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->smallInteger('fiscal_year');
            $t->string('code', 32);
            $t->string('name');
            $t->smallInteger('version');
            $t->string('status', 20);
            $t->uuid('prepared_by');
            $t->timestampTz('submitted_at')->nullable();
            $t->uuid('approved_by')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->string('note', 500)->nullable();
            $t->timestampsTz();
            $t->unique(['entity_id', 'fiscal_year', 'code', 'version']);
        });
        DB::statement("ALTER TABLE budgets ADD CONSTRAINT budgets_status_valid CHECK (status IN ('draft','submitted','approved','superseded') AND (status NOT IN ('approved','superseded') OR (approved_by IS NOT NULL AND approved_by <> prepared_by)))");
        DB::statement("CREATE UNIQUE INDEX budgets_one_approved ON budgets (entity_id, fiscal_year, code) WHERE status = 'approved'");

        Schema::create('budget_lines', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('budget_id');
            $t->uuid('account_id');
            $t->uuid('branch_id');
            $t->smallInteger('period_no');
            $t->bigInteger('amount_minor');
            $t->unique(['budget_id', 'account_id', 'branch_id', 'period_no']);
            $t->foreign('budget_id')->references('id')->on('budgets');
        });
        DB::statement('ALTER TABLE budget_lines ADD CONSTRAINT budget_lines_valid CHECK (period_no BETWEEN 1 AND 12 AND amount_minor >= 0)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_approved_budget_lines() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s text;
BEGIN
  SELECT status INTO s FROM budgets WHERE id = COALESCE(NEW.budget_id, OLD.budget_id);
  IF s IN ('approved', 'superseded') THEN
    RAISE EXCEPTION 'BUDGET_APPROVED: an approved budget version cannot change; make a new version' USING ERRCODE = 'check_violation';
  END IF;
  RETURN COALESCE(NEW, OLD);
END $$;
CREATE TRIGGER budget_lines_immutable_when_approved BEFORE INSERT OR UPDATE OR DELETE ON budget_lines
  FOR EACH ROW EXECUTE FUNCTION protect_approved_budget_lines();
SQL);

        RowLevelSecurity::enable('budgets');
        RowLevelSecurity::enable('budget_lines');

        // ASSUMPTION A-277: the accountant prepares, the finance manager (and CFO) approve (CQ-I9 open). Existing tenants' template roles get them; the SoD rule is seeded per tenant.
        DB::table('permissions')->insertOrIgnore([['code' => 'budget.prepare'], ['code' => 'budget.approve']]);
        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            foreach (self::GRANTS as $roleCode => $permissions) {
                $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
                foreach ($roleId === null ? [] : $permissions as $permission) {
                    DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => (string) $roleId, 'permission_code' => $permission]);
                }
            }
            if (! DB::table('sod_rules')->where('permission_a', 'budget.prepare')->where('permission_b', 'budget.approve')->exists()) {
                DB::table('sod_rules')->insert(['id' => (string) Illuminate\Support\Str::uuid7(), 'tenant_id' => $tenantId, 'code' => 'SOD-BUDGET', 'permission_a' => 'budget.prepare',
                    'permission_b' => 'budget.approve', 'mode' => 'block', 'applies_to' => 'object']);
            }
        });
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(function (): void {
            DB::table('role_permissions')->whereIn('permission_code', ['budget.prepare', 'budget.approve'])->delete();
            DB::table('sod_rules')->where('permission_a', 'budget.prepare')->delete();
        });
        DB::table('permissions')->whereIn('code', ['budget.prepare', 'budget.approve'])->delete();
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
        DB::unprepared('DROP FUNCTION IF EXISTS protect_approved_budget_lines()');
    }
};
