<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Design §5.5 claims with immutable reserve history (spec §4 "Reserve history immutable"), payments and recoveries — slice 1B.1. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('branch_id');
            $t->uuid('policy_id')->index();
            $t->string('number');
            $t->date('loss_date');
            $t->date('reported_on');
            $t->text('description');
            $t->string('status');
            $t->bigInteger('reserve_minor')->default(0);
            $t->integer('reserve_version')->default(0);
            $t->char('currency', 3);
            $t->uuid('created_by');
            $t->date('closed_on')->nullable();
            $t->text('status_reason')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
        });
        DB::statement("ALTER TABLE claims ADD CONSTRAINT claims_status_valid CHECK (status IN ('registered','reserved','approved','paid','closed','rejected'))");
        DB::statement('ALTER TABLE claims ADD CONSTRAINT claims_reserve_valid CHECK (reserve_minor >= 0)');

        Schema::create('claim_reserves', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('claim_id');
            $t->integer('version');
            $t->bigInteger('reserve_minor');
            $t->bigInteger('delta_minor');
            $t->string('kind'); // reserve | adjustment | close_release | reject_release
            $t->text('reason');
            $t->date('recorded_on');
            $t->uuid('recorded_by');
            $t->timestampTz('created_at');
            $t->unique(['claim_id', 'version']);
        });
        DB::statement("ALTER TABLE claim_reserves ADD CONSTRAINT claim_reserves_kind_valid CHECK (kind IN ('reserve','adjustment','close_release','reject_release'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_claim_reserves() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'IMMUTABLE_RESERVE_HISTORY: claim reserve % cannot be %', OLD.id, lower(TG_OP) USING ERRCODE = 'check_violation';
END $$;

CREATE TRIGGER claim_reserves_append_only BEFORE UPDATE OR DELETE ON claim_reserves
  FOR EACH ROW EXECUTE FUNCTION protect_claim_reserves();
SQL);

        Schema::create('claim_payments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('claim_id')->index();
            $t->uuid('payee_party_id');
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('status');
            $t->date('approved_on');
            $t->uuid('approved_by');
            $t->uuid('release_requested_by')->nullable();
            $t->uuid('bank_account_id')->nullable();
            $t->uuid('released_by')->nullable();
            $t->date('paid_on')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE claim_payments ADD CONSTRAINT claim_payments_status_valid CHECK (status IN
            ('pending_approval','approved','release_requested','release_pending_approval','paid','rejected'))");
        DB::statement('ALTER TABLE claim_payments ADD CONSTRAINT claim_payments_amount_positive CHECK (amount_minor > 0)');

        Schema::create('claim_recoveries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('claim_id')->index();
            $t->string('type');
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->date('received_on');
            $t->uuid('bank_account_id')->nullable();
            $t->string('reference')->nullable();
            $t->uuid('recorded_by');
            $t->timestampTz('created_at');
        });
        DB::statement("ALTER TABLE claim_recoveries ADD CONSTRAINT claim_recoveries_type_valid CHECK (type IN ('salvage','subrogation','third_party'))");
        DB::statement('ALTER TABLE claim_recoveries ADD CONSTRAINT claim_recoveries_amount_positive CHECK (amount_minor > 0)');

        foreach (['claims', 'claim_reserves', 'claim_payments', 'claim_recoveries'] as $table) {
            RowLevelSecurity::enable($table);
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS claim_reserves_append_only ON claim_reserves; DROP FUNCTION IF EXISTS protect_claim_reserves;');
        foreach (['claim_recoveries', 'claim_payments', 'claim_reserves', 'claims'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
