<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Design addendum v2 §B.7 fixed assets (MVP): asset classes with their method, life and GL accounts; the register; monthly depreciation runs with one
 * row per asset and period (a rerun inserts nothing); movements between branches; disposals. Account roles for asset cost, accumulated depreciation,
 * depreciation expense and disposal gain or loss (§B.2.5); existing tenants map them in Accounting → Account roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('account_roles')->insertOrIgnore([
            ['code' => 'fixed_asset_cost', 'description' => 'Fixed assets at cost (per asset class)'],
            ['code' => 'accumulated_depreciation', 'description' => 'Accumulated depreciation on fixed assets (per asset class)'],
            ['code' => 'depreciation_expense', 'description' => 'Depreciation charged to profit and loss (per asset class)'],
            ['code' => 'asset_disposal_gain_loss', 'description' => 'Gain or loss on disposal of fixed assets'],
        ]);

        Schema::create('asset_classes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->string('code', 32);
            $t->string('name');
            $t->string('method', 20);
            $t->integer('useful_life_months')->nullable();
            $t->integer('rate_bp')->nullable();
            $t->integer('residual_bp')->default(0);
            $t->bigInteger('capitalisation_threshold_minor')->default(0);
            $t->uuid('cost_account_id');
            $t->uuid('accumulated_account_id');
            $t->uuid('expense_account_id');
            $t->uuid('disposal_account_id')->nullable();
            $t->string('status', 20)->default('active');
            $t->timestampsTz();
            $t->unique(['entity_id', 'code']);
        });
        DB::statement("ALTER TABLE asset_classes ADD CONSTRAINT asset_classes_method_valid CHECK ((method = 'straight_line' AND useful_life_months > 0) OR (method = 'reducing_balance' AND rate_bp > 0 AND rate_bp <= 10000))");
        DB::statement('ALTER TABLE asset_classes ADD CONSTRAINT asset_classes_residual_valid CHECK (residual_bp >= 0 AND residual_bp < 10000 AND capitalisation_threshold_minor >= 0)');

        Schema::create('fixed_assets', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('branch_id');
            $t->uuid('class_id')->index();
            $t->string('number', 40)->unique();
            $t->string('description');
            $t->string('serial_no')->nullable();
            $t->string('location')->nullable();
            $t->string('custodian')->nullable();
            $t->string('supplier')->nullable();
            $t->string('invoice_ref')->nullable();
            $t->date('acquired_on');
            $t->char('currency', 3);
            $t->bigInteger('cost_minor');
            $t->bigInteger('residual_minor')->default(0);
            $t->string('method', 20);
            $t->integer('useful_life_months')->nullable();
            $t->integer('rate_bp')->nullable();
            $t->string('source_type', 20);
            $t->string('paid_via', 20);
            $t->uuid('bank_account_id')->nullable();
            $t->bigInteger('opening_accumulated_minor')->default(0);
            $t->date('opening_as_of')->nullable();
            $t->string('status', 20);
            $t->date('disposed_on')->nullable();
            $t->uuid('created_by');
            $t->timestampsTz();
            $t->foreign('class_id')->references('id')->on('asset_classes');
        });
        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_status_valid CHECK (status IN ('in_service','fully_depreciated','disposed'))");
        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_source_valid CHECK ((source_type = 'manual' AND paid_via IN ('bank','payable') AND opening_accumulated_minor = 0) OR (source_type = 'opening' AND paid_via = 'opening' AND opening_as_of IS NOT NULL))");
        DB::statement('ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_amounts_valid CHECK (cost_minor > 0 AND residual_minor >= 0 AND residual_minor < cost_minor AND opening_accumulated_minor >= 0 AND opening_accumulated_minor <= cost_minor - residual_minor)');

        Schema::create('asset_depreciation_runs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('period_id');
            $t->date('period_ends');
            $t->integer('assets_count');
            $t->bigInteger('total_minor');
            $t->uuid('posted_by');
            $t->timestampTz('posted_at');
        });

        Schema::create('asset_depreciation', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('asset_id');
            $t->uuid('run_id')->index();
            $t->uuid('period_id');
            $t->date('period_ends');
            $t->uuid('branch_id');
            $t->bigInteger('amount_minor');
            $t->bigInteger('accumulated_minor');
            $t->bigInteger('nbv_minor');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['asset_id', 'period_id']); // INVARIANT §B.7: one row per asset and period; a rerun inserts nothing
            $t->foreign('asset_id')->references('id')->on('fixed_assets');
            $t->foreign('run_id')->references('id')->on('asset_depreciation_runs');
        });
        DB::statement('ALTER TABLE asset_depreciation ADD CONSTRAINT asset_depreciation_amounts_valid CHECK (amount_minor > 0 AND accumulated_minor >= amount_minor AND nbv_minor >= 0)');

        Schema::create('asset_movements', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('asset_id')->index();
            $t->date('moved_on');
            $t->uuid('from_branch_id');
            $t->uuid('to_branch_id');
            $t->string('from_location')->nullable();
            $t->string('to_location')->nullable();
            $t->string('reason', 500);
            $t->bigInteger('cost_minor');
            $t->bigInteger('accumulated_minor');
            $t->uuid('moved_by');
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('asset_id')->references('id')->on('fixed_assets');
        });
        DB::statement('ALTER TABLE asset_movements ADD CONSTRAINT asset_movements_branches_differ CHECK (from_branch_id <> to_branch_id)');

        Schema::create('asset_disposals', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('asset_id')->unique();
            $t->string('number', 40)->unique();
            $t->date('disposal_date');
            $t->string('kind', 20);
            $t->bigInteger('proceeds_minor');
            $t->uuid('bank_account_id')->nullable();
            $t->bigInteger('cost_minor');
            $t->bigInteger('accumulated_minor');
            $t->bigInteger('nbv_minor');
            $t->bigInteger('gain_loss_minor');
            $t->string('reason', 500);
            $t->uuid('disposed_by');
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('asset_id')->references('id')->on('fixed_assets');
        });
        DB::statement("ALTER TABLE asset_disposals ADD CONSTRAINT asset_disposals_valid CHECK (kind IN ('sale','write_off') AND proceeds_minor >= 0 AND (kind = 'sale' OR proceeds_minor = 0) AND (proceeds_minor = 0 OR bank_account_id IS NOT NULL) AND nbv_minor = cost_minor - accumulated_minor AND gain_loss_minor = proceeds_minor - nbv_minor)");

        foreach (['asset_classes', 'fixed_assets', 'asset_depreciation_runs', 'asset_depreciation', 'asset_movements', 'asset_disposals'] as $table) {
            RowLevelSecurity::enable($table);
        }

        // ASSUMPTION A-276: the accountant keeps the register, the finance manager and CFO post depreciation. Existing tenants' template roles get them (rerun-safe).
        DB::table('permissions')->insertOrIgnore([['code' => 'fa.manage'], ['code' => 'fa.post_depreciation']]);
        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            foreach (self::GRANTS as $roleCode => $permissions) {
                $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
                foreach ($roleId === null ? [] : $permissions as $permission) {
                    DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => (string) $roleId, 'permission_code' => $permission]);
                }
            }
        });
    }

    private const GRANTS = ['accountant' => ['fa.manage'], 'finance_manager' => ['fa.post_depreciation'], 'cfo' => ['fa.post_depreciation']];

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(fn () => DB::table('role_permissions')->whereIn('permission_code', ['fa.manage', 'fa.post_depreciation'])->delete());
        DB::table('permissions')->whereIn('code', ['fa.manage', 'fa.post_depreciation'])->delete();
        foreach (['asset_disposals', 'asset_movements', 'asset_depreciation', 'asset_depreciation_runs', 'fixed_assets', 'asset_classes'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('account_roles')->whereIn('code', ['fixed_asset_cost', 'accumulated_depreciation', 'depreciation_expense', 'asset_disposal_gain_loss'])->delete();
    }
};
