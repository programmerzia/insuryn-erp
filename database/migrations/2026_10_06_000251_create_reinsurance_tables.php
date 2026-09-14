<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reinsurance MVP (market gap G4). All tables are tenant tables with RLS forced.
 *
 * - `reinsurers`: a party with role `reinsurer`, with its code, security rating, country and whether it is the state reinsurer (Sadharan Bima Corporation).
 * - `ri_treaties` + `ri_treaty_participants`: one treaty per entity, product class and underwriting year — quota share (cession %) or surplus (retention and
 *   lines) — with the commission on ceded premium and the SBC compulsory share applied first (ASSUMPTION A-252); participants share the treaty's cession.
 * - `ri_facultative_placements`: a manual placement on one policy for the part above treaty capacity.
 * - `ri_cessions`: append-only; every row is a signed movement of ceded sum insured, premium and commission for one reinsurer, from a policy transaction or a
 *   facultative placement, posted as RI_PREMIUM_CEDED. `ri_policy_positions`: the latest split of a policy's sum insured (retained, SBC, treaty, above capacity).
 * - `ri_claim_shares`: reinsurers' share of claim reserve changes (RI_CLAIM_RESERVE_CEDED) and of claim payments (RI_CLAIM_RECOVERABLE).
 * - `ri_upr_adjustments`: reinsurers' share of unearned premium at each period end (RI_UPR_ADJUSTED).
 * - `ri_statements`: the quarterly account statement per reinsurer (a snapshot).
 * - Account roles and permissions ri.manage_treaties, ri.place_facultative, ri.view with their role template grants for existing tenants.
 */
return new class extends Migration
{
    private const ROLES = [
        'ri_premium_ceded' => 'Reinsurance premium ceded (expense)',
        'ri_payable' => 'Due to reinsurers: premium ceded less commission (control per reinsurer)',
        'ri_commission_income' => 'Reinsurance commission income',
        'ri_unearned_premium' => 'Reinsurers\' share of unearned premium (asset)',
        'ri_outstanding_claims' => 'Reinsurers\' share of outstanding claims (asset)',
        'ri_claims_recoverable' => 'Claims recoverable from reinsurers',
    ];

    private const PERMISSIONS = [
        'ri.manage_treaties' => 'Set up reinsurers and reinsurance treaties',
        'ri.place_facultative' => 'Place facultative reinsurance on a policy',
        'ri.view' => 'See cessions, reinsurer statements and bordereaux',
    ];

    /** ASSUMPTION A-257: role template grants. */
    private const GRANTS = [
        'finance_manager' => ['ri.manage_treaties', 'ri.place_facultative', 'ri.view'],
        'cfo' => ['ri.manage_treaties', 'ri.place_facultative', 'ri.view'],
        'accountant' => ['ri.view'],
        'branch_manager' => ['ri.place_facultative', 'ri.view'],
        'auditor' => ['ri.view'],
    ];

    public function up(): void
    {
        Schema::create('reinsurers', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('party_id');
            $t->string('code', 32);
            $t->string('rating', 16)->nullable();
            $t->string('rating_agency', 32)->nullable();
            $t->char('country', 2);
            $t->boolean('is_state_reinsurer')->default(false);
            $t->string('status', 16)->default('active');
            $t->uuid('created_by');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'party_id']);
            $t->foreign('party_id')->references('id')->on('parties');
        });
        DB::statement("ALTER TABLE reinsurers ADD CONSTRAINT reinsurers_status_valid CHECK (status IN ('active','inactive'))");
        DB::statement('CREATE UNIQUE INDEX reinsurers_one_state_reinsurer ON reinsurers (tenant_id) WHERE is_state_reinsurer');
        RowLevelSecurity::enable('reinsurers');

        Schema::create('ri_treaties', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->string('code', 32);
            $t->string('name', 255);
            $t->string('class_code', 32);
            $t->smallInteger('underwriting_year');
            $t->date('period_from');
            $t->date('period_to');
            $t->string('type', 16);
            $t->integer('cession_bp')->nullable();
            $t->bigInteger('retention_minor')->nullable();
            $t->smallInteger('lines')->nullable();
            $t->integer('commission_bp');
            $t->integer('sbc_share_bp');
            $t->char('currency', 3);
            $t->string('status', 16)->default('active');
            $t->uuid('created_by');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
            $t->foreign('class_code')->references('code')->on('product_classes');
        });
        DB::statement("ALTER TABLE ri_treaties ADD CONSTRAINT ri_treaties_type_valid CHECK ((type = 'quota_share' AND cession_bp BETWEEN 0 AND 10000)
            OR (type = 'surplus' AND retention_minor > 0 AND lines BETWEEN 1 AND 50))");
        DB::statement("ALTER TABLE ri_treaties ADD CONSTRAINT ri_treaties_values_valid CHECK (status IN ('active','inactive') AND period_to >= period_from
            AND commission_bp BETWEEN 0 AND 10000 AND sbc_share_bp BETWEEN 0 AND 10000)");
        DB::statement("CREATE UNIQUE INDEX ri_treaties_one_active_per_class_year ON ri_treaties (tenant_id, entity_id, class_code, underwriting_year) WHERE status = 'active'");
        RowLevelSecurity::enable('ri_treaties');

        Schema::create('ri_treaty_participants', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('treaty_id');
            $t->uuid('reinsurer_id');
            $t->integer('share_bp');
            $t->timestampTz('created_at');
            $t->unique(['tenant_id', 'treaty_id', 'reinsurer_id']);
            $t->foreign('treaty_id')->references('id')->on('ri_treaties')->cascadeOnDelete();
            $t->foreign('reinsurer_id')->references('id')->on('reinsurers');
        });
        DB::statement('ALTER TABLE ri_treaty_participants ADD CONSTRAINT ri_treaty_participants_share_valid CHECK (share_bp > 0 AND share_bp <= 10000)');
        RowLevelSecurity::enable('ri_treaty_participants');

        Schema::create('ri_facultative_placements', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('policy_id');
            $t->uuid('reinsurer_id');
            $t->string('slip_reference', 64)->nullable();
            $t->integer('share_bp');
            $t->bigInteger('ceded_sum_insured_minor');
            $t->bigInteger('premium_minor');
            $t->integer('commission_bp');
            $t->bigInteger('commission_minor');
            $t->date('placed_on');
            $t->char('currency', 3);
            $t->uuid('placed_by');
            $t->timestampsTz();
            $t->foreign('policy_id')->references('id')->on('policies');
            $t->foreign('reinsurer_id')->references('id')->on('reinsurers');
        });
        DB::statement('ALTER TABLE ri_facultative_placements ADD CONSTRAINT ri_fac_values_valid CHECK (share_bp > 0 AND share_bp <= 10000 AND ceded_sum_insured_minor >= 0
            AND premium_minor > 0 AND commission_bp BETWEEN 0 AND 10000 AND commission_minor >= 0 AND commission_minor <= premium_minor)');
        RowLevelSecurity::enable('ri_facultative_placements');

        Schema::create('ri_cessions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('policy_id');
            $t->uuid('policy_transaction_id')->nullable();
            $t->uuid('facultative_placement_id')->nullable();
            $t->uuid('treaty_id')->nullable();
            $t->uuid('reinsurer_id');
            $t->string('kind', 16);
            $t->string('movement', 16);
            $t->integer('share_bp');
            $t->bigInteger('ceded_sum_insured_minor');
            $t->bigInteger('premium_minor');
            $t->bigInteger('commission_minor');
            $t->date('accounting_date');
            $t->char('currency', 3);
            $t->timestampTz('created_at');
            $t->unique(['tenant_id', 'policy_transaction_id', 'kind', 'reinsurer_id']);
            $t->index(['tenant_id', 'policy_id']);
            $t->index(['tenant_id', 'reinsurer_id', 'accounting_date']);
            $t->foreign('policy_id')->references('id')->on('policies');
            $t->foreign('reinsurer_id')->references('id')->on('reinsurers');
            $t->foreign('treaty_id')->references('id')->on('ri_treaties');
            $t->foreign('facultative_placement_id')->references('id')->on('ri_facultative_placements');
        });
        DB::statement("ALTER TABLE ri_cessions ADD CONSTRAINT ri_cessions_kind_valid CHECK (kind IN ('sbc','quota_share','surplus','facultative')
            AND movement IN ('issue','endorsement','cancellation','placement','backfill')
            AND (policy_transaction_id IS NOT NULL OR facultative_placement_id IS NOT NULL))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_ri_cessions() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'RI_CESSION_APPEND_ONLY: cession % cannot be changed or deleted; a correction is a new movement', OLD.id USING ERRCODE = '23514';
END $$;
CREATE TRIGGER ri_cessions_append_only BEFORE UPDATE OR DELETE ON ri_cessions FOR EACH ROW EXECUTE FUNCTION protect_ri_cessions();
SQL);
        RowLevelSecurity::enable('ri_cessions');

        Schema::create('ri_policy_positions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('policy_id');
            $t->uuid('treaty_id')->nullable();
            $t->bigInteger('sum_insured_minor');
            $t->bigInteger('net_premium_minor');
            $t->bigInteger('sbc_sum_insured_minor');
            $t->bigInteger('treaty_sum_insured_minor');
            $t->bigInteger('retained_sum_insured_minor');
            $t->bigInteger('above_capacity_minor');
            $t->string('note', 255)->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'policy_id']);
            $t->foreign('policy_id')->references('id')->on('policies');
        });
        RowLevelSecurity::enable('ri_policy_positions');

        Schema::create('ri_claim_shares', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('claim_id');
            $t->uuid('policy_id');
            $t->uuid('reinsurer_id');
            $t->string('kind', 16);
            $t->string('source_type', 32);
            $t->uuid('source_id');
            $t->integer('share_bp');
            $t->bigInteger('gross_minor');
            $t->bigInteger('amount_minor');
            $t->bigInteger('from_reserve_minor')->default(0);
            $t->date('recorded_on');
            $t->char('currency', 3);
            $t->timestampTz('created_at');
            $t->unique(['tenant_id', 'source_type', 'source_id', 'reinsurer_id']);
            $t->index(['tenant_id', 'claim_id']);
            $t->foreign('reinsurer_id')->references('id')->on('reinsurers');
        });
        DB::statement("ALTER TABLE ri_claim_shares ADD CONSTRAINT ri_claim_shares_kind_valid CHECK (kind IN ('reserve','recoverable'))");
        RowLevelSecurity::enable('ri_claim_shares');

        Schema::create('ri_upr_adjustments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('period_id');
            $t->uuid('branch_id');
            $t->uuid('reinsurer_id');
            $t->date('as_of');
            $t->bigInteger('unearned_minor');
            $t->bigInteger('delta_minor');
            $t->char('currency', 3);
            $t->timestampTz('created_at');
            $t->unique(['tenant_id', 'period_id', 'branch_id', 'reinsurer_id']);
            $t->foreign('reinsurer_id')->references('id')->on('reinsurers');
        });
        RowLevelSecurity::enable('ri_upr_adjustments');

        Schema::create('ri_statements', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->string('number', 64);
            $t->uuid('reinsurer_id');
            $t->smallInteger('year');
            $t->smallInteger('quarter');
            $t->date('period_from');
            $t->date('period_to');
            $t->bigInteger('opening_balance_minor');
            $t->bigInteger('premium_minor');
            $t->bigInteger('commission_minor');
            $t->bigInteger('claims_recoverable_minor');
            $t->bigInteger('closing_balance_minor');
            $t->bigInteger('outstanding_claims_share_minor');
            $t->char('currency', 3);
            $t->uuid('prepared_by');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
            $t->unique(['tenant_id', 'entity_id', 'reinsurer_id', 'year', 'quarter']);
            $t->foreign('reinsurer_id')->references('id')->on('reinsurers');
        });
        DB::statement('ALTER TABLE ri_statements ADD CONSTRAINT ri_statements_quarter_valid CHECK (quarter BETWEEN 1 AND 4)');
        RowLevelSecurity::enable('ri_statements');

        foreach (self::ROLES as $code => $description) {
            DB::table('account_roles')->insertOrIgnore(['code' => $code, 'description' => $description]);
        }
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
        });
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(fn () => DB::table('role_permissions')->whereIn('permission_code', array_keys(self::PERMISSIONS))->delete());
        DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->delete();
        foreach (['ri_statements', 'ri_upr_adjustments', 'ri_claim_shares', 'ri_policy_positions', 'ri_cessions', 'ri_facultative_placements', 'ri_treaty_participants', 'ri_treaties', 'reinsurers'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS protect_ri_cessions() CASCADE;');
        DB::table('account_roles')->whereIn('code', array_keys(self::ROLES))->delete();
    }
};
