<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Design §2.4 premium_earning_ledger: unique per policy and period makes an earning rerun a no-op (§8.1).
 * `kind` extends the design's unique(policy, period): a cancellation catch-up may land in a period that was
 * already earned, and is a second, distinct row for that period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('premium_earning_ledger', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('policy_id');
            $t->uuid('period_id')->index();
            $t->string('kind')->default('scheduled');
            $t->bigInteger('earned_minor');
            $t->uuid('run_id')->nullable();
            $t->timestampTz('created_at');
            $t->unique(['policy_id', 'period_id', 'kind']);
        });
        DB::statement("ALTER TABLE premium_earning_ledger ADD CONSTRAINT premium_earning_ledger_kind_valid CHECK (kind IN ('scheduled','cancellation_catch_up'))");
        RowLevelSecurity::enable('premium_earning_ledger');
    }

    public function down(): void
    {
        Schema::dropIfExists('premium_earning_ledger');
    }
};
