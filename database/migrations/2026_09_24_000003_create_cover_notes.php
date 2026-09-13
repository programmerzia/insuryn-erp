<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 design §2 step 3 cover notes (slice R6): temporary evidence of cover for an approved proposal, with its own branch-coded number and a validity capped
 * per product class (OPEN 2, A-93). Status active → superseded (by the policy, R7) | cancelled (reason) | expired (nightly); an expired note can still be
 * superseded when the policy is issued. At most one active cover note per proposal. No accounting (D-33).
 * Permissions cover_note.issue (branch officer, so branch manager) and cover_note.cancel (branch manager), A-94.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cover_notes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('branch_id');
            $t->uuid('proposal_id');
            $t->string('number', 64);
            $t->string('class_code', 32);
            $t->date('valid_from');
            $t->date('valid_to');
            $t->string('status', 16)->default('active');
            $t->uuid('issued_by');
            $t->timestampTz('issued_at');
            $t->uuid('superseded_by_policy_id')->nullable();
            $t->timestampTz('superseded_at')->nullable();
            $t->uuid('cancelled_by')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->timestampTz('expired_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status', 'valid_to']);
            $t->foreign('proposal_id')->references('id')->on('proposals');
            $t->foreign('class_code')->references('code')->on('product_classes');
        });
        DB::statement("ALTER TABLE cover_notes ADD CONSTRAINT cover_notes_status_valid CHECK (status IN ('active','superseded','cancelled','expired'))");
        DB::statement('ALTER TABLE cover_notes ADD CONSTRAINT cover_notes_range_valid CHECK (valid_to >= valid_from)');
        DB::statement("ALTER TABLE cover_notes ADD CONSTRAINT cover_notes_cancel_reason CHECK (status <> 'cancelled' OR (cancel_reason IS NOT NULL AND length(trim(cancel_reason)) > 0))");
        DB::statement("ALTER TABLE cover_notes ADD CONSTRAINT cover_notes_superseded_policy CHECK (status <> 'superseded' OR superseded_by_policy_id IS NOT NULL)");
        DB::statement("CREATE UNIQUE INDEX cover_notes_one_active_per_proposal ON cover_notes (tenant_id, proposal_id) WHERE status = 'active'");
        RowLevelSecurity::enable('cover_notes');

        DB::table('permissions')->insertOrIgnore([
            ['code' => 'cover_note.issue', 'description' => 'Issue cover notes for approved proposals'],
            ['code' => 'cover_note.cancel', 'description' => 'Cancel cover notes, with a reason'],
        ]);
        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            foreach (DB::table('roles')->whereIn('code', ['branch_officer', 'branch_manager'])->pluck('id') as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => (string) $roleId, 'permission_code' => 'cover_note.issue']);
            }
            foreach (DB::table('roles')->where('code', 'branch_manager')->pluck('id') as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => (string) $roleId, 'permission_code' => 'cover_note.cancel']);
            }
        });
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(fn () => DB::table('role_permissions')->whereIn('permission_code', ['cover_note.issue', 'cover_note.cancel'])->delete());
        DB::table('permissions')->whereIn('code', ['cover_note.issue', 'cover_note.cancel'])->delete();
        Schema::dropIfExists('cover_notes');
    }
};
