<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Commission plans and design §2.4 commission_entries — slice 1A.7. */
return new class extends Migration
{
    public function up(): void
    {
        // ASSUMPTION: A-6 — a plan is one flat rate (tiers, term years and hierarchy overrides are not specified).
        Schema::create('commission_plans', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('code');
            $t->string('name');
            $t->integer('rate_bp');
            $t->string('withholding_jurisdiction')->nullable();
            $t->string('withholding_tax_type')->nullable();
            $t->string('status')->default('active');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });
        DB::statement('ALTER TABLE commission_plans ADD CONSTRAINT commission_plans_rate_valid CHECK (rate_bp BETWEEN 0 AND 10000)');
        DB::statement('ALTER TABLE commission_plans ADD CONSTRAINT commission_plans_withholding_pair CHECK ((withholding_jurisdiction IS NULL) = (withholding_tax_type IS NULL))');

        Schema::create('commission_entries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('branch_id');
            $t->uuid('agent_id')->index();
            $t->uuid('policy_id')->index();
            $t->uuid('receipt_allocation_id')->nullable();
            $t->uuid('policy_transaction_id')->nullable();
            $t->uuid('commission_plan_id')->nullable();
            $t->string('kind');
            $t->bigInteger('base_minor');
            $t->integer('rate_bp')->nullable();
            $t->bigInteger('amount_minor');
            $t->bigInteger('withholding_minor');
            $t->char('currency', 3);
            $t->date('earned_on');
            $t->string('status')->default('accrued');
            $t->uuid('statement_id')->nullable();
            $t->timestampTz('created_at');
        });
        DB::statement("ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_kind_valid CHECK (kind IN ('earned','clawback','bonus'))");
        DB::statement("ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_status_valid CHECK (status IN ('accrued','approved','paid','reversed'))");
        DB::statement("ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_sign_valid CHECK ((kind = 'clawback') = (amount_minor < 0) AND abs(withholding_minor) <= abs(amount_minor))");
        // One earned entry per allocation and one clawback per cancellation: a replayed domain event cannot double commission.
        DB::statement("CREATE UNIQUE INDEX commission_entries_earned_once ON commission_entries (receipt_allocation_id) WHERE kind = 'earned'");
        DB::statement("CREATE UNIQUE INDEX commission_entries_clawback_once ON commission_entries (policy_transaction_id, agent_id) WHERE kind = 'clawback'");

        foreach (['commission_plans', 'commission_entries'] as $table) {
            RowLevelSecurity::enable($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('commission_plans');
    }
};
