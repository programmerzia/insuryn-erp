<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Design addendum v2 §B.6 petty cash (imprest; expense claims wait for employees): floats per branch with a custodian, vouchers, replenishment with approval,
 * surprise cash counts. Account roles petty_cash, petty_cash_expense and petty_cash_over_short (§B.2.5).
 */
return new class extends Migration
{
    private const GRANTS = ['branch_manager' => ['pettycash.spend'], 'accountant' => ['pettycash.replenish'], 'finance_manager' => ['pettycash.approve'], 'cfo' => ['pettycash.approve']];

    public function up(): void
    {
        DB::table('account_roles')->insertOrIgnore([
            ['code' => 'petty_cash', 'description' => 'Petty cash floats held at branches'],
            ['code' => 'petty_cash_expense', 'description' => 'Expenses paid from petty cash (per voucher account)'],
            ['code' => 'petty_cash_over_short', 'description' => 'Petty cash shortages and surpluses found on counting'],
        ]);

        Schema::create('petty_cash_floats', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('branch_id');
            $t->string('code', 32);
            $t->string('name');
            $t->uuid('custodian_user_id');
            $t->bigInteger('imprest_minor');
            $t->uuid('gl_account_id');
            $t->char('currency', 3);
            $t->date('issued_on');
            $t->uuid('bank_account_id');
            $t->string('status', 20)->default('active');
            $t->timestampsTz();
            $t->unique(['entity_id', 'code']);
        });
        DB::statement("ALTER TABLE petty_cash_floats ADD CONSTRAINT petty_cash_floats_valid CHECK (imprest_minor > 0 AND status IN ('active','closed'))");

        Schema::create('petty_cash_replenishments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('float_id')->index();
            $t->string('number', 40)->unique();
            $t->bigInteger('amount_minor');
            $t->uuid('bank_account_id');
            $t->string('status', 20);
            $t->uuid('requested_by');
            $t->timestampTz('requested_at');
            $t->uuid('decided_by')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->date('paid_on')->nullable();
            $t->string('decision_reason', 500)->nullable();
            $t->foreign('float_id')->references('id')->on('petty_cash_floats');
        });
        DB::statement("ALTER TABLE petty_cash_replenishments ADD CONSTRAINT petty_cash_replenishments_valid CHECK (amount_minor > 0 AND status IN ('pending_approval','paid','rejected') AND (status <> 'paid' OR (paid_on IS NOT NULL AND decided_by IS NOT NULL AND decided_by <> requested_by)))");

        Schema::create('petty_cash_vouchers', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('float_id')->index();
            $t->string('number', 40)->unique();
            $t->date('voucher_date');
            $t->string('payee');
            $t->string('description', 500);
            $t->uuid('account_id');
            $t->bigInteger('amount_minor');
            $t->string('status', 20)->default('posted');
            $t->uuid('replenishment_id')->nullable()->index();
            $t->uuid('created_by');
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('float_id')->references('id')->on('petty_cash_floats');
            $t->foreign('replenishment_id')->references('id')->on('petty_cash_replenishments');
        });
        DB::statement("ALTER TABLE petty_cash_vouchers ADD CONSTRAINT petty_cash_vouchers_valid CHECK (amount_minor > 0 AND status IN ('posted','voided'))");

        Schema::create('petty_cash_counts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('float_id')->index();
            $t->date('counted_on');
            $t->bigInteger('counted_minor');
            $t->bigInteger('expected_minor');
            $t->bigInteger('difference_minor');
            $t->uuid('counted_by');
            $t->string('note', 500)->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('float_id')->references('id')->on('petty_cash_floats');
        });
        DB::statement('ALTER TABLE petty_cash_counts ADD CONSTRAINT petty_cash_counts_valid CHECK (counted_minor >= 0 AND difference_minor = counted_minor - expected_minor)');

        foreach (['petty_cash_floats', 'petty_cash_replenishments', 'petty_cash_vouchers', 'petty_cash_counts'] as $table) {
            RowLevelSecurity::enable($table);
        }

        // ASSUMPTION A-278: the branch manager holds the branch float, the accountant asks for replenishment and counts, the finance manager (and CFO) approve.
        DB::table('permissions')->insertOrIgnore([['code' => 'pettycash.spend'], ['code' => 'pettycash.replenish'], ['code' => 'pettycash.approve']]);
        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            foreach (self::GRANTS as $roleCode => $permissions) {
                $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
                foreach ($roleId === null ? [] : $permissions as $permission) {
                    DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => (string) $roleId, 'permission_code' => $permission]);
                }
            }
            foreach ([['pettycash.replenish', 'pettycash.approve', 'object', 'SOD-PETTYCASH-1'], ['pettycash.spend', 'pettycash.approve', 'user', 'SOD-PETTYCASH-2']] as [$a, $b, $appliesTo, $code]) {
                if (! DB::table('sod_rules')->where('permission_a', $a)->where('permission_b', $b)->exists()) {
                    DB::table('sod_rules')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => $code, 'permission_a' => $a, 'permission_b' => $b, 'mode' => 'block', 'applies_to' => $appliesTo]);
                }
            }
        });
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(function (): void {
            DB::table('role_permissions')->whereIn('permission_code', ['pettycash.spend', 'pettycash.replenish', 'pettycash.approve'])->delete();
            DB::table('sod_rules')->where('permission_b', 'pettycash.approve')->delete();
        });
        DB::table('permissions')->whereIn('code', ['pettycash.spend', 'pettycash.replenish', 'pettycash.approve'])->delete();
        foreach (['petty_cash_counts', 'petty_cash_vouchers', 'petty_cash_replenishments', 'petty_cash_floats'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('account_roles')->whereIn('code', ['petty_cash', 'petty_cash_expense', 'petty_cash_over_short'])->delete();
    }
};
