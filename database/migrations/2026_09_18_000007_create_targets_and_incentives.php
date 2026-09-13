<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distribution design note §1 targets and incentive_plans, §4 sales performance (slice D7). Targets per producer, branch or channel for a
 * calendar month, quarter or year and a metric; incentive plans with tiers; one award per plan, producer and period, paid as a `bonus`
 * commission entry (which has no policy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('targets', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('subject_type');
            $t->uuid('subject_id');
            $t->string('period_type');
            $t->date('period_start');
            $t->string('metric');
            $t->bigInteger('target_value');
            $t->uuid('set_by');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'subject_type', 'subject_id', 'period_type', 'period_start', 'metric']);
        });
        DB::statement("ALTER TABLE targets ADD CONSTRAINT targets_values_valid CHECK (subject_type IN ('producer','branch','channel') AND period_type IN ('monthly','quarterly','annual')
            AND metric IN ('premium','policies','persistency','collections') AND target_value > 0)");

        Schema::create('incentive_plans', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('code');
            $t->string('name');
            $t->string('period_type');
            $t->string('metric');
            $t->jsonb('tiers');
            $t->jsonb('applies_to');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('withholding_jurisdiction')->nullable();
            $t->string('withholding_tax_type')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });
        DB::statement("ALTER TABLE incentive_plans ADD CONSTRAINT incentive_plans_values_valid CHECK (period_type IN ('monthly','quarterly','annual')
            AND metric IN ('premium','policies','persistency','collections') AND (effective_to IS NULL OR effective_to > effective_from)
            AND (withholding_jurisdiction IS NULL) = (withholding_tax_type IS NULL))");

        Schema::create('incentive_awards', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('plan_id');
            $t->uuid('producer_id')->index();
            $t->date('period_start');
            $t->date('period_end');
            $t->bigInteger('target_value');
            $t->bigInteger('actual_value');
            $t->unsignedInteger('achievement_bp');
            $t->unsignedSmallInteger('tier');
            $t->bigInteger('bonus_minor');
            $t->uuid('commission_entry_id')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['plan_id', 'producer_id', 'period_start']);
            $t->foreign('plan_id')->references('id')->on('incentive_plans');
        });
        DB::statement('ALTER TABLE incentive_awards ADD CONSTRAINT incentive_awards_bonus_positive CHECK (bonus_minor > 0)');

        RowLevelSecurity::enable('targets');
        RowLevelSecurity::enable('incentive_plans');
        RowLevelSecurity::enable('incentive_awards');

        DB::statement('ALTER TABLE commission_entries ALTER COLUMN policy_id DROP NOT NULL');
        DB::statement("ALTER TABLE commission_entries ADD CONSTRAINT commission_entries_policy_unless_bonus CHECK (kind = 'bonus' OR policy_id IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE commission_entries DROP CONSTRAINT IF EXISTS commission_entries_policy_unless_bonus');
        DB::statement("DELETE FROM commission_entries WHERE kind = 'bonus'");
        DB::statement('ALTER TABLE commission_entries ALTER COLUMN policy_id SET NOT NULL');
        Schema::dropIfExists('incentive_awards');
        Schema::dropIfExists('incentive_plans');
        Schema::dropIfExists('targets');
    }
};
