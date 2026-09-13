<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 design §2 quotations (slice R4): a quote for a product version with its risk inputs and the rating result frozen when it is issued.
 *
 * - Status draft → issued → converted | expired | declined; a draft may also be declined. The database refuses any other move.
 * - INVARIANT the rating is frozen at issue: once a quotation leaves draft, its risk inputs, coverages, rating result, premiums, product, customer,
 *   producer, dates and number never change (trigger `QUOTATION_FROZEN`), so a later tariff change never alters an issued quotation.
 * - Permission quotation.create (branch officer and branch manager templates, A-83); existing tenants' roles get it here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('branch_id');
            $t->string('number', 64)->nullable();
            $t->uuid('product_id');
            $t->uuid('product_version_id');
            $t->string('class_code', 32)->nullable();
            $t->uuid('customer_party_id')->nullable();
            $t->uuid('producer_id')->nullable();
            $t->date('inception');
            $t->jsonb('risk_inputs');
            $t->jsonb('coverages');
            $t->jsonb('risk_keys')->nullable();
            $t->jsonb('rating_result')->nullable();
            $t->string('rating_plan_code', 64)->nullable();
            $t->unsignedInteger('rating_plan_version')->nullable();
            $t->char('currency', 3);
            $t->bigInteger('sum_insured_minor')->nullable();
            $t->bigInteger('net_premium_minor')->nullable();
            $t->bigInteger('duties_minor')->nullable();
            $t->bigInteger('gross_premium_minor')->nullable();
            $t->date('valid_until')->nullable();
            $t->string('status', 16)->default('draft');
            $t->boolean('producer_eligible')->nullable();
            $t->string('producer_eligibility_reason', 64)->nullable();
            $t->text('producer_eligibility_note')->nullable();
            $t->text('decline_reason')->nullable();
            $t->uuid('declined_by')->nullable();
            $t->timestampTz('declined_at')->nullable();
            $t->uuid('issued_by')->nullable();
            $t->timestampTz('issued_at')->nullable();
            $t->timestampTz('expired_at')->nullable();
            $t->timestampTz('converted_at')->nullable();
            $t->uuid('created_by');
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'status', 'valid_until']);
            $t->foreign('class_code')->references('code')->on('product_classes');
        });
        DB::statement('CREATE UNIQUE INDEX quotations_number_unique ON quotations (tenant_id, number) WHERE number IS NOT NULL');
        DB::statement('CREATE INDEX quotations_risk_keys ON quotations USING gin (risk_keys)');
        DB::statement("ALTER TABLE quotations ADD CONSTRAINT quotations_status_valid CHECK (status IN ('draft','issued','expired','converted','declined'))");
        DB::statement("ALTER TABLE quotations ADD CONSTRAINT quotations_issued_complete CHECK (status IN ('draft','declined') OR (number IS NOT NULL AND rating_result IS NOT NULL AND valid_until IS NOT NULL AND customer_party_id IS NOT NULL))");
        DB::statement("ALTER TABLE quotations ADD CONSTRAINT quotations_declined_reason CHECK (status <> 'declined' OR (decline_reason IS NOT NULL AND length(trim(decline_reason)) > 0))");
        DB::statement('ALTER TABLE quotations ADD CONSTRAINT quotations_amounts_valid CHECK ((sum_insured_minor IS NULL OR sum_insured_minor >= 0) AND (net_premium_minor IS NULL OR net_premium_minor >= 0) AND (gross_premium_minor IS NULL OR gross_premium_minor >= 0))');
        RowLevelSecurity::enable('quotations');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_quotation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    IF OLD.status <> 'draft' THEN
      RAISE EXCEPTION 'QUOTATION_FROZEN: quotation % is %, it cannot be deleted', OLD.id, OLD.status USING ERRCODE = '23514';
    END IF;
    RETURN OLD;
  END IF;
  IF OLD.status <> NEW.status AND NOT (
       (OLD.status = 'draft' AND NEW.status IN ('issued','declined'))
    OR (OLD.status = 'issued' AND NEW.status IN ('expired','declined','converted'))) THEN
    RAISE EXCEPTION 'QUOTATION_TRANSITION: quotation % cannot move from % to %', OLD.id, OLD.status, NEW.status USING ERRCODE = '23514';
  END IF;
  IF OLD.status <> 'draft' AND (
       NEW.risk_inputs IS DISTINCT FROM OLD.risk_inputs OR NEW.coverages IS DISTINCT FROM OLD.coverages OR NEW.rating_result IS DISTINCT FROM OLD.rating_result
    OR NEW.rating_plan_code IS DISTINCT FROM OLD.rating_plan_code OR NEW.rating_plan_version IS DISTINCT FROM OLD.rating_plan_version
    OR NEW.net_premium_minor IS DISTINCT FROM OLD.net_premium_minor OR NEW.duties_minor IS DISTINCT FROM OLD.duties_minor
    OR NEW.gross_premium_minor IS DISTINCT FROM OLD.gross_premium_minor OR NEW.sum_insured_minor IS DISTINCT FROM OLD.sum_insured_minor
    OR NEW.number IS DISTINCT FROM OLD.number OR NEW.product_version_id IS DISTINCT FROM OLD.product_version_id OR NEW.product_id IS DISTINCT FROM OLD.product_id
    OR NEW.customer_party_id IS DISTINCT FROM OLD.customer_party_id OR NEW.producer_id IS DISTINCT FROM OLD.producer_id
    OR NEW.inception IS DISTINCT FROM OLD.inception OR NEW.valid_until IS DISTINCT FROM OLD.valid_until OR NEW.branch_id IS DISTINCT FROM OLD.branch_id
    OR NEW.risk_keys IS DISTINCT FROM OLD.risk_keys) THEN
    RAISE EXCEPTION 'QUOTATION_FROZEN: quotation % is %, its terms cannot change', OLD.id, OLD.status USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER quotations_protect BEFORE UPDATE OR DELETE ON quotations FOR EACH ROW EXECUTE FUNCTION protect_quotation();
SQL);

        DB::table('permissions')->insertOrIgnore(['code' => 'quotation.create', 'description' => 'Rate, save, issue and decline quotations and turn them into proposals']);
        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            foreach (DB::table('roles')->whereIn('code', ['branch_officer', 'branch_manager'])->pluck('id') as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => (string) $roleId, 'permission_code' => 'quotation.create']);
            }
        });
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(fn () => DB::table('role_permissions')->where('permission_code', 'quotation.create')->delete());
        DB::table('permissions')->where('code', 'quotation.create')->delete();
        DB::unprepared('DROP TRIGGER IF EXISTS quotations_protect ON quotations; DROP FUNCTION IF EXISTS protect_quotation;');
        Schema::dropIfExists('quotations');
    }
};
