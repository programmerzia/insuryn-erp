<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One tenant, one entity, one branch, a starter insurance COA, LOCAL book, FY2026 periods (Jul-Jun),
 * and role→account mappings so the golden fixtures can post. Reused by tests (see tests/Pest.php).
 */
final class DemoTenantSeeder extends Seeder
{
    /** @return array{tenant_id:string, entity_id:string, branch_id:string, book_id:string, accounts:array<string,string>} */
    public function run(string $slug = 'demo'): array
    {
        $tenantId = (string) Str::uuid7();
        DB::table('tenants')->insert(['id' => $tenantId, 'name' => 'Demo Insurance', 'slug' => $slug, 'timezone' => 'Asia/Dhaka',
            'fiscal_year_start_month' => 7, 'base_currency' => 'BDT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return TenantContext::run($tenantId, function () use ($tenantId): array {
            $entityId = (string) Str::uuid7();
            DB::table('legal_entities')->insert(['id' => $entityId, 'tenant_id' => $tenantId, 'code' => 'DEMO', 'name' => 'Demo Insurance Ltd', 'base_currency' => 'BDT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $branchId = (string) Str::uuid7();
            DB::table('branches')->insert(['id' => $branchId, 'tenant_id' => $tenantId, 'entity_id' => $entityId, 'code' => 'HO', 'name' => 'Head Office', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $bookId = (string) Str::uuid7();
            DB::table('books')->insert(['id' => $bookId, 'tenant_id' => $tenantId, 'code' => 'LOCAL', 'name' => 'Local GAAP', 'is_primary' => true]);

            $start = CarbonImmutable::parse('2026-07-01');
            for ($p = 1; $p <= 12; $p++) {
                $s = $start->addMonths($p - 1);
                DB::table('fiscal_periods')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'entity_id' => $entityId, 'book_id' => $bookId,
                    'year' => 2026, 'period' => $p, 'starts' => $s->toDateString(), 'ends' => $s->endOfMonth()->toDateString(), 'status' => 'open']);
            }

            // code => [name, type, normal_side, role, is_control, subledger]
            $coa = [
                '1010' => ['Bank - Main', 'asset', 'debit', 'bank_main', false, null],
                '1020' => ['Bank - Clearing', 'asset', 'debit', 'bank_clearing', false, null],
                '1025' => ['Cheques in Clearing', 'asset', 'debit', 'cheques_in_clearing', false, null], // gap fix GA-14
                '5600' => ['Bank Charges', 'expense', 'debit', 'bank_charges', false, null], // gap fix GA-14
                '1100' => ['Premium Receivable', 'asset', 'debit', 'premium_receivable', true, 'premium'],
                '1150' => ['Agent Collections Receivable', 'asset', 'debit', 'agent_receivable', true, 'agent'],
                '1160' => ['Producer Advances', 'asset', 'debit', 'producer_advances', false, null],
                '1200' => ['Recovery Receivable', 'asset', 'debit', 'recovery_receivable', false, null],
                '2010' => ['Suspense - Unallocated Receipts', 'liability', 'credit', 'suspense_receipts', true, 'suspense'],
                '2100' => ['Unearned Premium Reserve', 'liability', 'credit', 'unearned_premium', false, null],
                '2150' => ['Premium Tax Payable', 'liability', 'credit', 'premium_tax_payable', false, null],
                '2155' => ['Stamp Duty Payable', 'liability', 'credit', 'stamp_duty_payable', false, null],
                '2160' => ['Customer Refunds Payable', 'liability', 'credit', 'customer_refund_payable', false, null],
                '2200' => ['Outstanding Claims Reserve', 'liability', 'credit', 'claims_outstanding', true, 'claims'],
                '2210' => ['Claims Payable', 'liability', 'credit', 'claims_payable', true, 'claims'],
                '2220' => ['IBNR Provision', 'liability', 'credit', 'ibnr_provision', false, null], // market gap G5
                '2300' => ['Commission Payable', 'liability', 'credit', 'commission_payable', true, 'commission'],
                '2310' => ['Withholding Tax on Commission', 'liability', 'credit', 'commission_withholding_payable', false, null],
                '2400' => ['Salaries Payable', 'liability', 'credit', 'salary_payable', false, null],
                '2500' => ['Accounts Payable', 'liability', 'credit', 'accounts_payable', false, null],
                '1310' => ['Input VAT Receivable', 'asset', 'debit', 'input_vat_receivable', false, null], // slice 2.3 accounts payable
                '2510' => ['VAT Deducted at Source Payable', 'liability', 'credit', 'vat_deducted_at_source_payable', false, null],
                '2520' => ['Tax Deducted at Source from Suppliers', 'liability', 'credit', 'supplier_tax_withheld_payable', false, null],
                '5700' => ['General and Administrative Expenses', 'expense', 'debit', 'ap_expense', false, null],
                '2410' => ['Employee Tax Payable', 'liability', 'credit', 'employee_tax_payable', false, null],
                '2420' => ['Provident Fund Payable', 'liability', 'credit', 'pf_payable', false, null],
                '3100' => ['Retained Earnings', 'equity', 'credit', 'retained_earnings', false, null],
                '4100' => ['Premium Income', 'income', 'credit', 'premium_income', false, null],
                '4200' => ['Claims Recovery Income', 'income', 'credit', 'claims_recovery_income', false, null],
                '4900' => ['FX Gain/Loss', 'income', 'credit', 'fx_gain_loss', false, null],
                '5100' => ['Claims Incurred', 'expense', 'debit', 'claims_expense', false, null],
                '5110' => ['Claims Incurred - IBNR', 'expense', 'debit', 'claims_ibnr_expense', false, null], // market gap G5
                '5200' => ['Commission Expense', 'expense', 'debit', 'commission_expense', false, null],
                '5300' => ['Salaries', 'expense', 'debit', 'salary_expense', false, null],
                '5310' => ['Employer PF Contribution', 'expense', 'debit', 'employer_pf_expense', false, null],
                '5450' => ['Premium Written Off', 'expense', 'debit', 'premium_written_off', false, null], // gap fixes W7 (GA-24)
                '5320' => ['Festival Bonus', 'expense', 'debit', 'bonus_expense', false, null], // People/Payroll MVP
                '5900' => ['Rounding Differences', 'expense', 'debit', 'rounding_difference', false, null],
                // Reinsurance MVP (G4).
                '1410' => ['Reinsurers\' Share of Unearned Premium', 'asset', 'debit', 'ri_unearned_premium', false, null],
                '1420' => ['Reinsurers\' Share of Outstanding Claims', 'asset', 'debit', 'ri_outstanding_claims', false, null],
                '1430' => ['Reinsurance Claims Recoverable', 'asset', 'debit', 'ri_claims_recoverable', false, null],
                '2610' => ['Due to Reinsurers', 'liability', 'credit', 'ri_payable', false, null],
                '4310' => ['Reinsurance Commission Income', 'income', 'credit', 'ri_commission_income', false, null],
                '5410' => ['Reinsurance Premium Ceded', 'expense', 'debit', 'ri_premium_ceded', false, null],
                // Design addendum v2 §B.7 fixed assets: the default accounts an asset class starts with.
                '1600' => ['Fixed Assets at Cost', 'asset', 'debit', 'fixed_asset_cost', false, null],
                '1690' => ['Accumulated Depreciation', 'asset', 'credit', 'accumulated_depreciation', false, null],
                '5710' => ['Depreciation', 'expense', 'debit', 'depreciation_expense', false, null],
                '4800' => ['Gain/Loss on Disposal of Assets', 'income', 'credit', 'asset_disposal_gain_loss', false, null],
            ];
            $accounts = [];
            foreach ($coa as $code => [$name, $type, $side, $role, $control, $sub]) {
                $id = (string) Str::uuid7();
                $accounts[$role] = $id;
                DB::table('accounts')->insert(['id' => $id, 'tenant_id' => $tenantId, 'entity_id' => $entityId, 'code' => $code, 'name' => $name, 'type' => $type,
                    'normal_side' => $side, 'is_postable' => true, 'is_control' => $control, 'control_subledger' => $sub, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('account_role_mappings')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'entity_id' => $entityId, 'book_id' => $bookId,
                    'role_code' => $role, 'account_id' => $id, 'effective_from' => '2026-01-01', 'effective_to' => null]);
                if ($sub !== null) {
                    DB::table('subledger_controls')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'entity_id' => $entityId,
                        'book_id' => $bookId, 'subledger' => $sub, 'control_account_role' => $role]);
                }
            }
            foreach (PermissionsSeeder::SOD as $i => [$a, $b, $appliesTo]) {
                DB::table('sod_rules')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => 'SOD'.($i + 1), 'permission_a' => $a, 'permission_b' => $b, 'mode' => 'block', 'applies_to' => $appliesTo]);
            }
            return ['tenant_id' => $tenantId, 'entity_id' => $entityId, 'branch_id' => $branchId, 'book_id' => $bookId, 'accounts' => $accounts];
        });
    }
}
