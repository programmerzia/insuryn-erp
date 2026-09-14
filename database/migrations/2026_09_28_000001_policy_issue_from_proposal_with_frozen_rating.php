<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 design §2 steps 4–5 (slice R7): policies issued from approved proposals carry the quotation, proposal and superseded cover note, the risk inputs,
 * the rating result and plan version, the special terms and the duplicate-risk keys.
 *
 * - INVARIANT a policy's rating result is frozen at issue: trigger `POLICY_RATING_FROZEN` refuses any change to `rating_result`, `risk_inputs`,
 *   `rating_plan_code`, `rating_plan_version`, `special_terms`, `quotation_id` and `proposal_id` after the row is written; re-rating is an endorsement
 *   transaction whose own `rating_result` is frozen the same way.
 * - Stamp duty gets its own amount (`stamp_duty_minor`, `stamp_duty_delta_minor`) and account role `stamp_duty_payable` (DECISION D-37); the premium CHECK
 *   becomes gross = net + tax + stamp duty.
 * - The renewal chain (design `previous_policy_id`) is the existing `renewal_of_policy_id` (DECISION D-38).
 * - `product_versions.endorsement_uses_current_tariff` (default false): endorsements re-rate on the original plan version unless set.
 * - Credit issuance (OPEN 4, A-117): how the issue was allowed (`issue_basis` credit | premium_received) and the premium-received reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $t): void {
            $t->uuid('quotation_id')->nullable();
            $t->uuid('proposal_id')->nullable();
            $t->uuid('cover_note_id')->nullable();
            $t->jsonb('risk_inputs')->nullable();
            $t->jsonb('risk_keys')->nullable();
            $t->jsonb('rating_result')->nullable();
            $t->string('rating_plan_code', 64)->nullable();
            $t->unsignedInteger('rating_plan_version')->nullable();
            $t->jsonb('special_terms')->nullable();
            $t->bigInteger('stamp_duty_minor')->default(0);
            $t->string('issue_basis', 32)->nullable();
            $t->string('premium_received_reference', 128)->nullable();
            $t->foreign('quotation_id')->references('id')->on('quotations');
            $t->foreign('proposal_id')->references('id')->on('proposals');
            $t->foreign('cover_note_id')->references('id')->on('cover_notes');
        });
        DB::statement('CREATE UNIQUE INDEX policies_one_per_proposal ON policies (tenant_id, proposal_id) WHERE proposal_id IS NOT NULL');
        DB::statement('ALTER TABLE policies DROP CONSTRAINT policies_premium_valid');
        DB::statement('ALTER TABLE policies ADD CONSTRAINT policies_premium_valid CHECK (net_premium_minor >= 0 AND tax_minor >= 0 AND stamp_duty_minor >= 0 AND gross_premium_minor = net_premium_minor + tax_minor + stamp_duty_minor)');
        DB::statement('ALTER TABLE policies ADD CONSTRAINT policies_rating_complete CHECK (rating_result IS NULL OR (risk_inputs IS NOT NULL AND rating_plan_code IS NOT NULL AND rating_plan_version IS NOT NULL))');
        DB::statement("ALTER TABLE policies ADD CONSTRAINT policies_issue_basis_valid CHECK (issue_basis IS NULL OR issue_basis IN ('credit','premium_received'))");
        DB::statement("ALTER TABLE policies ADD CONSTRAINT policies_premium_received_reference CHECK (issue_basis IS DISTINCT FROM 'premium_received' OR (premium_received_reference IS NOT NULL AND length(trim(premium_received_reference)) > 0))");

        Schema::table('policy_transactions', function (Blueprint $t): void {
            $t->bigInteger('stamp_duty_delta_minor')->default(0);
            $t->jsonb('rating_result')->nullable();
            $t->string('rating_basis', 32)->nullable();
        });
        DB::statement("ALTER TABLE policy_transactions ADD CONSTRAINT policy_transactions_rating_basis_valid CHECK (rating_basis IS NULL OR rating_basis IN ('original_plan','current_tariff'))");

        Schema::table('product_versions', function (Blueprint $t): void {
            $t->boolean('endorsement_uses_current_tariff')->default(false);
        });

        DB::table('account_roles')->insertOrIgnore([['code' => 'stamp_duty_payable', 'description' => 'Stamp duty on policies, owed to the government']]);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_policy_rating() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.rating_result IS DISTINCT FROM OLD.rating_result OR NEW.risk_inputs IS DISTINCT FROM OLD.risk_inputs
    OR NEW.rating_plan_code IS DISTINCT FROM OLD.rating_plan_code OR NEW.rating_plan_version IS DISTINCT FROM OLD.rating_plan_version
    OR NEW.special_terms IS DISTINCT FROM OLD.special_terms OR NEW.quotation_id IS DISTINCT FROM OLD.quotation_id OR NEW.proposal_id IS DISTINCT FROM OLD.proposal_id THEN
    RAISE EXCEPTION 'POLICY_RATING_FROZEN: the rating of policy % is frozen at issue; re-rate with an endorsement', OLD.id USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER policies_protect_rating BEFORE UPDATE ON policies FOR EACH ROW EXECUTE FUNCTION protect_policy_rating();

CREATE OR REPLACE FUNCTION protect_policy_transaction_rating() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.rating_result IS DISTINCT FROM OLD.rating_result OR NEW.rating_basis IS DISTINCT FROM OLD.rating_basis
    OR NEW.premium_delta_minor IS DISTINCT FROM OLD.premium_delta_minor OR NEW.net_delta_minor IS DISTINCT FROM OLD.net_delta_minor
    OR NEW.tax_delta_minor IS DISTINCT FROM OLD.tax_delta_minor OR NEW.stamp_duty_delta_minor IS DISTINCT FROM OLD.stamp_duty_delta_minor THEN
    RAISE EXCEPTION 'POLICY_RATING_FROZEN: policy transaction % is on record; its premium and rating never change', OLD.id USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER policy_transactions_protect_rating BEFORE UPDATE ON policy_transactions FOR EACH ROW EXECUTE FUNCTION protect_policy_transaction_rating();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS policies_protect_rating ON policies; DROP FUNCTION IF EXISTS protect_policy_rating;
            DROP TRIGGER IF EXISTS policy_transactions_protect_rating ON policy_transactions; DROP FUNCTION IF EXISTS protect_policy_transaction_rating;');
        Schema::table('product_versions', fn (Blueprint $t) => $t->dropColumn('endorsement_uses_current_tariff'));
        DB::statement('ALTER TABLE policy_transactions DROP CONSTRAINT IF EXISTS policy_transactions_rating_basis_valid');
        Schema::table('policy_transactions', fn (Blueprint $t) => $t->dropColumn(['stamp_duty_delta_minor', 'rating_result', 'rating_basis']));
        DB::statement('DROP INDEX IF EXISTS policies_one_per_proposal');
        foreach (['policies_premium_valid', 'policies_rating_complete', 'policies_issue_basis_valid', 'policies_premium_received_reference'] as $constraint) {
            DB::statement("ALTER TABLE policies DROP CONSTRAINT IF EXISTS {$constraint}");
        }
        Schema::table('policies', function (Blueprint $t): void {
            $t->dropForeign(['quotation_id']);
            $t->dropForeign(['proposal_id']);
            $t->dropForeign(['cover_note_id']);
            $t->dropColumn(['quotation_id', 'proposal_id', 'cover_note_id', 'risk_inputs', 'risk_keys', 'rating_result', 'rating_plan_code', 'rating_plan_version',
                'special_terms', 'stamp_duty_minor', 'issue_basis', 'premium_received_reference']);
        });
        DB::statement('ALTER TABLE policies ADD CONSTRAINT policies_premium_valid CHECK (net_premium_minor >= 0 AND tax_minor >= 0 AND gross_premium_minor = net_premium_minor + tax_minor)');
        DB::table('account_roles')->where('code', 'stamp_duty_payable')->delete();
    }
};
