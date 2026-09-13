<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Slice 1C.3 (spec §4 agent cash collection with deposit reconciliation). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $t): void {
            $t->uuid('collected_by_agent_id')->nullable()->index();
        });
        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_agent_collections_cash CHECK (collected_by_agent_id IS NULL OR channel = 'cash')");

        Schema::create('agent_deposits', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('branch_id');
            $t->uuid('agent_id')->index();
            $t->string('number');
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->date('deposited_on');
            $t->uuid('bank_account_id')->nullable();
            $t->string('reference')->nullable();
            $t->uuid('recorded_by');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
        });
        DB::statement('ALTER TABLE agent_deposits ADD CONSTRAINT agent_deposits_amount_positive CHECK (amount_minor > 0)');
        RowLevelSecurity::enable('agent_deposits');
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_deposits');
        DB::statement('ALTER TABLE receipts DROP CONSTRAINT receipts_agent_collections_cash');
        Schema::table('receipts', fn (Blueprint $t) => $t->dropColumn('collected_by_agent_id'));
    }
};
