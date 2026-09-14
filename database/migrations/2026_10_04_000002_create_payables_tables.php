<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Slices 2.3 and 2.4 accounts payable (addendum v2 §B.4): suppliers (a vendor party with terms, tax profile and paying bank account), supplier bills
 * and their lines, payment runs with their items (bank account snapshot per item) and the bank payment files generated from released runs.
 * Permissions ap.* and the object SoD rules (enter ✕ approve bills; prepare ✕ approve ✕ release payment runs) for existing tenants.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'ap.manage_suppliers' => 'Create and change suppliers, their payment terms, tax profile and bank account',
        'ap.enter_bills' => 'Enter supplier bills and submit them for approval',
        'ap.approve_bills' => 'Approve or reject supplier bills someone else entered; approving posts them',
        'ap.prepare_payments' => 'Prepare payment runs from due supplier bills',
        'ap.approve_payments' => 'Approve payment runs someone else prepared',
        'ap.release_payments' => 'Release approved payment runs to the bank',
    ];

    /** [permission_a, permission_b] object rules */
    private const SOD = [
        ['ap.enter_bills', 'ap.approve_bills'],
        ['ap.prepare_payments', 'ap.approve_payments'],
        ['ap.approve_payments', 'ap.release_payments'],
        ['ap.prepare_payments', 'ap.release_payments'],
    ];

    private const GRANTS = [
        'accountant' => ['ap.manage_suppliers', 'ap.enter_bills', 'ap.prepare_payments'],
        'finance_manager' => ['ap.manage_suppliers', 'ap.approve_bills', 'ap.approve_payments'],
        'cfo' => ['ap.manage_suppliers', 'ap.approve_bills', 'ap.approve_payments', 'ap.release_payments'],
    ];

    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('party_id');
            $t->string('code', 32);
            $t->string('category', 32);
            $t->string('status', 16)->default('active');
            $t->smallInteger('payment_terms_days')->default(30);
            $t->uuid('default_account_id')->nullable();
            $t->string('tin', 32)->nullable();
            $t->string('bin', 32)->nullable();
            $t->boolean('vat_registered')->default(false);
            $t->string('bank_name')->nullable();
            $t->string('bank_branch')->nullable();
            $t->string('routing_no', 16)->nullable();
            $t->string('account_name')->nullable();
            $t->string('account_no_masked', 64)->nullable();
            $t->text('account_no_enc')->nullable();
            $t->uuid('created_by');
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'party_id']);
        });
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_status_valid CHECK (status IN ('active','on_hold','blocked'))");
        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_terms_valid CHECK (payment_terms_days BETWEEN 0 AND 365)');

        Schema::create('ap_bills', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('branch_id');
            $t->string('number', 64)->nullable();
            $t->uuid('supplier_id')->index();
            $t->string('supplier_reference', 64);
            $t->date('bill_date');
            $t->date('due_date');
            $t->date('accounting_date')->nullable();
            $t->char('currency', 3);
            $t->text('description')->nullable();
            $t->bigInteger('net_minor')->default(0);
            $t->bigInteger('vat_minor')->default(0);
            $t->bigInteger('vds_minor')->default(0);
            $t->bigInteger('tds_minor')->default(0);
            $t->bigInteger('gross_minor')->default(0);
            $t->bigInteger('payable_minor')->default(0);
            $t->bigInteger('paid_minor')->default(0);
            $t->string('status', 24)->default('draft');
            $t->string('source_type', 32)->default('manual');
            $t->uuid('source_id')->nullable();
            $t->uuid('approval_id')->nullable();
            $t->uuid('created_by');
            $t->uuid('submitted_by')->nullable();
            $t->uuid('approved_by')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->uuid('cancelled_by')->nullable();
            $t->date('cancelled_on')->nullable();
            $t->text('cancelled_reason')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'status', 'due_date']);
        });
        DB::statement("ALTER TABLE ap_bills ADD CONSTRAINT ap_bills_status_valid CHECK (status IN ('draft','pending_approval','posted','partially_paid','paid','cancelled'))");
        DB::statement('ALTER TABLE ap_bills ADD CONSTRAINT ap_bills_payable_valid CHECK (payable_minor = gross_minor - vds_minor - tds_minor AND gross_minor = net_minor + vat_minor)');
        DB::statement('ALTER TABLE ap_bills ADD CONSTRAINT ap_bills_paid_valid CHECK (paid_minor >= 0 AND paid_minor <= payable_minor)');
        DB::statement("CREATE UNIQUE INDEX ap_bills_open_supplier_reference ON ap_bills (tenant_id, supplier_id, lower(supplier_reference)) WHERE status <> 'cancelled'");
        DB::statement('CREATE UNIQUE INDEX ap_bills_tenant_number ON ap_bills (tenant_id, number) WHERE number IS NOT NULL');

        Schema::create('ap_bill_lines', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('bill_id')->index();
            $t->smallInteger('line_no');
            $t->string('description', 500);
            $t->uuid('account_id');
            $t->uuid('claim_id')->nullable();
            $t->uuid('policy_id')->nullable();
            $t->bigInteger('net_minor');
            $t->bigInteger('vat_minor')->default(0);
            $t->bigInteger('vds_minor')->default(0);
            $t->bigInteger('tds_minor')->default(0);
            $t->unique(['bill_id', 'line_no']);
        });
        DB::statement('ALTER TABLE ap_bill_lines ADD CONSTRAINT ap_bill_lines_amounts_valid CHECK (net_minor > 0 AND vat_minor >= 0 AND vds_minor >= 0 AND tds_minor >= 0 AND vds_minor <= vat_minor)');

        Schema::create('payment_runs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->string('number', 64);
            $t->uuid('bank_account_id');
            $t->date('pay_date');
            $t->char('currency', 3);
            $t->bigInteger('total_minor')->default(0);
            $t->integer('item_count')->default(0);
            $t->string('status', 24)->default('draft');
            $t->uuid('approval_id')->nullable();
            $t->uuid('created_by');
            $t->uuid('submitted_by')->nullable();
            $t->uuid('approved_by')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->uuid('released_by')->nullable();
            $t->timestampTz('released_at')->nullable();
            $t->text('cancelled_reason')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
        });
        DB::statement("ALTER TABLE payment_runs ADD CONSTRAINT payment_runs_status_valid CHECK (status IN ('draft','pending_approval','approved','released','cancelled'))");

        Schema::create('payment_run_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('run_id')->index();
            $t->string('payable_type', 32)->default('ap_bill');
            $t->uuid('payable_id');
            $t->uuid('supplier_id');
            $t->uuid('payee_party_id');
            $t->uuid('branch_id');
            $t->jsonb('bank_account_snapshot');
            $t->bigInteger('amount_minor');
            $t->string('status', 16)->default('included');
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE payment_run_items ADD CONSTRAINT payment_run_items_status_valid CHECK (status IN ('included','removed','released'))");
        DB::statement('ALTER TABLE payment_run_items ADD CONSTRAINT payment_run_items_amount_positive CHECK (amount_minor > 0)');
        // INVARIANT (§B.4): a payable is in at most one run waiting to be released.
        DB::statement("CREATE UNIQUE INDEX payment_run_items_one_open ON payment_run_items (payable_type, payable_id) WHERE status = 'included'");

        Schema::create('bank_payment_files', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('run_id')->index();
            $t->string('format_code', 32);
            $t->integer('version');
            $t->uuid('stored_document_id')->nullable();
            $t->char('sha256', 64);
            $t->bigInteger('total_minor');
            $t->integer('item_count');
            $t->uuid('generated_by');
            $t->timestampTz('generated_at');
            $t->unique(['run_id', 'format_code', 'version']);
        });

        foreach (['suppliers', 'ap_bills', 'ap_bill_lines', 'payment_runs', 'payment_run_items', 'bank_payment_files'] as $table) {
            RowLevelSecurity::enable($table);
        }

        foreach (self::PERMISSIONS as $code => $description) {
            DB::table('permissions')->updateOrInsert(['code' => $code], ['description' => $description]);
        }
        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            foreach (self::GRANTS as $roleCode => $permissions) {
                $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
                foreach ($roleId === null ? [] : $permissions as $permission) {
                    DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => (string) $roleId, 'permission_code' => $permission]);
                }
            }
            foreach (self::SOD as $i => [$a, $b]) {
                if (! DB::table('sod_rules')->where('permission_a', $a)->where('permission_b', $b)->exists()) {
                    DB::table('sod_rules')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => 'SOD-AP-'.($i + 1), 'permission_a' => $a,
                        'permission_b' => $b, 'mode' => 'block', 'applies_to' => 'object']);
                }
            }
        });
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(function (): void {
            DB::table('sod_rules')->where('permission_a', 'like', 'ap.%')->delete();
            DB::table('role_permissions')->whereIn('permission_code', array_keys(self::PERMISSIONS))->delete();
        });
        DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->delete();
        foreach (['bank_payment_files', 'payment_run_items', 'payment_runs', 'ap_bill_lines', 'ap_bills', 'suppliers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
