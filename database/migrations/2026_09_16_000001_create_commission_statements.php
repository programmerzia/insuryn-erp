<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Slice 1C.1: commission payout statements (§5.6 approved → paid) and the date each entry was paid (dated commission subledger). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_statements', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('agent_id')->index();
            $t->string('number');
            $t->date('up_to');
            $t->bigInteger('gross_minor');
            $t->bigInteger('withholding_minor');
            $t->bigInteger('net_minor');
            $t->char('currency', 3);
            $t->string('status');
            $t->uuid('approved_by');
            $t->date('approved_on');
            $t->uuid('paid_by')->nullable();
            $t->date('paid_on')->nullable();
            $t->uuid('bank_account_id')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
        });
        DB::statement("ALTER TABLE commission_statements ADD CONSTRAINT commission_statements_status_valid CHECK (status IN ('approved','paid'))");
        DB::statement('ALTER TABLE commission_statements ADD CONSTRAINT commission_statements_net_positive CHECK (net_minor > 0)');
        RowLevelSecurity::enable('commission_statements');

        Schema::table('commission_entries', function (Blueprint $t): void {
            $t->date('paid_on')->nullable();
            $t->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('commission_entries', function (Blueprint $t): void {
            $t->dropIndex(['agent_id', 'status']);
            $t->dropColumn('paid_on');
        });
        Schema::dropIfExists('commission_statements');
    }
};
