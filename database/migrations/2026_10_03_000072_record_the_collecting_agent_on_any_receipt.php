<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GA-38: agents also collect cheques and mobile-money payments, so a receipt of any channel may name the agent who collected it. Only cash stays owed by the
 * agent until deposited (AGENT_CASH_COLLECTED to agent receivable); another channel reaches the company's bank and posts as any receipt (A-194).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE receipts DROP CONSTRAINT IF EXISTS receipts_agent_collections_cash');
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_agent_collections_cash CHECK (collected_by_agent_id IS NULL OR channel = 'cash')");
    }
};
