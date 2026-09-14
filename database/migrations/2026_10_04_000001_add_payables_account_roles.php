<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Slice 2.3 accounts payable (addendum v2 §B.2.5): the account roles the supplier bill rules post to, in the global catalogue. Existing tenants map
 * them from Accounting → Account roles (the unmapped-roles banner lists them); new charts of accounts get them from the template.
 */
return new class extends Migration
{
    private const ROLES = [
        'ap_expense' => 'Expense on supplier bill lines (account taken from the bill line)',
        'input_vat_receivable' => 'Recoverable VAT on supplier bills',
        'vat_deducted_at_source_payable' => 'VAT deducted at source from suppliers (VDS), owed to the government',
        'supplier_tax_withheld_payable' => 'Income tax deducted at source from suppliers (TDS/AIT), owed to the government',
    ];

    public function up(): void
    {
        foreach (self::ROLES as $code => $description) {
            DB::table('account_roles')->updateOrInsert(['code' => $code], ['description' => $description]);
        }
    }

    public function down(): void
    {
        DB::table('account_roles')->whereIn('code', array_keys(self::ROLES))->delete();
    }
};
