<?php

declare(strict_types=1);

use App\Modules\Distribution\Infrastructure\LegacyAgentBackfill;
use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distribution design note §1 (slice D1): channels and producers. Phase 1 `agents` rows move into `producers` (type agent, standard agency
 * channel) with their ids, so every `agent_id` column and `dim_agent` keeps pointing at the same rows; then `agents` is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('code');
            $t->string('name');
            $t->string('type');
            $t->string('status')->default('active');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });
        DB::statement("ALTER TABLE channels ADD CONSTRAINT channels_type_valid CHECK (type IN ('agency','bdo','broker','bancassurance','partner','direct'))");
        DB::statement("ALTER TABLE channels ADD CONSTRAINT channels_status_valid CHECK (status IN ('active','inactive'))");

        Schema::create('producers', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('party_id')->unique();
            $t->string('code');
            $t->string('type');
            $t->uuid('channel_id')->index();
            $t->uuid('branch_id');
            $t->uuid('parent_agent_id')->nullable()->index();
            $t->uuid('commission_plan_id')->nullable();
            $t->uuid('employee_id')->nullable();
            $t->string('status')->default('active');
            $t->date('joined_on')->nullable();
            $t->date('terminated_on')->nullable();
            $t->text('termination_reason')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
            $t->foreign('channel_id')->references('id')->on('channels');
        });
        DB::statement("ALTER TABLE producers ADD CONSTRAINT producers_type_valid CHECK (type IN ('agent','agency_org','bdo','broker','partner'))");
        DB::statement("ALTER TABLE producers ADD CONSTRAINT producers_status_valid CHECK (status IN ('applicant','active','suspended','terminated'))");
        DB::statement('ALTER TABLE producers ADD CONSTRAINT producers_not_own_parent CHECK (parent_agent_id IS DISTINCT FROM id)');
        DB::statement("ALTER TABLE producers ADD CONSTRAINT producers_termination_dated CHECK (status <> 'terminated' OR terminated_on IS NOT NULL)");

        RowLevelSecurity::enable('channels');
        RowLevelSecurity::enable('producers');

        LegacyAgentBackfill::copy('agents');
        Schema::drop('agents');

        DB::table('permissions')->where('code', 'agent.manage')->update(['description' => 'Create and edit producers (agents, agencies, BDOs, brokers) and their hierarchy']);
    }

    public function down(): void
    {
        Schema::create('agents', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('party_id')->unique();
            $t->string('code');
            $t->uuid('parent_agent_id')->nullable()->index();
            $t->uuid('branch_id');
            $t->uuid('commission_plan_id')->nullable();
            $t->string('status')->default('active');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });
        RowLevelSecurity::enable('agents');
        RowLevelSecurity::forEachTenant(fn (string $tenantId) => DB::insert("INSERT INTO agents (id, tenant_id, party_id, code, parent_agent_id, branch_id, commission_plan_id, status, created_at, updated_at)
            SELECT id, tenant_id, party_id, code, parent_agent_id, branch_id, commission_plan_id, CASE WHEN status = 'active' THEN 'active' ELSE 'inactive' END, created_at, updated_at
            FROM producers WHERE tenant_id = ? AND type = 'agent'", [$tenantId]));
        Schema::drop('producers');
        Schema::drop('channels');
    }
};
