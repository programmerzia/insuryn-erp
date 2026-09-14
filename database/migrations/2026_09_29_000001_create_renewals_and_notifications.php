<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 design §4 renewals and §6 "Expiry register queue" (slice R9).
 *
 * - `quotations.renewal_of_policy_id`: a renewal quotation names the policy it renews (design `previous_policy_id` is `policies.renewal_of_policy_id`, D-38);
 *   frozen once the quotation leaves draft; at most one open (draft or issued) renewal quotation per policy. A renewal quotation offered by the nightly run
 *   has no creating user (`created_by` null, audited as the system); every other quotation keeps its creator (CHECK).
 * - `expiry_register` (tenant): one row per policy coming up for renewal (unique per policy), rebuilt idempotently: bucket, days left, status
 *   upcoming | renewal_offered | renewed | lapsed | not_renewed, the renewal quotation, the renewal policy, and why it was not renewed (D-42).
 * - `renewal_notices` (tenant): one notice or reminder per policy and offset (idempotent), with the generated renewal notice document.
 * - `notifications` (tenant, Platform): every message handed to a notification channel (D-41) — log-only adapter now, gateways LATER.
 * - Permission renewal.manage (branch officer and branch manager templates, A-126); existing tenants' roles get it here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $t): void {
            $t->uuid('renewal_of_policy_id')->nullable();
            $t->foreign('renewal_of_policy_id')->references('id')->on('policies');
        });
        DB::statement('ALTER TABLE quotations ALTER COLUMN created_by DROP NOT NULL');
        DB::statement('ALTER TABLE quotations ADD CONSTRAINT quotations_creator CHECK (created_by IS NOT NULL OR renewal_of_policy_id IS NOT NULL)');
        DB::statement("CREATE UNIQUE INDEX quotations_one_open_renewal ON quotations (tenant_id, renewal_of_policy_id) WHERE renewal_of_policy_id IS NOT NULL AND status IN ('draft','issued')");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_quotation_renewal_link() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.status <> 'draft' AND NEW.renewal_of_policy_id IS DISTINCT FROM OLD.renewal_of_policy_id THEN
    RAISE EXCEPTION 'QUOTATION_FROZEN: quotation % is %, the policy it renews cannot change', OLD.id, OLD.status USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER quotations_protect_renewal_link BEFORE UPDATE ON quotations FOR EACH ROW EXECUTE FUNCTION protect_quotation_renewal_link();
SQL);

        Schema::create('expiry_register', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('branch_id');
            $t->uuid('policy_id');
            $t->string('policy_number', 64);
            $t->uuid('product_id');
            $t->string('class_code', 32)->nullable();
            $t->uuid('agent_id')->nullable();
            $t->uuid('policyholder_party_id');
            $t->date('expiry');
            $t->boolean('rated');
            $t->unsignedSmallInteger('bucket')->nullable();
            $t->integer('days_left');
            $t->date('as_of');
            $t->string('status', 16)->default('upcoming');
            $t->uuid('renewal_quotation_id')->nullable();
            $t->string('quote_problem_code', 64)->nullable();
            $t->text('quote_problem')->nullable();
            $t->uuid('renewal_policy_id')->nullable();
            $t->string('reason', 32)->nullable();
            $t->text('reason_note')->nullable();
            $t->uuid('closed_by')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'policy_id']);
            $t->index(['tenant_id', 'status', 'expiry']);
            $t->foreign('policy_id')->references('id')->on('policies');
            $t->foreign('renewal_quotation_id')->references('id')->on('quotations');
            $t->foreign('renewal_policy_id')->references('id')->on('policies');
        });
        DB::statement("ALTER TABLE expiry_register ADD CONSTRAINT expiry_register_status_valid CHECK (status IN ('upcoming','renewal_offered','renewed','lapsed','not_renewed'))");
        DB::statement("ALTER TABLE expiry_register ADD CONSTRAINT expiry_register_renewed_policy CHECK (status <> 'renewed' OR renewal_policy_id IS NOT NULL)");
        DB::statement("ALTER TABLE expiry_register ADD CONSTRAINT expiry_register_reason CHECK (status NOT IN ('lapsed','not_renewed') OR reason IS NOT NULL)");
        DB::statement("ALTER TABLE expiry_register ADD CONSTRAINT expiry_register_other_note CHECK (reason IS DISTINCT FROM 'other' OR (reason_note IS NOT NULL AND length(trim(reason_note)) > 0))");
        RowLevelSecurity::enable('expiry_register');

        Schema::create('renewal_notices', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('expiry_register_id');
            $t->uuid('policy_id');
            $t->unsignedSmallInteger('offset_days');
            $t->string('kind', 16);
            $t->uuid('quotation_id');
            $t->uuid('generated_document_id')->nullable();
            $t->uuid('stored_document_id')->nullable();
            $t->jsonb('notification_ids');
            $t->date('sent_on');
            $t->timestampTz('created_at');
            $t->unique(['tenant_id', 'policy_id', 'offset_days']);
            $t->foreign('expiry_register_id')->references('id')->on('expiry_register');
            $t->foreign('quotation_id')->references('id')->on('quotations');
        });
        DB::statement("ALTER TABLE renewal_notices ADD CONSTRAINT renewal_notices_kind_valid CHECK (kind IN ('notice','reminder'))");
        RowLevelSecurity::enable('renewal_notices');

        Schema::create('notifications', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('channel', 16);
            $t->string('adapter', 32);
            $t->string('recipient_type', 32);
            $t->uuid('recipient_id')->nullable();
            $t->string('recipient_name', 255);
            $t->string('recipient_address', 255)->nullable();
            $t->string('subject_type', 64);
            $t->uuid('subject_id');
            $t->string('template_code', 32)->nullable();
            $t->string('title', 255);
            $t->text('body');
            $t->uuid('stored_document_id')->nullable();
            $t->string('idempotency_key', 191);
            $t->string('status', 16);
            $t->text('failure')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->timestampTz('created_at');
            $t->unique(['tenant_id', 'idempotency_key', 'channel']);
            $t->index(['tenant_id', 'subject_type', 'subject_id']);
        });
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_status_valid CHECK (status IN ('sent','failed'))");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_channel_valid CHECK (channel IN ('email','sms'))");
        RowLevelSecurity::enable('notifications');

        DB::table('permissions')->insertOrIgnore(['code' => 'renewal.manage', 'description' => 'Work the expiry register: offer renewal quotations and record why a policy was not renewed']);
        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            foreach (DB::table('roles')->whereIn('code', ['branch_officer', 'branch_manager'])->pluck('id') as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => (string) $roleId, 'permission_code' => 'renewal.manage']);
            }
        });
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(fn () => DB::table('role_permissions')->where('permission_code', 'renewal.manage')->delete());
        DB::table('permissions')->where('code', 'renewal.manage')->delete();
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('renewal_notices');
        Schema::dropIfExists('expiry_register');
        DB::unprepared('DROP TRIGGER IF EXISTS quotations_protect_renewal_link ON quotations; DROP FUNCTION IF EXISTS protect_quotation_renewal_link;');
        DB::statement('DROP INDEX IF EXISTS quotations_one_open_renewal');
        DB::statement('ALTER TABLE quotations DROP CONSTRAINT IF EXISTS quotations_creator');
        Schema::table('quotations', function (Blueprint $t): void {
            $t->dropForeign(['renewal_of_policy_id']);
            $t->dropColumn('renewal_of_policy_id');
        });
    }
};
