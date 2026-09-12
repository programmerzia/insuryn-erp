<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Slice 1A.8: subledgers reconcile as of a date (design §6.3), so the business rows that move a control account carry the date their
 * accounting event posts on — policy_transactions.accounting_date (issue date, endorsement/cancellation effective date) and
 * receipt_allocations.posted_on (receipt value date, or the suspense allocation's posting date).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE policy_transactions ADD COLUMN accounting_date date');
        DB::statement('UPDATE policy_transactions SET accounting_date = effective_date');
        DB::statement('ALTER TABLE policy_transactions ALTER COLUMN accounting_date SET NOT NULL');
        DB::statement('ALTER TABLE receipt_allocations ADD COLUMN posted_on date');
        DB::statement('UPDATE receipt_allocations a SET posted_on = r.value_date FROM receipts r WHERE r.id = a.receipt_id');
        DB::statement('ALTER TABLE receipt_allocations ALTER COLUMN posted_on SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE receipt_allocations DROP COLUMN posted_on');
        DB::statement('ALTER TABLE policy_transactions DROP COLUMN accounting_date');
    }
};
