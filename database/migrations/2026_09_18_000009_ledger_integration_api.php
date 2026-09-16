<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Insuryn Ledger API (docs/plan/api-accounting-v1.md slice 1): external systems submit accounting events through
 * HTTP; each submission is a source row (non-negotiable #8) linked to the kernel accounting_event.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_kind_valid');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_kind_valid CHECK (kind IN ('staff','portal','integration'))");

        if (! Schema::hasTable('external_event_intakes')) {
            Schema::create('external_event_intakes', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id');
                $t->uuid('entity_id');
                $t->string('idempotency_key');
                $t->string('event_type');
                $t->date('transaction_date');
                $t->date('effective_date');
                $t->char('currency', 3);
                $t->jsonb('payload');
                $t->jsonb('dimensions');
                $t->string('source_type');
                $t->string('source_id');
                $t->string('source_number')->nullable();
                $t->uuid('accounting_event_id')->nullable();
                $t->uuid('submitted_by')->nullable();
                $t->timestampTz('created_at');
                $t->unique(['tenant_id', 'idempotency_key']);
                $t->index(['tenant_id', 'accounting_event_id']);
            });
            RowLevelSecurity::enable('external_event_intakes');
        }
    }

    public function down(): void
    {
        RowLevelSecurity::disable('external_event_intakes');
        Schema::dropIfExists('external_event_intakes');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_kind_valid');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_kind_valid CHECK (kind IN ('staff','portal'))");
    }
};
