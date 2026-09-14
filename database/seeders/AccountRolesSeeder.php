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
        // Slice 2.3 accounts payable (addendum v2 B.2.5)
        'ap_expense' => 'Expense on supplier bill lines (account taken from the bill line)',
        'input_vat_receivable' => 'Recoverable VAT on supplier bills',
        'vat_deducted_at_source_payable' => 'VAT deducted at source from suppliers (VDS), owed to the government',
        'supplier_tax_withheld_payable' => 'Income tax deducted at source from suppliers (TDS/AIT), owed to the government',
        'dac_asset' => 'Deferred acquisition cost (LATER)',
        'claims_outstanding' => 'Outstanding claims reserve',
        'claims_expense' => 'Claims incurred',
        'claims_payable' => 'Approved claims awaiting payment',
        'claims_recovery_income' => 'Salvage / subrogation recoveries',
        'claims_ibnr_expense' => 'Claims incurred – IBNR (incurred but not reported)', // market gap G5
        'ibnr_provision' => 'IBNR provision (claims incurred but not reported)', // market gap G5
        'recovery_receivable' => 'Accrued recoveries',
        'rounding_difference' => 'Rounding residual',
        'fx_gain_loss' => 'FX gain/loss',
        'salary_expense' => 'Salaries',
        'employer_pf_expense' => 'Employer PF contribution',
        'salary_payable' => 'Net salary payable',
        'employee_tax_payable' => 'Employee income tax withheld',
        'pf_payable' => 'Provident fund payable',
        'bonus_expense' => 'Festival bonus and other bonuses paid through payroll (People/Payroll MVP)',
        'retained_earnings' => 'Retained earnings',
        'premium_written_off' => 'Unpaid premium written off as too small to collect', // gap fixes W7 (GA-24, A-234)
        // Reinsurance MVP (G4).
        'ri_premium_ceded' => 'Reinsurance premium ceded (expense)',
        'ri_payable' => 'Due to reinsurers: premium ceded less commission (control per reinsurer)',
        'ri_commission_income' => 'Reinsurance commission income',
        'ri_unearned_premium' => 'Reinsurers\' share of unearned premium (asset)',
        'ri_outstanding_claims' => 'Reinsurers\' share of outstanding claims (asset)',
        'ri_claims_recoverable' => 'Claims recoverable from reinsurers',
        // Design addendum v2 §B.7 fixed assets (migration 2026_10_05_000271)
        'fixed_asset_cost' => 'Fixed assets at cost (per asset class)',
        'accumulated_depreciation' => 'Accumulated depreciation on fixed assets (per asset class)',
        'depreciation_expense' => 'Depreciation charged to profit and loss (per asset class)',
        'asset_disposal_gain_loss' => 'Gain or loss on disposal of fixed assets',
    ];

    public function run(): void
    {
        foreach (self::ROLES as $code => $desc) {
            DB::table('account_roles')->updateOrInsert(['code' => $code], ['description' => $desc]);
        }
    }
}
