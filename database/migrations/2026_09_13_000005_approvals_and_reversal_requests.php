<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Design §2.1 approvals keep the request context (e.g. a reopen reason) and when they were decided;
 * §2.3 "every reversal requires reason, created_by and approval": reversals are requested before they run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $t): void {
            $t->jsonb('context')->nullable();
            $t->timestampTz('decided_at')->nullable();
        });
        DB::statement("ALTER TABLE approvals ADD CONSTRAINT approvals_status_valid CHECK (status IN ('pending','approved','rejected'))");
        DB::statement("ALTER TABLE approval_decisions ADD CONSTRAINT approval_decisions_decision_valid CHECK (decision IN ('approved','rejected'))");
        Schema::table('approval_policies', fn (Blueprint $t) => $t->index(['object_type', 'effective_from']));
        Schema::table('approval_decisions', fn (Blueprint $t) => $t->unique(['approval_id', 'step_no']));

        Schema::create('journal_reversal_requests', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('journal_id')->index();
            $t->date('on_date');
            $t->text('reason');
            $t->uuid('requested_by');
            $t->uuid('approval_id')->nullable();
            $t->string('status')->default('pending');
            $t->uuid('decided_by')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->uuid('reversal_journal_id')->nullable();
            $t->timestampTz('created_at');
        });
        DB::statement("ALTER TABLE journal_reversal_requests ADD CONSTRAINT journal_reversal_requests_status_valid CHECK (status IN ('pending','rejected','executed'))");
        RowLevelSecurity::enable('journal_reversal_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_reversal_requests');
        Schema::table('approval_decisions', fn (Blueprint $t) => $t->dropUnique(['approval_id', 'step_no']));
        Schema::table('approval_policies', fn (Blueprint $t) => $t->dropIndex(['object_type', 'effective_from']));
        DB::statement('ALTER TABLE approval_decisions DROP CONSTRAINT IF EXISTS approval_decisions_decision_valid');
        DB::statement('ALTER TABLE approvals DROP CONSTRAINT IF EXISTS approvals_status_valid');
        Schema::table('approvals', fn (Blueprint $t) => $t->dropColumn(['context', 'decided_at']));
    }
};
