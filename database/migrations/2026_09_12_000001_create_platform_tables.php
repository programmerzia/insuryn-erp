<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('timezone')->default('Asia/Dhaka');
            $t->unsignedSmallInteger('fiscal_year_start_month')->default(7);
            $t->char('base_currency', 3)->default('BDT');
            $t->string('status')->default('active');
            $t->timestampsTz();
        });

        Schema::create('legal_entities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('code');
            $t->string('name');
            $t->char('base_currency', 3);
            $t->string('status')->default('active');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->string('code');
            $t->string('name');
            $t->string('status')->default('active');
            $t->timestampsTz();
            $t->unique(['entity_id', 'code']);
        });

        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('oidc_subject')->nullable()->unique();
            $t->string('email');
            $t->string('name');
            $t->string('password')->nullable(); // local fallback until Zitadel is wired
            $t->string('status')->default('active');
            $t->rememberToken();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'email']);
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('code');
            $t->string('name');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->string('code')->primary();
            $t->string('description')->nullable();
        });

        Schema::create('role_permissions', function (Blueprint $t) {
            $t->uuid('role_id');
            $t->string('permission_code');
            $t->primary(['role_id', 'permission_code']);
        });

        Schema::create('user_roles', function (Blueprint $t) {
            $t->uuid('user_id');
            $t->uuid('role_id');
            $t->string('scope_type'); // tenant|entity|branch
            $t->uuid('scope_id');
            $t->primary(['user_id', 'role_id', 'scope_type', 'scope_id']);
        });

        Schema::create('sod_rules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('code');
            $t->string('permission_a');
            $t->string('permission_b');
            $t->string('mode')->default('block'); // block|warn
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('approval_policies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('object_type');
            $t->jsonb('condition');
            $t->jsonb('steps');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
        });

        Schema::create('approvals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('object_type');
            $t->uuid('object_id');
            $t->uuid('policy_id');
            $t->string('status')->default('pending');
            $t->unsignedSmallInteger('current_step')->default(1);
            $t->uuid('requested_by');
            $t->timestampTz('requested_at');
            $t->index(['object_type', 'object_id']);
        });

        Schema::create('approval_decisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('approval_id')->index();
            $t->unsignedSmallInteger('step_no');
            $t->uuid('decided_by');
            $t->string('decision'); // approved|rejected
            $t->text('reason')->nullable();
            $t->timestampTz('decided_at');
        });

        Schema::create('audit_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->timestampTz('occurred_at')->index();
            $t->uuid('actor_user_id')->nullable();
            $t->string('actor_type')->default('user'); // user|system|integration
            $t->string('action');
            $t->string('object_type');
            $t->uuid('object_id');
            $t->jsonb('before')->nullable();
            $t->jsonb('after')->nullable();
            $t->text('reason')->nullable();
            $t->uuid('request_id')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent')->nullable();
            $t->index(['object_type', 'object_id']);
        });

        Schema::create('number_sequences', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('branch_id')->nullable();
            $t->string('doc_type');
            $t->unsignedSmallInteger('fiscal_year');
            $t->string('prefix');
            $t->unsignedBigInteger('next_no')->default(1);
            $t->timestampsTz();
            $t->unique(['entity_id', 'branch_id', 'doc_type', 'fiscal_year'], 'number_sequences_scope_unique');
        });

        Schema::create('document_numbers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('sequence_id');
            $t->string('number');
            $t->string('status'); // reserved|used|voided
            $t->string('object_type')->nullable();
            $t->uuid('object_id')->nullable();
            $t->uuid('reserved_by')->nullable();
            $t->timestampTz('reserved_at');
            $t->timestampTz('used_at')->nullable();
            $t->text('void_reason')->nullable();
            $t->unique(['sequence_id', 'number']);
        });

        Schema::create('tax_rates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('jurisdiction');
            $t->string('tax_type');
            $t->unsignedInteger('rate_bp'); // basis points, 1500 = 15%
            $t->boolean('inclusive')->default(false);
            $t->boolean('withholding')->default(false);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
        });

        Schema::create('outbox', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('message_type');
            $t->jsonb('payload');
            $t->timestampTz('created_at');
            $t->timestampTz('relayed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        foreach (['outbox','tax_rates','document_numbers','number_sequences','audit_events','approval_decisions','approvals','approval_policies','sod_rules','user_roles','role_permissions','permissions','roles','users','branches','legal_entities','tenants'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
