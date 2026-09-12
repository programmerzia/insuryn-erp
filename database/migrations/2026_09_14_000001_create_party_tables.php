<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Design §2.4 parties, party_roles, party_bank_accounts, agents (slice 1A.1). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('kind');
            $t->string('display_name');
            $t->string('tax_id')->nullable();
            $t->string('status')->default('active');
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE parties ADD CONSTRAINT parties_kind_valid CHECK (kind IN ('individual','organization'))");

        Schema::create('party_roles', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('party_id');
            $t->string('role');
            $t->jsonb('role_data')->nullable();
            $t->string('status')->default('active');
            $t->timestampsTz();
            $t->unique(['party_id', 'role']);
        });
        DB::statement("ALTER TABLE party_roles ADD CONSTRAINT party_roles_role_valid CHECK (role IN ('customer','policyholder','insured','beneficiary','agent','vendor','reinsurer','employee'))");

        Schema::create('party_bank_accounts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('party_id')->index();
            $t->string('bank_name');
            $t->string('account_no_masked');
            $t->text('account_no_enc');
            $t->boolean('is_default')->default(false);
            $t->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX party_bank_accounts_one_default ON party_bank_accounts (party_id) WHERE is_default');

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
        DB::statement('ALTER TABLE agents ADD CONSTRAINT agents_not_own_parent CHECK (parent_agent_id IS DISTINCT FROM id)');

        foreach (['parties', 'party_roles', 'party_bank_accounts', 'agents'] as $table) {
            RowLevelSecurity::enable($table);
        }
        DB::table('permissions')->insertOrIgnore([
            ['code' => 'party.manage', 'description' => 'Create and edit parties, roles and bank accounts'],
            ['code' => 'agent.manage', 'description' => 'Create and edit agents and their hierarchy'],
        ]);
    }

    public function down(): void
    {
        foreach (['agents', 'party_bank_accounts', 'party_roles', 'parties'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('permissions')->whereIn('code', ['party.manage', 'agent.manage'])->delete();
    }
};
