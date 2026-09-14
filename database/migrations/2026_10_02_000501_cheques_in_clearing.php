<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gap fix GA-14: cheques sit in "cheques in clearing" until the bank clears them.
 * - Account roles `cheques_in_clearing` (cheques received, not yet credited by the bank) and `bank_charges` (what the bank deducts, such as the charge
 *   for a returned cheque). Existing tenants map them in Accounting → Account roles; until `cheques_in_clearing` is mapped their cheques post straight to
 *   the bank as before (ASSUMPTION A-215), so posted history and running practice are untouched.
 * - `receipts.in_clearing` (the receipt was taken into clearing), `cleared_on` (the day the bank credited it) and `bounce_charge_minor` (the bank's
 *   charge recorded with a bounce).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('account_roles')->insertOrIgnore([
            ['code' => 'cheques_in_clearing', 'description' => 'Cheques received and not yet cleared by the bank'],
            ['code' => 'bank_charges', 'description' => 'Bank charges'],
        ]);

        Schema::table('receipts', function (Blueprint $t): void {
            $t->boolean('in_clearing')->default(false);
            $t->date('cleared_on')->nullable();
            $t->bigInteger('bounce_charge_minor')->default(0);
        });
        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_clearing_valid CHECK ((cleared_on IS NULL OR (in_clearing AND channel = 'cheque')) AND (NOT in_clearing OR channel = 'cheque') AND bounce_charge_minor >= 0)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE receipts DROP CONSTRAINT IF EXISTS receipts_clearing_valid');
        Schema::table('receipts', function (Blueprint $t): void {
            $t->dropColumn(['in_clearing', 'cleared_on', 'bounce_charge_minor']);
        });
        DB::table('account_roles')->whereIn('code', ['cheques_in_clearing', 'bank_charges'])->delete();
    }
};
