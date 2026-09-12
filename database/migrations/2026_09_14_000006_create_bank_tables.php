<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Design §2.4 bank_accounts, bank_statement_lines, bank_matches — slice 1A.6. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('gl_account_id');
            $t->string('bank_name');
            $t->string('account_no_masked');
            $t->char('currency', 3);
            $t->string('status')->default('active');
            $t->timestampsTz();
            $t->unique(['entity_id', 'gl_account_id']);
        });
        DB::statement("ALTER TABLE bank_accounts ADD CONSTRAINT bank_accounts_status_valid CHECK (status IN ('active','closed'))");

        Schema::create('bank_statement_lines', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('bank_account_id');
            $t->date('posted_on');
            $t->bigInteger('amount_minor'); // signed: positive = money in
            $t->string('reference')->nullable();
            $t->string('description')->nullable();
            $t->jsonb('raw');
            $t->char('line_hash', 64);
            $t->string('source_file')->nullable();
            $t->string('match_status')->default('unmatched');
            $t->text('explanation')->nullable();
            $t->uuid('explained_by')->nullable();
            $t->uuid('imported_by')->nullable();
            $t->timestampTz('imported_at');
            $t->unique(['bank_account_id', 'line_hash']);
            $t->index(['bank_account_id', 'match_status', 'posted_on']);
        });
        DB::statement("ALTER TABLE bank_statement_lines ADD CONSTRAINT bank_statement_lines_status_valid CHECK (match_status IN ('unmatched','matched','explained'))");
        DB::statement('ALTER TABLE bank_statement_lines ADD CONSTRAINT bank_statement_lines_amount_nonzero CHECK (amount_minor <> 0)');

        Schema::create('bank_matches', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('statement_line_id')->index();
            $t->uuid('journal_line_id')->unique(); // a journal line is matched to at most one statement line
            $t->uuid('matched_by')->nullable();
            $t->string('method');
            $t->smallInteger('confidence')->nullable();
            $t->timestampTz('matched_at');
        });
        DB::statement("ALTER TABLE bank_matches ADD CONSTRAINT bank_matches_method_valid CHECK (method IN ('auto','manual'))");

        foreach (['bank_accounts', 'bank_statement_lines', 'bank_matches'] as $table) {
            RowLevelSecurity::enable($table);
        }
    }

    public function down(): void
    {
        foreach (['bank_matches', 'bank_statement_lines', 'bank_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
