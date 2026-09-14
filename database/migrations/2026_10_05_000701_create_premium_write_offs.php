<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gap fixes W7 (GA-24): writing off the small premium a cancelled policy still owes. One row per request: the amount owed when asked, the approval it waits
 * for, and what was written off when approved (PREMIUM_WRITTEN_OFF: debit `premium_written_off`, credit premium receivable). At most one request per
 * policy waits at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('premium_write_offs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('branch_id');
            $t->uuid('policy_id')->index();
            $t->bigInteger('requested_minor');
            $t->bigInteger('written_off_minor')->nullable();
            $t->char('currency', 3);
            $t->string('status', 24); // pending_approval | written_off | settled | rejected
            $t->text('reason');
            $t->uuid('approval_id')->nullable();
            $t->uuid('requested_by');
            $t->timestampTz('requested_at');
            $t->uuid('decided_by')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->date('written_off_on')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE premium_write_offs ADD CONSTRAINT premium_write_offs_status_valid CHECK (status IN ('pending_approval', 'written_off', 'settled', 'rejected'))");
        DB::statement('ALTER TABLE premium_write_offs ADD CONSTRAINT premium_write_offs_amounts_valid CHECK (requested_minor > 0 AND (written_off_minor IS NULL OR written_off_minor >= 0))');
        DB::statement("CREATE UNIQUE INDEX premium_write_offs_one_pending ON premium_write_offs (tenant_id, policy_id) WHERE status = 'pending_approval'");
        RowLevelSecurity::enable('premium_write_offs');
    }

    public function down(): void
    {
        Schema::dropIfExists('premium_write_offs');
    }
};
