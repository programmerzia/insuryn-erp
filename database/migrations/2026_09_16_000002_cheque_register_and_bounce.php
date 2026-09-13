<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Slice 1C.2 (spec §4 cheque register and bounce handling): cheque details, dated reversals and bounces so subledgers stay reconcilable. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $t): void {
            $t->string('cheque_no')->nullable();
            $t->string('cheque_bank')->nullable();
            $t->date('cheque_date')->nullable();
            $t->date('bounced_on')->nullable();
            $t->text('bounce_reason')->nullable();
        });
        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_cheque_details CHECK (channel <> 'cheque' OR (cheque_no IS NOT NULL AND cheque_bank IS NOT NULL AND cheque_date IS NOT NULL))");
        // A cheque is presented once; after it bounces the same cheque may be presented again.
        DB::statement("CREATE UNIQUE INDEX receipts_cheque_presented_once ON receipts (tenant_id, lower(cheque_bank), cheque_no) WHERE channel = 'cheque' AND status <> 'bounced'");

        Schema::table('receipt_allocations', function (Blueprint $t): void {
            $t->date('reversed_on')->nullable();
        });
        Schema::table('suspense_items', function (Blueprint $t): void {
            $t->date('bounced_on')->nullable();
        });
        DB::statement('ALTER TABLE suspense_items DROP CONSTRAINT suspense_items_status_valid');
        DB::statement("ALTER TABLE suspense_items ADD CONSTRAINT suspense_items_status_valid CHECK (status IN ('open','allocated','refunded','written_off','bounced'))");
        DB::statement("CREATE UNIQUE INDEX commission_entries_bounce_clawback_once ON commission_entries (receipt_allocation_id) WHERE kind = 'clawback'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS commission_entries_bounce_clawback_once');
        DB::statement('ALTER TABLE suspense_items DROP CONSTRAINT suspense_items_status_valid');
        DB::statement("ALTER TABLE suspense_items ADD CONSTRAINT suspense_items_status_valid CHECK (status IN ('open','allocated','refunded','written_off'))");
        Schema::table('suspense_items', fn (Blueprint $t) => $t->dropColumn('bounced_on'));
        Schema::table('receipt_allocations', fn (Blueprint $t) => $t->dropColumn('reversed_on'));
        DB::statement('DROP INDEX IF EXISTS receipts_cheque_presented_once');
        DB::statement('ALTER TABLE receipts DROP CONSTRAINT receipts_cheque_details');
        Schema::table('receipts', fn (Blueprint $t) => $t->dropColumn(['cheque_no', 'cheque_bank', 'cheque_date', 'bounced_on', 'bounce_reason']));
    }
};
