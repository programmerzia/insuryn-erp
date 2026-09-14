<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Finance\FixedAssets\Application\FixedAssetService;
use App\Modules\Finance\FixedAssets\Domain\DepreciationCalculator;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design addendum v2 §B.6–B.8 in the Part A demo (Padma General Insurance), run inside the story before August is closed, so the August close posts
 * the month's depreciation itself (ASSUMPTION A-280, demo placeholders: names, suppliers and amounts are illustrative):
 * - Fixed assets: five classes on their own GL accounts; 21 assets at Head Office and Chattogram brought in with the opening balances at the 31 July 2026
 *   cut-over (acquired 2024–2026, accumulated depreciation to that day in the opening journal), four bought in August and September on credit, and an
 *   old desktop batch sold in August. August's depreciation is posted by the close; September's is left to preview and post.
 * - Budgets: operating expense accounts; July–September salaries, rent, utilities, marketing and IT posted per branch (accrued to payables); the FY2026
 *   operating budget per account × branch × month prepared by the accountant and approved by the finance manager, so September shows its variances.
 * - Petty cash: Head Office (20,000) and Chattogram (10,000) floats held by the branch manager, twelve September vouchers, the Head Office float replenished.
 */
final class FinanceModulesDemoSeeder
{
    private const CUT_OVER = '2026-07-31';

    /** code => [name, method, life months | yearly rate %, cost account, accumulated account] */
    private const CLASSES = [
        'FURN' => ['Furniture and fixtures', 'straight_line', 120, ['1610', 'Furniture and Fixtures'], ['1611', 'Accumulated Depreciation - Furniture and Fixtures']],
        'IT' => ['Computers and IT equipment', 'straight_line', 36, ['1620', 'Computers and IT Equipment'], ['1621', 'Accumulated Depreciation - Computers and IT Equipment']],
        'VEH' => ['Motor vehicles', 'reducing_balance', 20, ['1630', 'Motor Vehicles'], ['1631', 'Accumulated Depreciation - Motor Vehicles']],
        'OFFEQ' => ['Office equipment', 'straight_line', 60, ['1640', 'Office Equipment'], ['1641', 'Accumulated Depreciation - Office Equipment']],
        'LHI' => ['Leasehold improvements', 'straight_line', 60, ['1650', 'Leasehold Improvements'], ['1651', 'Accumulated Depreciation - Leasehold Improvements']],
    ];

    /** [branch HO|CTG, class, description, acquired on, cost BDT, location, custodian, supplier] */
    private const OPENING_ASSETS = [
        ['HO', 'LHI', 'Head office interior fit-out, Gulshan Avenue', '2024-01-05', 3_800_000, 'Gulshan head office', 'Admin department', 'Interior Craft Ltd, Dhaka'],
        ['HO', 'FURN', 'Open-plan workstations (20 seats)', '2024-01-15', 620_000, 'Gulshan head office, 4th floor', 'Admin department', 'Otobi Ltd, Dhaka'],
        ['HO', 'IT', 'Dell PowerEdge R750 server', '2024-01-20', 850_000, 'Server room', 'IT department', 'Ryans Computers, Dhaka'],
        ['HO', 'IT', 'Dell OptiPlex desktops (4)', '2024-02-01', 260_000, 'Accounts section', 'Accounts department', 'Ryans Computers, Dhaka'],
        ['HO', 'FURN', 'Executive desk and chair set, MD office', '2024-02-10', 185_000, 'MD office', 'Managing Director', 'Hatil Furniture, Dhaka'],
        ['HO', 'FURN', 'Conference table, 12 seats', '2024-03-05', 240_000, 'Board room', 'Company Secretary', 'Hatil Furniture, Dhaka'],
        ['HO', 'VEH', 'Toyota Premio 2022 (DHA-METRO-GA-27-4410)', '2024-04-01', 4_250_000, 'Head office car park', 'Managing Director', 'Navana Toyota, Dhaka'],
        ['HO', 'OFFEQ', 'Split air conditioners (8)', '2024-05-10', 720_000, 'Gulshan head office', 'Admin department', 'Walton Plaza, Dhaka'],
        ['HO', 'IT', 'Cisco switch and FortiGate firewall', '2024-06-18', 420_000, 'Server room', 'IT department', 'Smart Technologies (BD) Ltd'],
        ['HO', 'OFFEQ', 'Standby generator 60 kVA', '2024-07-22', 1_350_000, 'Basement', 'Admin department', 'Energypac Power, Dhaka'],
        ['CTG', 'FURN', 'Branch counters and customer seating', '2024-09-01', 310_000, 'Agrabad branch', 'Branch manager', 'Otobi Ltd, Chattogram'],
        ['CTG', 'LHI', 'Agrabad branch fit-out', '2025-01-10', 1_650_000, 'Agrabad branch', 'Branch manager', 'Port City Interiors, Chattogram'],
        ['HO', 'IT', 'HP ProBook laptops (10)', '2025-01-12', 1_150_000, 'Underwriting and claims', 'IT department', 'Smart Technologies (BD) Ltd'],
        ['HO', 'FURN', 'Steel filing cabinets (12)', '2025-02-20', 96_000, 'Records room', 'Admin department', 'Nadia Steel, Dhaka'],
        ['HO', 'VEH', 'Toyota Hiace microbus (DHA-METRO-CHA-53-1187)', '2025-03-15', 5_600_000, 'Head office car park', 'Transport pool', 'Navana Toyota, Dhaka'],
        ['CTG', 'IT', 'Desktop computers (6)', '2025-04-10', 390_000, 'Agrabad branch', 'Branch manager', 'Computer Source, Chattogram'],
        ['CTG', 'OFFEQ', 'Split air conditioners (4)', '2025-05-20', 360_000, 'Agrabad branch', 'Branch manager', 'Walton Plaza, Chattogram'],
        ['HO', 'OFFEQ', 'CCTV and access control system', '2025-06-04', 210_000, 'Gulshan head office', 'Admin department', 'Smart Technologies (BD) Ltd'],
        ['HO', 'IT', 'Canon multifunction printer', '2025-08-07', 145_000, 'Gulshan head office, 3rd floor', 'Admin department', 'Canon Bangladesh'],
        ['CTG', 'VEH', 'Honda CB Hornet motorcycles (2)', '2025-10-05', 380_000, 'Agrabad branch', 'Field officers', 'Bangladesh Honda, Chattogram'],
        ['CTG', 'IT', 'Lenovo laptops (2)', '2026-02-15', 240_000, 'Agrabad branch', 'Branch manager', 'Computer Source, Chattogram'],
    ];

    /** Bought after the cut-over, on credit: [branch, class, description, acquired on, cost BDT, location, custodian, supplier, invoice] */
    private const NEW_ASSETS = [
        ['HO', 'IT', 'Lenovo ThinkPad E14 laptops (5)', '2026-08-10', 575_000, 'Finance department', 'Accounts department', 'Ryans Computers, Dhaka', 'RC-2026-08814'],
        ['CTG', 'OFFEQ', 'Konica Minolta bizhub photocopier', '2026-08-18', 265_000, 'Agrabad branch', 'Branch manager', 'Konica Minolta Business Solutions, Chattogram', 'KMB-CTG-4471'],
        ['HO', 'FURN', 'Ergonomic chairs (15)', '2026-09-03', 172_500, 'Gulshan head office, 3rd floor', 'Admin department', 'Otobi Ltd, Dhaka', 'OTB-DHK-19022'],
        ['CTG', 'IT', 'HP LaserJet Pro printers (2)', '2026-09-08', 96_000, 'Agrabad branch', 'Branch manager', 'Computer Source, Chattogram', 'CS-5532'],
    ];

    /** @var array<string, string> branch code → id */
    private array $branches = [];

    /** @param array<string, string> $users role code → user id */
    public function beforeAugustClose(string $entityId, string $headOfficeId, string $chattogramId, string $bankAccountId, array $users): void
    {
        $this->branches = ['HO' => $headOfficeId, 'CTG' => $chattogramId];
        $this->fixedAssets($entityId, $bankAccountId, $users);
        $this->budgets($entityId, $users);
        $this->pettyCash($entityId, $bankAccountId, $users);
    }

    /** September vouchers: [float, date, paid to, for, account code, BDT] */
    private const VOUCHERS = [
        ['HO', '2026-09-01', 'Nahar Stationery, Gulshan-1', 'A4 paper and toner for the accounts section', '5440', 1_850],
        ['HO', '2026-09-02', 'Pathao courier', 'Policy documents to Motijheel customers', '5450', 640],
        ['HO', '2026-09-03', 'Rahman Tea Stall', 'Tea and snacks for the underwriting meeting', '5460', 920],
        ['HO', '2026-09-06', 'CNG auto-rickshaw', 'Surveyor visit to Tejgaon claim site', '5450', 450],
        ['HO', '2026-09-08', 'Bismillah Electric, Gulshan', 'Tube lights and switch repair, 3rd floor', '5470', 2_300],
        ['HO', '2026-09-09', 'Nahar Stationery, Gulshan-1', 'Receipt books and envelopes', '5440', 1_420],
        ['HO', '2026-09-12', 'Uber', 'Cheque deposit run to City Bank Gulshan', '5450', 380],
        ['CTG', '2026-09-02', 'Agrabad Photocopy Centre', 'Photocopies of claim files', '5440', 560],
        ['CTG', '2026-09-04', 'CNG auto-rickshaw', 'Branch officer visit to Khatunganj warehouse', '5450', 700],
        ['CTG', '2026-09-07', 'Mezban House, Agrabad', 'Lunch for visiting surveyor', '5460', 1_250],
        ['CTG', '2026-09-09', 'Chittagong Sanitary Store', 'Washroom tap repair', '5470', 980],
        ['CTG', '2026-09-11', 'Sundarban Courier', 'Documents to head office', '5450', 320],
    ];

    /**
     * Petty cash: Head Office and Chattogram floats (20,000 and 10,000) held by the branch manager from 1 September, a dozen September vouchers, and the
     * Head Office float replenished — asked for by the accountant, approved and paid by the finance manager.
     *
     * @param array<string, string> $users
     */
    private function pettyCash(string $entityId, string $bankAccountId, array $users): void
    {
        $pettyCash = app(\App\Modules\Finance\Expenses\Application\PettyCashService::class);
        $gl = $this->account($entityId, '1040');
        $floats = [];
        foreach (['HO' => ['PC-HO', 'Head office petty cash', 20_000], 'CTG' => ['PC-CTG', 'Chattogram branch petty cash', 10_000]] as $branch => [$code, $name, $limit]) {
            $floats[$branch] = $pettyCash->createFloat($entityId, $this->branches[$branch], $code, $name, $users['branch_manager'], $limit * 100, $gl, $bankAccountId,
                CarbonImmutable::parse('2026-09-01'), $users['finance_manager']);
        }
        foreach ([true, false] as $beforeReplenishment) {
            foreach (self::VOUCHERS as [$branch, $date, $payee, $description, $code, $amount]) {
                if (($date <= '2026-09-10') === $beforeReplenishment) {
                    $pettyCash->spend($floats[$branch] ?? '', CarbonImmutable::parse($date), $payee, $description, $this->account($entityId, (string) $code), $amount * 100, $users['branch_manager']);
                }
            }
            if ($beforeReplenishment) {
                $replenishment = $pettyCash->requestReplenishment($floats['HO'] ?? '', $bankAccountId, $users['accountant'], CarbonImmutable::parse('2026-09-10'));
                $pettyCash->approveReplenishment($replenishment, CarbonImmutable::parse('2026-09-11'), $users['finance_manager']);
            }
        }
    }

    /** Operating expense accounts the budget and petty cash use: code => name (5300 Salaries exists in the chart). */
    private const EXPENSE_ACCOUNTS = ['5400' => 'Office Rent', '5410' => 'Electricity, Gas and Water', '5420' => 'Marketing and Advertisement', '5430' => 'IT and Software Expenses',
        '5440' => 'Printing and Stationery', '5450' => 'Conveyance and Travel', '5460' => 'Entertainment', '5470' => 'Repairs and Maintenance'];

    /** Monthly budget per branch (BDT): account code => [HO, CTG]. Salaries double in March (Eid-ul-Fitr festival bonus). */
    private const BUDGET = ['5300' => [1_850_000, 720_000], '5400' => [650_000, 220_000], '5410' => [120_000, 55_000], '5420' => [250_000, 90_000], '5430' => [180_000, 45_000],
        '5440' => [4_000, 1_500], '5450' => [2_500, 1_200], '5460' => [1_500, 1_000], '5470' => [3_000, 1_000]];

    /** Posted expense by month (BDT), billed on credit: month => branch => account code => amount. September shows the variances. */
    private const ACTUALS = [
        '2026-07-28' => ['HO' => ['5300' => 1_850_000, '5400' => 650_000, '5410' => 116_400, '5420' => 238_000, '5430' => 176_500], 'CTG' => ['5300' => 720_000, '5400' => 220_000, '5410' => 53_900, '5420' => 85_000, '5430' => 44_000]],
        '2026-08-28' => ['HO' => ['5300' => 1_850_000, '5400' => 650_000, '5410' => 124_800, '5420' => 262_500, '5430' => 169_000], 'CTG' => ['5300' => 720_000, '5400' => 220_000, '5410' => 57_200, '5420' => 88_000, '5430' => 47_500]],
        '2026-09-10' => ['HO' => ['5300' => 1_850_000, '5400' => 650_000, '5410' => 128_500, '5420' => 340_000, '5430' => 150_000], 'CTG' => ['5300' => 735_000, '5400' => 220_000, '5410' => 49_800, '5420' => 60_000, '5430' => 52_500]],
    ];

    /** @param array<string, string> $users */
    private function budgets(string $entityId, array $users): void
    {
        $accounts = ['5300' => $this->account($entityId, '5300')];
        foreach (self::EXPENSE_ACCOUNTS as $code => $name) {
            $accounts[$code] = $this->newAccount($entityId, (string) $code, $name, 'debit', 'expense');
        }
        // Actuals: the month's salaries, rent, utilities, marketing and IT bills per branch, accrued against accounts payable (accountant prepares, finance manager approves).
        $journals = app(ManualJournalService::class);
        $payable = $this->account($entityId, '2500');
        foreach (self::ACTUALS as $date => $byBranch) {
            $lines = [];
            $total = 0;
            foreach ($byBranch as $branch => $amounts) {
                foreach ($amounts as $code => $amount) {
                    $lines[] = new ManualJournalLine($accounts[$code], Side::Debit, $amount * 100, ['branch' => $this->branches[$branch]], self::EXPENSE_ACCOUNTS[$code] ?? 'Salaries');
                    $lines[] = new ManualJournalLine($payable, Side::Credit, $amount * 100, ['branch' => $this->branches[$branch]], 'Payable');
                    $total += $amount;
                }
            }
            $month = CarbonImmutable::parse($date);
            $journal = $journals->create(new ManualJournalRequest($entityId, $month, 'Monthly operating expenses '.$month->format('F Y'), JournalKind::Manual,
                'Salaries, office rent, utilities, marketing and IT bills for Head Office and Chattogram', 'BDT', $lines), $users['accountant']);
            $journals->submit($journal->id, $users['accountant']);
            $journals->approve($journal->id, $users['finance_manager']);
        }

        // FY2026 budget: prepared by the accountant (one row per account and branch), approved by the finance manager.
        $budgets = app(\App\Modules\Finance\Budget\Application\BudgetService::class);
        $budget = $budgets->create($entityId, 2026, 'MAIN', 'Operating budget', $users['accountant']);
        foreach (['HO' => 0, 'CTG' => 1] as $branch => $column) {
            $rows = [];
            foreach (self::BUDGET as $code => $monthly) {
                $amounts = array_fill(0, 12, $monthly[$column] * 100);
                if ((int) $code === 5300) {
                    $amounts[8] *= 2; // March: Eid-ul-Fitr festival bonus
                }
                $rows[] = ['account_id' => $accounts[$code], 'amounts' => $amounts];
            }
            $budgets->saveBranchRows($budget, $this->branches[$branch], $rows, $users['accountant']);
        }
        $budgets->submit($budget, $users['accountant']);
        $budgets->approve($budget, $users['finance_manager']);
    }

    /** @param array<string, string> $users */
    private function fixedAssets(string $entityId, string $bankAccountId, array $users): void
    {
        $assets = app(FixedAssetService::class);
        $expense = $this->account($entityId, '5710');
        $disposal = $this->account($entityId, '4800');
        $classes = [];
        foreach (self::CLASSES as $code => [$name, $method, $lifeOrRate, [$costCode, $costName], [$accCode, $accName]]) {
            $classes[$code] = $assets->saveClass($entityId, ['code' => $code, 'name' => $name, 'method' => $method,
                'useful_life_months' => $method === 'straight_line' ? $lifeOrRate : null, 'rate_bp' => $method === 'reducing_balance' ? $lifeOrRate * 100 : null, 'residual_bp' => 0,
                'capitalisation_threshold_minor' => 10_000_00, 'cost_account_id' => $this->newAccount($entityId, $costCode, $costName, 'debit'),
                'accumulated_account_id' => $this->newAccount($entityId, $accCode, $accName, 'credit'), 'expense_account_id' => $expense, 'disposal_account_id' => $disposal], $users['accountant']);
        }

        // Opening register at the cut-over: accumulated depreciation from the acquisition month to July 2026, as the monthly batch would have charged it.
        $cutOver = CarbonImmutable::parse(self::CUT_OVER);
        $opening = [];
        $desktops = null;
        foreach (self::OPENING_ASSETS as [$branch, $class, $description, $acquiredOn, $cost, $location, $custodian, $supplier]) {
            [$method, $lifeOrRate] = [self::CLASSES[$class][1], self::CLASSES[$class][2]];
            $accumulated = 0;
            for ($month = CarbonImmutable::parse($acquiredOn)->endOfMonth(); $month->lessThanOrEqualTo($cutOver); $month = $month->addDays(1)->endOfMonth()) {
                $accumulated += DepreciationCalculator::monthly($method, $cost * 100, 0, $method === 'straight_line' ? $lifeOrRate : null, $method === 'reducing_balance' ? $lifeOrRate * 100 : null,
                    $accumulated, CarbonImmutable::parse($acquiredOn), $month);
            }
            $id = $assets->registerOpening($entityId, ['class_id' => $classes[$class] ?? '', 'branch_id' => $this->branches[$branch], 'description' => $description, 'location' => $location,
                'custodian' => $custodian, 'supplier' => $supplier, 'acquired_on' => $acquiredOn, 'cost_minor' => $cost * 100], $accumulated, $cutOver, $users['accountant']);
            $desktops ??= str_starts_with($description, 'Dell OptiPlex') ? $id : null;
            $key = "{$class}|{$branch}";
            $opening[$key] = ['cost' => ($opening[$key]['cost'] ?? 0) + $cost * 100, 'accumulated' => ($opening[$key]['accumulated'] ?? 0) + $accumulated];
        }
        $this->openingJournal($entityId, $opening, $users);

        foreach (self::NEW_ASSETS as [$branch, $class, $description, $acquiredOn, $cost, $location, $custodian, $supplier, $invoice]) {
            $assets->acquire($entityId, ['class_id' => $classes[$class] ?? '', 'branch_id' => $this->branches[$branch], 'description' => $description, 'location' => $location, 'custodian' => $custodian,
                'supplier' => $supplier, 'invoice_ref' => $invoice, 'acquired_on' => $acquiredOn, 'cost_minor' => $cost * 100, 'paid_via' => 'payable'], $users['accountant']);
        }
        // The desktops replaced by the new laptops, sold to a second-hand dealer in August.
        $assets->dispose((string) $desktops, 'sale', CarbonImmutable::parse('2026-08-20'), 35_000_00, $bankAccountId, 'Replaced by ThinkPad laptops; sold to Elephant Road Computer Market', $users['accountant']);
    }

    /**
     * @param array<string, array{cost: int, accumulated: int}> $opening "class|branch" → totals
     * @param array<string, string> $users
     */
    private function openingJournal(string $entityId, array $opening, array $users): void
    {
        $lines = [];
        $nbv = 0;
        foreach ($opening as $key => $totals) {
            [$class, $branch] = explode('|', $key);
            $classRow = DB::table('asset_classes')->where('entity_id', $entityId)->where('code', $class)->first(['cost_account_id', 'accumulated_account_id']) ?? throw new \LogicException('Class missing.');
            $lines[] = new ManualJournalLine((string) $classRow->cost_account_id, Side::Debit, $totals['cost'], ['branch' => $this->branches[$branch]], self::CLASSES[$class][0].' at cost');
            if ($totals['accumulated'] > 0) {
                $lines[] = new ManualJournalLine((string) $classRow->accumulated_account_id, Side::Credit, $totals['accumulated'], ['branch' => $this->branches[$branch]], self::CLASSES[$class][0].' accumulated depreciation');
            }
            $nbv += $totals['cost'] - $totals['accumulated'];
        }
        $lines[] = new ManualJournalLine($this->account($entityId, '3100'), Side::Credit, $nbv, ['branch' => $this->branches['HO']], 'Fixed assets brought forward at net book value');
        $journals = app(ManualJournalService::class);
        $journal = $journals->create(new ManualJournalRequest($entityId, CarbonImmutable::parse(self::CUT_OVER), 'Fixed assets brought forward', JournalKind::Manual,
            'Fixed asset register at the 31 July 2026 cut-over: cost and accumulated depreciation per class and branch', 'BDT', $lines), $users['accountant']);
        $journals->submit($journal->id, $users['accountant']);
        $journals->approve($journal->id, $users['finance_manager']);
    }

    private function newAccount(string $entityId, string $code, string $name, string $normalSide, string $type = 'asset'): string
    {
        $id = (string) Str::uuid7();
        DB::table('accounts')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId, 'code' => $code, 'name' => $name, 'type' => $type, 'normal_side' => $normalSide,
            'is_postable' => true, 'is_control' => false, 'control_subledger' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function account(string $entityId, string $code): string
    {
        return (string) (DB::table('accounts')->where('entity_id', $entityId)->where('code', $code)->value('id') ?? throw new \LogicException("Account {$code} missing."));
    }
}
