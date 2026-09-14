<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Market gap G5: regulatory returns and technical provisions.
 * - `regulatory_returns`: one row per form and period (quarter `2026-Q3` or year `2026`) with the table as generated (snapshot), and its status draft → reviewed → filed
 *   (filing date and the regulator's reference). A filed return keeps its snapshot.
 * - `technical_provision_runs`: the quarterly IBNR run (draft → reviewed → posted), per-class results and the paid triangles it was built on (jsonb).
 * - `claims_paid_history`: paid claims from before this system (legacy data), by class, accident quarter and payment quarter, so a chain-ladder triangle has history
 *   (ASSUMPTION A-265).
 * - Account roles `claims_ibnr_expense` (Claims incurred – IBNR) and `ibnr_provision` (IBNR provision); existing tenants map them in Accounting → Account roles.
 * - Permissions `regulatory.file`, `provisions.run`, `provisions.approve`; the finance manager prepares and files, the CFO approves (ASSUMPTION A-268), never on a run
 *   they prepared (SoD object rule provisions.run ✕ provisions.approve).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'regulatory.file' => 'Mark a regulatory return filed with the regulator (filing date and reference)',
        'provisions.run' => 'Prepare and review the quarterly technical provisions run (IBNR)',
        'provisions.approve' => 'Approve and post the quarterly technical provisions run',
    ];

    private const GRANTS = ['finance_manager' => ['regulatory.file', 'provisions.run'], 'cfo' => ['regulatory.file', 'provisions.run', 'provisions.approve']];

    public function up(): void
    {
        DB::table('account_roles')->insertOrIgnore([
            ['code' => 'claims_ibnr_expense', 'description' => 'Claims incurred – IBNR (incurred but not reported)'],
            ['code' => 'ibnr_provision', 'description' => 'IBNR provision (claims incurred but not reported)'],
        ]);

        Schema::create('regulatory_returns', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->string('form_code', 64);
            $t->string('period_key', 16); // 2026-Q3 | 2026
            $t->date('period_start');
            $t->date('period_end');
            $t->string('status', 16)->default('draft');
            $t->jsonb('snapshot');
            $t->uuid('generated_by');
            $t->timestampTz('generated_at');
            $t->uuid('reviewed_by')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->date('filed_on')->nullable();
            $t->string('filing_reference', 100)->nullable();
            $t->uuid('filed_by')->nullable();
            $t->timestampTz('filed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'entity_id', 'form_code', 'period_key']);
        });
        DB::statement("ALTER TABLE regulatory_returns ADD CONSTRAINT regulatory_returns_status_valid CHECK (status IN ('draft','reviewed','filed') AND (status <> 'filed' OR (filed_on IS NOT NULL AND filing_reference IS NOT NULL)))");
        RowLevelSecurity::enable('regulatory_returns');

        Schema::create('technical_provision_runs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->string('number')->nullable();
            $t->string('quarter_key', 8); // 2026-Q3
            $t->date('quarter_start');
            $t->date('quarter_end');
            $t->char('currency', 3);
            $t->string('status', 16)->default('draft');
            $t->jsonb('methods'); // class → percentage | chain_ladder
            $t->jsonb('results'); // per class lines, triangles, premium deficiency notes
            $t->bigInteger('total_ibnr_minor');
            $t->uuid('prior_run_id')->nullable();
            $t->bigInteger('prior_ibnr_minor')->default(0);
            $t->uuid('reversed_by_run_id')->nullable();
            $t->uuid('prepared_by');
            $t->timestampTz('prepared_at');
            $t->uuid('reviewed_by')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->uuid('approved_by')->nullable();
            $t->timestampTz('posted_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'entity_id', 'quarter_key']);
        });
        DB::statement("ALTER TABLE technical_provision_runs ADD CONSTRAINT technical_provision_runs_valid CHECK (status IN ('draft','reviewed','posted') AND total_ibnr_minor >= 0)");
        RowLevelSecurity::enable('technical_provision_runs');

        Schema::create('claims_paid_history', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->string('class', 64);
            $t->date('accident_quarter_start');
            $t->date('paid_quarter_start');
            $t->bigInteger('paid_minor');
            $t->string('source', 64)->default('legacy_import');
            $t->timestampTz('created_at')->nullable();
            $t->index(['tenant_id', 'entity_id', 'class']);
        });
        DB::statement('ALTER TABLE claims_paid_history ADD CONSTRAINT claims_paid_history_valid CHECK (paid_quarter_start >= accident_quarter_start AND paid_minor >= 0)');
        RowLevelSecurity::enable('claims_paid_history');

        foreach (self::PERMISSIONS as $code => $description) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'description' => $description]);
        }
        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            foreach (self::GRANTS as $roleCode => $permissions) {
                $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
                if ($roleId === null) {
                    continue;
                }
                foreach ($permissions as $permission) {
                    DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => (string) $roleId, 'permission_code' => $permission]);
                }
            }
            if (! DB::table('sod_rules')->where('permission_a', 'provisions.run')->where('permission_b', 'provisions.approve')->exists()) {
                $code = DB::table('sod_rules')->where('code', 'SOD9')->exists() ? 'SOD-PROVISIONS' : 'SOD9';
                DB::table('sod_rules')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => $code, 'permission_a' => 'provisions.run',
                    'permission_b' => 'provisions.approve', 'mode' => 'block', 'applies_to' => 'object']);
            }
        });
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(function (): void {
            DB::table('sod_rules')->where('permission_a', 'provisions.run')->where('permission_b', 'provisions.approve')->delete();
            DB::table('role_permissions')->whereIn('permission_code', array_keys(self::PERMISSIONS))->delete();
        });
        DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->delete();
        Schema::dropIfExists('claims_paid_history');
        Schema::dropIfExists('technical_provision_runs');
        Schema::dropIfExists('regulatory_returns');
        DB::table('account_roles')->whereIn('code', ['claims_ibnr_expense', 'ibnr_provision'])->delete();
    }
};
