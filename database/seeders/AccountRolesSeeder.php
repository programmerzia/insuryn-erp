<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Semantic account roles referenced by posting rules (design §3.4). Global, not tenant-scoped. */
final class AccountRolesSeeder extends Seeder
{
    public const ROLES = [
        'bank_main' => 'Main bank account (overridden per bank account LATER)',
        'bank_clearing' => 'Payment gateway / clearing',
        'cheques_in_clearing' => 'Cheques received and not yet cleared by the bank', // gap fix GA-14
        'bank_charges' => 'Bank charges', // gap fix GA-14
        'suspense_receipts' => 'Unallocated receipts',
        'premium_receivable' => 'Premium receivable control',
        'unearned_premium' => 'Unearned premium reserve',
        'premium_income' => 'Earned premium income',
        'premium_tax_payable' => 'VAT/levies on premium',
        'stamp_duty_payable' => 'Stamp duty on policies, owed to the government', // Phase 3 R7 (D-37)
        'customer_refund_payable' => 'Refunds due to customers',
        'agent_receivable' => 'Agent cash collections not yet deposited',
        'commission_expense' => 'Commission expense',
        'commission_payable' => 'Commission payable control',
        'commission_withholding_payable' => 'Withholding tax on commission',
        'producer_advances' => 'Advances paid to producers, recovered from their commission (Distribution D6)',
        'accounts_payable' => 'Amounts owed to suppliers and producers paid through payables (Distribution D6)',
        'dac_asset' => 'Deferred acquisition cost (LATER)',
        'claims_outstanding' => 'Outstanding claims reserve',
        'claims_expense' => 'Claims incurred',
        'claims_payable' => 'Approved claims awaiting payment',
        'claims_recovery_income' => 'Salvage / subrogation recoveries',
        'recovery_receivable' => 'Accrued recoveries',
        'rounding_difference' => 'Rounding residual',
        'fx_gain_loss' => 'FX gain/loss',
        'salary_expense' => 'Salaries',
        'employer_pf_expense' => 'Employer PF contribution',
        'salary_payable' => 'Net salary payable',
        'employee_tax_payable' => 'Employee income tax withheld',
        'pf_payable' => 'Provident fund payable',
        'retained_earnings' => 'Retained earnings',
        'premium_written_off' => 'Unpaid premium written off as too small to collect', // gap fixes W7 (GA-24, A-234)
    ];

    public function run(): void
    {
        foreach (self::ROLES as $code => $desc) {
            DB::table('account_roles')->updateOrInsert(['code' => $code], ['description' => $desc]);
        }
    }
}
