<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 3 design §1 rating plans (slice R2): rating_plans, rate_tables, rate_table_rows, rating_steps and duties, all tenant tables with forced RLS.
 *
 * - Lifecycle draft → approved → active → retired (D-21). The approver is never the creator (CHECK, besides the service and the SoD rule).
 * - INVARIANT only one active plan per product class per date: exclusion constraint over (tenant, class, [effective_from, effective_to)) for active plans.
 * - Only a draft changes: a trigger refuses edits of approved, active or retired plans and of their tables, rows and steps. From active, only
 *   retiring and shortening effective_to (when a successor supersedes it) are allowed; status moves only forward.
 * - Permissions rating.manage_plans and rating.approve_plans (A-69), with the object-level SoD rule manage ≠ approve on the same plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rating_plans', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('code', 64);
            $t->string('name');
            $t->string('class_code', 32);
            $t->unsignedInteger('version');
            $t->char('currency', 3)->default('BDT');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('status')->default('draft');
            $t->string('source')->default('company');
            $t->boolean('verify')->default(false);
            $t->text('notes')->nullable();
            $t->uuid('copied_from_plan_id')->nullable();
            $t->uuid('created_by');
            $t->uuid('approved_by')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->uuid('activated_by')->nullable();
            $t->timestampTz('activated_at')->nullable();
            $t->uuid('retired_by')->nullable();
            $t->timestampTz('retired_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code', 'version']);
            $t->foreign('class_code')->references('code')->on('product_classes');
        });
        DB::statement("ALTER TABLE rating_plans ADD CONSTRAINT rating_plans_status_valid CHECK (status IN ('draft','approved','active','retired'))");
        DB::statement("ALTER TABLE rating_plans ADD CONSTRAINT rating_plans_source_valid CHECK (source IN ('idra_tariff','company'))");
        DB::statement('ALTER TABLE rating_plans ADD CONSTRAINT rating_plans_range_valid CHECK (effective_to IS NULL OR effective_to > effective_from)');
        DB::statement('ALTER TABLE rating_plans ADD CONSTRAINT rating_plans_maker_checker CHECK (approved_by IS NULL OR approved_by <> created_by)');
        DB::statement("ALTER TABLE rating_plans ADD CONSTRAINT rating_plans_approved_has_approver CHECK (status = 'draft' OR approved_by IS NOT NULL)");
        DB::statement("ALTER TABLE rating_plans ADD CONSTRAINT rating_plans_one_active_per_class EXCLUDE USING gist (tenant_id WITH =, class_code WITH =, daterange(effective_from, effective_to, '[)') WITH &&) WHERE (status = 'active')");

        Schema::create('rate_tables', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('plan_id')->index();
            $t->string('code', 64);
            $t->string('name');
            $t->jsonb('dimensions');
            $t->string('value_type');
            $t->timestampsTz();
            $t->unique(['plan_id', 'code']);
            $t->foreign('plan_id')->references('id')->on('rating_plans');
        });
        DB::statement("ALTER TABLE rate_tables ADD CONSTRAINT rate_tables_value_type_valid CHECK (value_type IN ('rate_pm','rate_pct','flat','band'))");

        Schema::create('rate_table_rows', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('table_id')->index();
            $t->unsignedInteger('position')->default(0);
            $t->jsonb('keys');
            $t->bigInteger('value_minor')->nullable();
            $t->integer('value_bp')->nullable();
            $t->bigInteger('band_from')->nullable();
            $t->bigInteger('band_to')->nullable();
            $t->string('band_label')->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->timestampsTz();
            $t->foreign('table_id')->references('id')->on('rate_tables');
        });
        DB::statement('ALTER TABLE rate_table_rows ADD CONSTRAINT rate_table_rows_one_value CHECK (value_minor IS NULL OR value_bp IS NULL)');
        DB::statement('ALTER TABLE rate_table_rows ADD CONSTRAINT rate_table_rows_band_valid CHECK (band_to IS NULL OR (band_from IS NOT NULL AND band_to > band_from))');
        DB::statement('ALTER TABLE rate_table_rows ADD CONSTRAINT rate_table_rows_range_valid CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to > effective_from)');

        Schema::create('rating_steps', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('plan_id')->index();
            $t->unsignedInteger('order_no');
            $t->string('code', 64);
            $t->string('kind');
            $t->text('expression');
            $t->text('condition')->nullable();
            $t->string('applies_to', 64)->nullable();
            $t->string('label_en');
            $t->string('label_bn');
            $t->timestampsTz();
            $t->unique(['plan_id', 'order_no']);
            $t->unique(['plan_id', 'code']);
            $t->foreign('plan_id')->references('id')->on('rating_plans');
        });
        DB::statement("ALTER TABLE rating_steps ADD CONSTRAINT rating_steps_kind_valid CHECK (kind IN ('base','coverage','loading','discount','minimum','rounding','duty','tax'))");

        Schema::create('duties', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('code', 16);
            $t->string('basis');
            $t->integer('rate_bp')->nullable();
            $t->bigInteger('amount_minor')->nullable();
            $t->jsonb('bands')->nullable();
            $t->jsonb('class_codes');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('label_en');
            $t->string('label_bn');
            $t->boolean('verify')->default(true);
            $t->string('source')->nullable();
            $t->uuid('created_by');
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE duties ADD CONSTRAINT duties_code_valid CHECK (code IN ('vat','stamp','levy'))");
        DB::statement(<<<'SQL'
            ALTER TABLE duties ADD CONSTRAINT duties_basis_value CHECK (
              (basis = 'pct_of_premium' AND rate_bp IS NOT NULL AND rate_bp BETWEEN 0 AND 10000 AND amount_minor IS NULL AND bands IS NULL)
              OR (basis = 'flat_per_policy' AND amount_minor IS NOT NULL AND amount_minor >= 0 AND rate_bp IS NULL AND bands IS NULL)
              OR (basis = 'per_sum_insured_band' AND bands IS NOT NULL AND rate_bp IS NULL AND amount_minor IS NULL))
            SQL);
        DB::statement('ALTER TABLE duties ADD CONSTRAINT duties_range_valid CHECK (effective_to IS NULL OR effective_to > effective_from)');

        foreach (['rating_plans', 'rate_tables', 'rate_table_rows', 'rating_steps', 'duties'] as $table) {
            RowLevelSecurity::enable($table);
        }

        Schema::table('product_versions', function (Blueprint $t): void {
            $t->uuid('rating_plan_id')->nullable();
            $t->foreign('rating_plan_id')->references('id')->on('rating_plans');
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_rating_plan() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE frozen_new jsonb; frozen_old jsonb;
BEGIN
  IF TG_OP = 'DELETE' THEN
    IF OLD.status <> 'draft' THEN
      RAISE EXCEPTION 'RATING_PLAN_IMMUTABLE: rating plan % is % and cannot be deleted', OLD.id, OLD.status USING ERRCODE = 'check_violation';
    END IF;
    RETURN OLD;
  END IF;
  IF OLD.status = 'draft' AND NEW.status IN ('draft', 'approved') THEN
    RETURN NEW;
  END IF;
  IF NOT (NEW.status = OLD.status OR (OLD.status = 'approved' AND NEW.status IN ('active', 'retired')) OR (OLD.status = 'active' AND NEW.status = 'retired')) THEN
    RAISE EXCEPTION 'RATING_PLAN_TRANSITION: rating plan % cannot go from % to %', OLD.id, OLD.status, NEW.status USING ERRCODE = 'check_violation';
  END IF;
  frozen_new := row_to_json(NEW)::jsonb - 'status' - 'effective_to' - 'activated_by' - 'activated_at' - 'retired_by' - 'retired_at' - 'updated_at';
  frozen_old := row_to_json(OLD)::jsonb - 'status' - 'effective_to' - 'activated_by' - 'activated_at' - 'retired_by' - 'retired_at' - 'updated_at';
  IF frozen_new <> frozen_old OR (NEW.effective_to IS DISTINCT FROM OLD.effective_to
      AND NOT (OLD.status = 'active' AND NEW.effective_to IS NOT NULL AND (OLD.effective_to IS NULL OR NEW.effective_to < OLD.effective_to))) THEN
    RAISE EXCEPTION 'RATING_PLAN_IMMUTABLE: rating plan % is % and cannot be changed', OLD.id, OLD.status USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;

CREATE TRIGGER rating_plans_immutable BEFORE UPDATE OR DELETE ON rating_plans FOR EACH ROW EXECUTE FUNCTION protect_rating_plan();

CREATE OR REPLACE FUNCTION protect_rating_plan_parts() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE plan_ids uuid[]; pid uuid; pstatus text;
BEGIN
  IF TG_TABLE_NAME = 'rate_table_rows' THEN
    SELECT array_agg(plan_id) INTO plan_ids FROM rate_tables
      WHERE id IN (CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE OLD.table_id END, CASE WHEN TG_OP = 'DELETE' THEN NULL ELSE NEW.table_id END);
  ELSE
    plan_ids := ARRAY[CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE OLD.plan_id END, CASE WHEN TG_OP = 'DELETE' THEN NULL ELSE NEW.plan_id END];
  END IF;
  FOREACH pid IN ARRAY coalesce(plan_ids, ARRAY[]::uuid[]) LOOP
    CONTINUE WHEN pid IS NULL;
    SELECT status INTO pstatus FROM rating_plans WHERE id = pid;
    IF pstatus IS NOT NULL AND pstatus <> 'draft' THEN
      RAISE EXCEPTION 'RATING_PLAN_IMMUTABLE: rating plan % is % and its tables, rows and steps cannot change', pid, pstatus USING ERRCODE = 'check_violation';
    END IF;
  END LOOP;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;

CREATE TRIGGER rate_tables_immutable BEFORE INSERT OR UPDATE OR DELETE ON rate_tables FOR EACH ROW EXECUTE FUNCTION protect_rating_plan_parts();
CREATE TRIGGER rate_table_rows_immutable BEFORE INSERT OR UPDATE OR DELETE ON rate_table_rows FOR EACH ROW EXECUTE FUNCTION protect_rating_plan_parts();
CREATE TRIGGER rating_steps_immutable BEFORE INSERT OR UPDATE OR DELETE ON rating_steps FOR EACH ROW EXECUTE FUNCTION protect_rating_plan_parts();
SQL);

        DB::table('permissions')->insertOrIgnore([
            ['code' => 'rating.manage_plans', 'description' => 'Draft rating plans (rate tables, steps), new plan versions and premium duties'],
            ['code' => 'rating.approve_plans', 'description' => 'Approve, activate and retire rating plans (never a plan one drafted)'],
        ]);
        // Existing tenants: the finance manager and CFO templates gain both permissions (A-69); the object-level SoD rule is added once.
        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            $roles = DB::table('roles')->whereIn('code', ['finance_manager', 'cfo'])->pluck('id');
            foreach ($roles as $roleId) {
                foreach (['rating.manage_plans', 'rating.approve_plans'] as $permission) {
                    DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => (string) $roleId, 'permission_code' => $permission]);
                }
            }
            if (! DB::table('sod_rules')->where('permission_a', 'rating.manage_plans')->where('permission_b', 'rating.approve_plans')->exists()) {
                $code = DB::table('sod_rules')->where('code', 'SOD7')->exists() ? 'SOD-RATING' : 'SOD7';
                DB::table('sod_rules')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => $code, 'permission_a' => 'rating.manage_plans',
                    'permission_b' => 'rating.approve_plans', 'mode' => 'block', 'applies_to' => 'object']);
            }
        });
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(function (): void {
            DB::table('sod_rules')->where('permission_a', 'rating.manage_plans')->delete();
            DB::table('role_permissions')->whereIn('permission_code', ['rating.manage_plans', 'rating.approve_plans'])->delete();
        });
        DB::table('permissions')->whereIn('code', ['rating.manage_plans', 'rating.approve_plans'])->delete();
        Schema::table('product_versions', function (Blueprint $t): void {
            $t->dropForeign(['rating_plan_id']);
            $t->dropColumn('rating_plan_id');
        });
        DB::unprepared('DROP TRIGGER IF EXISTS rating_steps_immutable ON rating_steps; DROP TRIGGER IF EXISTS rate_table_rows_immutable ON rate_table_rows;
            DROP TRIGGER IF EXISTS rate_tables_immutable ON rate_tables; DROP FUNCTION IF EXISTS protect_rating_plan_parts;
            DROP TRIGGER IF EXISTS rating_plans_immutable ON rating_plans; DROP FUNCTION IF EXISTS protect_rating_plan;');
        foreach (['duties', 'rating_steps', 'rate_table_rows', 'rate_tables', 'rating_plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
