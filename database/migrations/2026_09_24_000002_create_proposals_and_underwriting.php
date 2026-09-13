<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 3 design §2 step 2 and §5 (slice R5): proposals, underwriting limits and underwriting referrals through the approval engine.
 *
 * - `proposals`: one per accepted quotation, with KYC, the underwriting outcome and reasons, a manual loading with its reason, and the rating result
 *   (the quotation's, or re-rated with the loading). Status draft → submitted → approved | declined → issued (R7).
 * - `underwriting_limits`: the largest sum insured a role may accept per product class, effective-dated, no overlaps (exclusion constraint).
 * - `approvals.steps`: an approval may carry its own steps (the role whose underwriting limit covers the sum insured) instead of an approval policy
 *   (DECISION D-31); `policy_id` becomes optional for such approvals.
 * - Permissions underwriting.decide (branch manager, finance manager, CFO; A-86) and underwriting.manage_limits (tenant admin; A-87), and the object SoD rule
 *   quotation.create ✕ underwriting.decide: whoever prepared a proposal does not decide its referral.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('branch_id');
            $t->uuid('quotation_id');
            $t->string('number', 64);
            $t->uuid('product_id');
            $t->uuid('product_version_id');
            $t->string('class_code', 32);
            $t->uuid('customer_party_id');
            $t->uuid('producer_id')->nullable();
            $t->date('inception');
            $t->jsonb('risk_inputs');
            $t->jsonb('risk_keys');
            $t->jsonb('rating_result');
            $t->string('rating_plan_code', 64);
            $t->unsignedInteger('rating_plan_version');
            $t->char('currency', 3);
            $t->bigInteger('sum_insured_minor');
            $t->bigInteger('net_premium_minor');
            $t->bigInteger('duties_minor');
            $t->bigInteger('gross_premium_minor');
            $t->string('status', 16)->default('draft');
            $t->string('kyc_status', 16)->default('pending');
            $t->string('kyc_id_type', 32)->nullable();
            $t->string('kyc_id_number', 64)->nullable();
            $t->uuid('kyc_verified_by')->nullable();
            $t->timestampTz('kyc_verified_at')->nullable();
            $t->text('kyc_waiver_reason')->nullable();
            $t->string('underwriting_status', 16)->nullable();
            $t->jsonb('referral_reasons')->nullable();
            $t->uuid('approval_id')->nullable();
            $t->integer('manual_loading_bp')->nullable();
            $t->text('manual_loading_reason')->nullable();
            $t->uuid('manual_loading_by')->nullable();
            $t->uuid('submitted_by')->nullable();
            $t->timestampTz('submitted_at')->nullable();
            $t->uuid('decided_by')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->uuid('policy_id')->nullable();
            $t->timestampTz('issued_at')->nullable();
            $t->uuid('created_by');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'quotation_id']);
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status', 'underwriting_status']);
            $t->foreign('quotation_id')->references('id')->on('quotations');
            $t->foreign('class_code')->references('code')->on('product_classes');
        });
        DB::statement('CREATE INDEX proposals_risk_keys ON proposals USING gin (risk_keys)');
        DB::statement("ALTER TABLE proposals ADD CONSTRAINT proposals_status_valid CHECK (status IN ('draft','submitted','approved','declined','issued'))");
        DB::statement("ALTER TABLE proposals ADD CONSTRAINT proposals_kyc_status_valid CHECK (kyc_status IN ('pending','verified','waived'))");
        DB::statement("ALTER TABLE proposals ADD CONSTRAINT proposals_underwriting_status_valid CHECK (underwriting_status IS NULL OR underwriting_status IN ('auto_approved','referred','approved','declined'))");
        DB::statement("ALTER TABLE proposals ADD CONSTRAINT proposals_kyc_complete CHECK ((kyc_status <> 'verified' OR (kyc_id_type IS NOT NULL AND kyc_id_number IS NOT NULL AND kyc_verified_by IS NOT NULL))
            AND (kyc_status <> 'waived' OR (kyc_waiver_reason IS NOT NULL AND length(trim(kyc_waiver_reason)) > 0 AND kyc_verified_by IS NOT NULL)))");
        DB::statement("ALTER TABLE proposals ADD CONSTRAINT proposals_loading_has_reason CHECK (manual_loading_bp IS NULL OR (manual_loading_bp BETWEEN 1 AND 10000 AND manual_loading_reason IS NOT NULL AND length(trim(manual_loading_reason)) > 0))");
        DB::statement("ALTER TABLE proposals ADD CONSTRAINT proposals_decided CHECK (status NOT IN ('approved','declined','issued') OR underwriting_status IN ('auto_approved','approved','declined'))");
        DB::statement("ALTER TABLE proposals ADD CONSTRAINT proposals_issued_has_policy CHECK (status <> 'issued' OR policy_id IS NOT NULL)");
        RowLevelSecurity::enable('proposals');

        Schema::create('underwriting_limits', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('role_code', 64);
            $t->string('class_code', 32);
            $t->bigInteger('max_sum_insured_minor');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->boolean('verify')->default(false);
            $t->uuid('created_by');
            $t->timestampsTz();
            $t->foreign('class_code')->references('code')->on('product_classes');
        });
        DB::statement('ALTER TABLE underwriting_limits ADD CONSTRAINT underwriting_limits_amount_valid CHECK (max_sum_insured_minor >= 0)');
        DB::statement('ALTER TABLE underwriting_limits ADD CONSTRAINT underwriting_limits_range_valid CHECK (effective_to IS NULL OR effective_to > effective_from)');
        DB::statement("ALTER TABLE underwriting_limits ADD CONSTRAINT underwriting_limits_no_overlap EXCLUDE USING gist (tenant_id WITH =, role_code WITH =, class_code WITH =, daterange(effective_from, effective_to, '[)') WITH &&)");
        RowLevelSecurity::enable('underwriting_limits');

        Schema::table('approvals', function (Blueprint $t): void {
            $t->jsonb('steps')->nullable();
        });
        DB::statement('ALTER TABLE approvals ALTER COLUMN policy_id DROP NOT NULL');
        DB::statement('ALTER TABLE approvals ADD CONSTRAINT approvals_policy_or_steps CHECK (policy_id IS NOT NULL OR steps IS NOT NULL)');

        DB::table('permissions')->insertOrIgnore([
            ['code' => 'underwriting.decide', 'description' => 'Decide underwriting referrals (approve, approve with a loading, decline) within one\'s underwriting limit, and waive KYC'],
            ['code' => 'underwriting.manage_limits', 'description' => 'Set underwriting limits: the largest sum insured each role may accept per product class'],
        ]);
        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            foreach (DB::table('roles')->whereIn('code', ['branch_manager', 'finance_manager', 'cfo'])->pluck('id') as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => (string) $roleId, 'permission_code' => 'underwriting.decide']);
            }
            foreach (DB::table('roles')->where('code', 'tenant_admin')->pluck('id') as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => (string) $roleId, 'permission_code' => 'underwriting.manage_limits']);
            }
            if (! DB::table('sod_rules')->where('permission_a', 'quotation.create')->where('permission_b', 'underwriting.decide')->exists()) {
                $code = DB::table('sod_rules')->where('code', 'SOD8')->exists() ? 'SOD-UNDERWRITING' : 'SOD8';
                DB::table('sod_rules')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => $code, 'permission_a' => 'quotation.create',
                    'permission_b' => 'underwriting.decide', 'mode' => 'block', 'applies_to' => 'object']);
            }
        });
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(function (): void {
            DB::table('sod_rules')->where('permission_a', 'quotation.create')->where('permission_b', 'underwriting.decide')->delete();
            DB::table('role_permissions')->whereIn('permission_code', ['underwriting.decide', 'underwriting.manage_limits'])->delete();
        });
        DB::table('permissions')->whereIn('code', ['underwriting.decide', 'underwriting.manage_limits'])->delete();
        DB::statement('ALTER TABLE approvals DROP CONSTRAINT IF EXISTS approvals_policy_or_steps');
        Schema::table('approvals', fn (Blueprint $t) => $t->dropColumn('steps'));
        Schema::dropIfExists('underwriting_limits');
        Schema::dropIfExists('proposals');
    }
};
