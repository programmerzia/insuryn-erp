<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('code'); // LOCAL|IFRS|MGMT
            $t->string('name');
            $t->boolean('is_primary')->default(false);
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('fiscal_periods', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('book_id');
            $t->unsignedSmallInteger('year');
            $t->unsignedSmallInteger('period');
            $t->date('starts');
            $t->date('ends');
            $t->string('status')->default('open'); // open|soft_locked|locked
            $t->uuid('locked_by')->nullable();
            $t->timestampTz('locked_at')->nullable();
            $t->unique(['entity_id', 'book_id', 'year', 'period']);
        });

        Schema::create('accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->string('code');
            $t->string('name');
            $t->string('type'); // asset|liability|equity|income|expense
            $t->string('normal_side'); // debit|credit
            $t->uuid('parent_id')->nullable();
            $t->boolean('is_postable')->default(true);
            $t->boolean('is_control')->default(false);
            $t->string('control_subledger')->nullable();
            $t->char('currency', 3)->nullable();
            $t->string('status')->default('active');
            $t->timestampsTz();
            $t->unique(['entity_id', 'code']);
        });

        Schema::create('account_roles', function (Blueprint $t) {
            $t->string('code')->primary();
            $t->string('description')->nullable();
        });

        Schema::create('account_role_mappings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('book_id');
            $t->string('role_code');
            $t->uuid('account_id');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->unique(['entity_id', 'book_id', 'role_code', 'effective_from'], 'arm_scope_unique');
        });

        Schema::create('dimension_requirements', function (Blueprint $t) {
            $t->uuid('tenant_id');
            $t->string('event_type');
            $t->string('dimension_code');
            $t->boolean('required')->default(true);
            $t->primary(['tenant_id', 'event_type', 'dimension_code']);
        });

        Schema::create('accounting_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->string('event_type')->index();
            $t->string('source_type');
            $t->uuid('source_id');
            $t->unsignedInteger('source_version')->default(1);
            $t->string('idempotency_key');
            $t->timestampTz('occurred_at');
            $t->date('transaction_date');
            $t->date('effective_date');
            $t->char('currency', 3);
            $t->jsonb('payload');
            $t->jsonb('dimensions');
            $t->string('status')->default('received')->index(); // received|queued|posting|posted|failed|rejected|superseded
            $t->text('failure_reason')->nullable();
            $t->uuid('journal_batch_id')->nullable();
            $t->timestampTz('created_at');
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['source_type', 'source_id']);
        });

        Schema::create('journal_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('accounting_event_id')->nullable();
            $t->timestampTz('created_at');
        });

        Schema::create('journals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('book_id');
            $t->uuid('batch_id')->nullable();
            $t->string('number')->nullable();
            $t->uuid('period_id');
            $t->date('transaction_date');
            $t->date('posting_date');
            $t->date('effective_date');
            $t->string('status')->default('draft')->index(); // draft|pending_approval|approved|queued|posting|posted|failed|cancelled|reversed
            $t->string('kind')->default('system'); // system|manual|reversal|adjustment|opening
            $t->uuid('reverses_journal_id')->nullable();
            $t->uuid('corrects_journal_id')->nullable();
            $t->uuid('reversed_by_journal_id')->nullable();
            $t->uuid('original_transaction_id')->nullable();
            $t->text('reason')->nullable();
            $t->string('source_type')->nullable();
            $t->uuid('source_id')->nullable();
            $t->string('posting_rule_code')->nullable();
            $t->unsignedInteger('posting_rule_version')->nullable();
            $t->char('currency', 3);
            $t->decimal('fx_rate', 18, 8)->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('approved_by')->nullable();
            $t->timestampTz('posted_at')->nullable();
            $t->string('description')->nullable();
            $t->timestampsTz();
            $t->unique(['entity_id', 'book_id', 'number']);
            $t->index(['source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('journal_id');
            $t->unsignedSmallInteger('line_no');
            $t->uuid('account_id');
            $t->string('side'); // debit|credit
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->bigInteger('base_amount_minor');
            $t->string('role_code')->nullable();
            $t->string('memo')->nullable();
            $t->uuid('dim_branch')->nullable();
            $t->uuid('dim_product')->nullable();
            $t->string('dim_lob')->nullable();
            $t->string('dim_channel')->nullable();
            $t->uuid('dim_agent')->nullable();
            $t->uuid('dim_policy')->nullable();
            $t->uuid('dim_claim')->nullable();
            $t->uuid('dim_cost_centre')->nullable();
            $t->uuid('dim_employee')->nullable();
            $t->uuid('dim_customer')->nullable();
            $t->uuid('dim_reinsurer')->nullable();
            $t->jsonb('dims_ext')->nullable();
            $t->index(['tenant_id', 'account_id', 'journal_id']);
            $t->index('dim_policy');
            $t->index('dim_claim');
            $t->index('dim_agent');
            $t->index('dim_customer');
        });
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_amount_positive CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_side_valid CHECK (side IN ('debit','credit'))");

        Schema::create('subledger_controls', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->string('subledger'); // premium|claims|commission|customer|agent|bank|ap|ar|suspense
            $t->string('control_account_role');
            $t->uuid('book_id');
            // A subledger may reconcile to several control accounts (design §6.1: claims → claims_outstanding and claims_payable).
            $t->unique(['entity_id', 'book_id', 'subledger', 'control_account_role']);
        });

        Schema::create('reconciliation_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->string('subledger');
            $t->uuid('period_id');
            $t->timestampTz('run_at');
            $t->bigInteger('subledger_balance_minor');
            $t->bigInteger('gl_balance_minor');
            $t->bigInteger('variance_minor');
            $t->string('status'); // clean|variance|resolved
            $t->uuid('resolved_by')->nullable();
            $t->text('resolution_note')->nullable();
        });

        Schema::create('reconciliation_exceptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('run_id')->index();
            $t->string('object_type');
            $t->uuid('object_id');
            $t->bigInteger('expected_minor');
            $t->bigInteger('actual_minor');
            $t->text('note')->nullable();
            $t->string('status')->default('open');
        });

        Schema::create('period_close_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('period_id');
            $t->string('status')->default('running');
            $t->uuid('started_by');
            $t->timestampTz('started_at');
            $t->timestampTz('completed_at')->nullable();
        });

        Schema::create('period_close_tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('close_run_id')->index();
            $t->string('code');
            $t->unsignedSmallInteger('order_no');
            $t->jsonb('depends_on')->nullable();
            $t->string('owner_role');
            $t->string('status')->default('pending'); // pending|running|done|blocked|skipped
            $t->jsonb('result')->nullable();
            $t->uuid('done_by')->nullable();
            $t->timestampTz('done_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['period_close_tasks','period_close_runs','reconciliation_exceptions','reconciliation_runs','subledger_controls','journal_lines','journals','journal_batches','accounting_events','dimension_requirements','account_role_mappings','account_roles','accounts','fiscal_periods','books'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
