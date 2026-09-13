<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distribution design note §1 "commission_entries stays and gains scheme_id, rule_id, level_code, hierarchy_snapshot" (slice D5). One trigger
 * now produces one entry per beneficiary (the seller's direct commission and each override), so the replay guards become per beneficiary.
 * `conditional` entries wait for the statement run (persistency). `compliance_exceptions` records who was not paid and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_entries', function (Blueprint $t): void {
            $t->uuid('scheme_id')->nullable();
            $t->uuid('rule_id')->nullable();
            $t->string('beneficiary_role')->default('direct');
            $t->string('level_code', 32)->nullable();
            $t->jsonb('hierarchy_snapshot')->nullable();
        });
        DB::statement("ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_role_valid CHECK (beneficiary_role IN ('direct','override'))");
        DB::statement('ALTER TABLE commission_entries DROP CONSTRAINT commission_entries_status_valid');
        DB::statement("ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_status_valid CHECK (status IN ('conditional','accrued','approved','paid','reversed'))");
        DB::statement('DROP INDEX commission_entries_earned_once');
        DB::statement("CREATE UNIQUE INDEX commission_entries_earned_once ON commission_entries (receipt_allocation_id, agent_id) WHERE kind = 'earned' AND receipt_allocation_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX commission_entries_written_once ON commission_entries (policy_transaction_id, agent_id, rule_id) WHERE kind = 'earned' AND receipt_allocation_id IS NULL");
        DB::statement("CREATE UNIQUE INDEX commission_entries_reversal_once ON commission_entries (receipt_allocation_id, agent_id) WHERE kind = 'clawback' AND receipt_allocation_id IS NOT NULL");

        Schema::create('compliance_exceptions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('producer_id')->nullable()->index();
            $t->uuid('policy_id')->nullable()->index();
            $t->string('source_type');
            $t->uuid('source_id');
            $t->string('reason_code');
            $t->text('message');
            $t->date('occurred_on');
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('resolved_at')->nullable();
            $t->unique(['source_type', 'source_id', 'producer_id', 'reason_code']);
        });
        RowLevelSecurity::enable('compliance_exceptions');
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_exceptions');
        DB::statement('DROP INDEX IF EXISTS commission_entries_reversal_once');
        DB::statement('DROP INDEX IF EXISTS commission_entries_written_once');
        DB::statement('DROP INDEX commission_entries_earned_once');
        DB::statement("CREATE UNIQUE INDEX commission_entries_earned_once ON commission_entries (receipt_allocation_id) WHERE kind = 'earned'");
        DB::statement('ALTER TABLE commission_entries DROP CONSTRAINT commission_entries_status_valid');
        DB::statement("ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_status_valid CHECK (status IN ('accrued','approved','paid','reversed'))");
        DB::statement('ALTER TABLE commission_entries DROP CONSTRAINT commission_entries_role_valid');
        Schema::table('commission_entries', fn (Blueprint $t) => $t->dropColumn(['scheme_id', 'rule_id', 'beneficiary_role', 'level_code', 'hierarchy_snapshot']));
    }
};
