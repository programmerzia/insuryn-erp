<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Distribution\Application\Incentives\IncentivePlanService;
use App\Modules\Distribution\Application\Targets\TargetService;
use App\Modules\Insurance\Commission\Application\CommissionPayoutService;
use App\Modules\Insurance\Commission\Application\CommissionStatementRun;
use App\Modules\Insurance\Commission\Application\IncentiveRun;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Party\Domain\PartyContact;
use App\Modules\People\Employee\Application\EmployeeService;
use App\Modules\People\Payroll\Application\PayrollRules;
use App\Modules\People\Payroll\Application\PayrollRunService;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Messaging\OutboxConsumers;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * People and Payroll in the Part A story (Padma General Insurance), run inside the story before August is closed:
 *
 * - HR reference data and the payroll rules as placeholders flagged verify (ASSUMPTION A-284): departments, designations, grades G1–G8 with house rent,
 *   medical and conveyance per grade; provident fund 10% + 10% of basic for permanent staff; a festival bonus of one month's basic at each Eid; income tax
 *   slabs for FY2026-27 with one third of income (capped at 5,00,000) exempt and a minimum tax of 5,000.
 * - 30 employees at Head Office and the Chittagong branch, with a promotion, a transfer and a pay change in their history; Nasima Akter, the salaried BDO
 *   of the story, is employee EMP-017 and her producer record points at her.
 * - August 2026 payroll calculated by the HR manager, approved and posted by the finance manager, salaries released by the accountant on 1 September.
 * - Nasima's August incentive bonus: a statement paid through payroll on 5 September (COMMISSION_PAYOUT_TO_PAYROLL), taken in by the payroll consumer and
 *   shown on her September payslip; the September payroll is calculated and waits for approval.
 */
final class PeopleDemoSeeder
{
    /** @var list<array{0: string, 1: string}> code, name */
    private const DEPARTMENTS = [['EXE', 'Executive Office'], ['UW', 'Underwriting'], ['CLM', 'Claims'], ['FIN', 'Finance and Accounts'], ['BD', 'Business Development'],
        ['HRA', 'HR and Administration'], ['IT', 'Information Technology'], ['BOP', 'Branch Operations']];

    /** @var list<string> */
    private const DESIGNATIONS = ['Managing Director and CEO', 'Deputy Managing Director', 'Chief Financial Officer', 'Senior Manager', 'Branch Manager', 'Manager',
        'Assistant Manager', 'Senior Officer', 'Officer', 'Junior Officer', 'Business Development Officer', 'Office Assistant'];

    /** grade => [name, house rent bp, medical bp, medical cap (BDT), conveyance (BDT)] — placeholders, verify. */
    private const GRADES = ['G1' => ['Executive', 5000, 1000, 20_000, 15_000], 'G2' => ['Senior executive', 5000, 1000, 15_000, 10_000], 'G3' => ['Senior management', 5000, 1000, 12_000, 6_000],
        'G4' => ['Management', 5000, 1000, 10_000, 5_000], 'G5' => ['Junior management', 5000, 1000, 8_000, 3_500], 'G6' => ['Senior officer', 5000, 1000, 6_000, 2_500],
        'G7' => ['Officer', 5000, 1000, 5_000, 2_000], 'G8' => ['Support staff', 4000, 1000, 2_500, 1_500]];

    /**
     * code => [name, gender, branch (HO|CTG), department, designation, grade, type, basic (BDT), joined on, bank].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: int, 8: string, 9: string}>
     */
    private const EMPLOYEES = [
        'EMP-001' => ['Mahbubur Rahman', 'male', 'HO', 'EXE', 'Managing Director and CEO', 'G1', 'permanent', 450_000, '2018-03-01', 'City Bank'],
        'EMP-002' => ['Farzana Haque', 'female', 'HO', 'EXE', 'Deputy Managing Director', 'G2', 'permanent', 280_000, '2019-01-15', 'BRAC Bank'],
        'EMP-003' => ['Shafiqul Islam', 'male', 'HO', 'FIN', 'Chief Financial Officer', 'G2', 'permanent', 240_000, '2019-06-01', 'City Bank'],
        'EMP-004' => ['Tahmina Begum', 'female', 'HO', 'UW', 'Senior Manager', 'G3', 'permanent', 140_000, '2020-02-01', 'Dutch-Bangla Bank'],
        'EMP-005' => ['Anisur Rahman', 'male', 'HO', 'CLM', 'Senior Manager', 'G3', 'permanent', 135_000, '2020-07-01', 'Eastern Bank'],
        'EMP-006' => ['Rezaul Karim', 'male', 'HO', 'FIN', 'Manager', 'G4', 'permanent', 82_000, '2021-01-10', 'City Bank'],
        'EMP-007' => ['Sadia Afrin', 'female', 'HO', 'HRA', 'Manager', 'G4', 'permanent', 85_000, '2021-04-01', 'BRAC Bank'],
        'EMP-008' => ['Kamrul Hasan', 'male', 'HO', 'IT', 'Manager', 'G4', 'permanent', 95_000, '2020-11-01', 'Dutch-Bangla Bank'],
        'EMP-009' => ['Nusrat Jahan', 'female', 'HO', 'UW', 'Senior Officer', 'G6', 'permanent', 45_000, '2022-03-01', 'Islami Bank Bangladesh'],
        'EMP-010' => ['Imran Hossain', 'male', 'HO', 'CLM', 'Assistant Manager', 'G5', 'permanent', 58_000, '2022-05-15', 'Eastern Bank'],
        'EMP-011' => ['Mehedi Hasan', 'male', 'HO', 'FIN', 'Senior Officer', 'G6', 'permanent', 42_000, '2023-01-01', 'City Bank'],
        'EMP-012' => ['Sumaiya Islam', 'female', 'HO', 'UW', 'Senior Officer', 'G6', 'permanent', 40_000, '2023-02-01', 'Dutch-Bangla Bank'],
        'EMP-013' => ['Rakibul Hasan', 'male', 'HO', 'CLM', 'Officer', 'G7', 'permanent', 30_000, '2024-01-15', 'BRAC Bank'],
        'EMP-014' => ['Fatema Khatun', 'female', 'HO', 'FIN', 'Officer', 'G7', 'permanent', 30_000, '2024-03-01', 'City Bank'],
        'EMP-015' => ['Arif Chowdhury', 'male', 'HO', 'IT', 'Officer', 'G7', 'permanent', 32_000, '2024-06-01', 'Eastern Bank'],
        'EMP-016' => ['Jannatul Ferdous', 'female', 'HO', 'HRA', 'Officer', 'G7', 'probation', 28_000, '2026-03-01', 'Dutch-Bangla Bank'],
        'EMP-017' => ['Nasima Akter', 'female', 'HO', 'BD', 'Business Development Officer', 'G6', 'permanent', 38_000, '2026-01-01', 'City Bank'],
        'EMP-018' => ['Tanvir Ahmed', 'male', 'HO', 'BD', 'Senior Manager', 'G3', 'permanent', 120_000, '2019-09-01', 'BRAC Bank'],
        'EMP-019' => ['Abdul Malek', 'male', 'HO', 'HRA', 'Office Assistant', 'G8', 'contract', 16_000, '2022-08-01', 'Islami Bank Bangladesh'],
        'EMP-020' => ['Shirin Akter', 'female', 'HO', 'BD', 'Business Development Officer', 'G6', 'permanent', 36_000, '2025-04-01', 'Dutch-Bangla Bank'],
        'EMP-021' => ['Mizanur Rahman', 'male', 'HO', 'UW', 'Junior Officer', 'G7', 'probation', 26_000, '2026-08-10', 'City Bank'],
        'EMP-022' => ['Jashim Uddin', 'male', 'CTG', 'BOP', 'Branch Manager', 'G4', 'permanent', 100_000, '2019-05-01', 'Eastern Bank'],
        'EMP-023' => ['Rokeya Sultana', 'female', 'CTG', 'BOP', 'Assistant Manager', 'G5', 'permanent', 55_000, '2021-09-01', 'Islami Bank Bangladesh'],
        'EMP-024' => ['Nazmul Haque', 'male', 'CTG', 'UW', 'Senior Officer', 'G6', 'permanent', 40_000, '2022-10-01', 'Dutch-Bangla Bank'],
        'EMP-025' => ['Parvin Akter', 'female', 'HO', 'CLM', 'Officer', 'G7', 'permanent', 30_000, '2023-07-01', 'BRAC Bank'],
        'EMP-026' => ['Saiful Islam', 'male', 'CTG', 'BD', 'Business Development Officer', 'G6', 'permanent', 37_000, '2024-02-01', 'Islami Bank Bangladesh'],
        'EMP-027' => ['Khaleda Parvin', 'female', 'CTG', 'BD', 'Business Development Officer', 'G6', 'permanent', 35_000, '2025-08-01', 'Eastern Bank'],
        'EMP-028' => ['Emon Barua', 'male', 'CTG', 'FIN', 'Officer', 'G7', 'permanent', 29_000, '2024-09-01', 'Dutch-Bangla Bank'],
        'EMP-029' => ['Liton Das', 'male', 'CTG', 'BOP', 'Office Assistant', 'G8', 'contract', 15_000, '2023-03-01', 'Islami Bank Bangladesh'],
        'EMP-030' => ['Hasan Mahmud', 'male', 'CTG', 'UW', 'Junior Officer', 'G7', 'permanent', 25_000, '2025-11-01', 'City Bank'],
    ];

    /**
     * The story's People and Payroll part. `$users` are the Part A role users (role code → user id); `$bdoProducerId` is Nasima Akter's producer.
     *
     * @param array<string, string> $users
     */
    public function run(string $entityId, string $headOfficeId, string $chittagongId, string $bankAccountId, array $users, string $bdoProducerId): void
    {
        $hr = $this->hrManager();
        $finance = $users['finance_manager'];
        $accountant = $users['accountant'];
        $day = fn (string $d): CarbonImmutable => CarbonImmutable::parse($d);
        $ids = self::configure($entityId, $hr);

        $nasimaEmployeeId = (string) DB::table('producers')->where('id', $bdoProducerId)->value('employee_id');
        $employees = app(EmployeeService::class);
        $parties = app(PartyService::class);
        $employeeIds = [];
        $seq = 0;
        foreach (self::EMPLOYEES as $code => [$name, $gender, $branch, $department, $designation, $grade, $type, $basic, $joined, $bank]) {
            $seq++;
            $party = $parties->create(PartyKind::Individual, $name, sprintf('%012d', 184_000_000_000 + $seq * 7919), [PartyRoleType::Employee], $hr,
                new PartyContact(mobile: sprintf('+88017%08d', 11_223_300 + $seq), identityNo: sprintf('%010d', 1_990_000_000 + $seq * 131)));
            $employeeIds[$code] = $employees->hire($entityId, ['id' => $code === 'EMP-017' && Str::isUuid($nasimaEmployeeId) ? $nasimaEmployeeId : null, 'party_id' => $party->id, 'code' => $code,
                'full_name' => $name, 'gender' => $gender, 'joined_on' => $joined, 'branch_id' => $branch === 'HO' ? $headOfficeId : $chittagongId, 'department_id' => $ids['departments'][$department],
                'designation_id' => $ids['designations'][$designation], 'grade_id' => $ids['grades'][$grade], 'employment_type' => $type, 'basic_minor' => $basic * 100,
                'tin' => sprintf('%012d', 184_000_000_000 + $seq * 7919), 'nid' => sprintf('%010d', 1_990_000_000 + $seq * 131), 'mobile' => sprintf('+88017%08d', 11_223_300 + $seq),
                'bank_name' => $bank, 'bank_branch' => $branch === 'HO' ? 'Motijheel' : 'Agrabad', 'routing_no' => sprintf('%09d', 225_270_000 + $seq), 'account_no' => sprintf('%013d', 1_051_000_000_000 + $seq * 977)], $hr);
        }
        $employee = fn (string $code): string => $employeeIds[$code] ?? throw new RuntimeException("Demo employee {$code} was not hired.");
        if (! Str::isUuid($nasimaEmployeeId)) {
            DB::table('producers')->where('id', $bdoProducerId)->update(['employee_id' => $employee('EMP-017')]);
        }
        // Employment history: a promotion, a transfer and a pay change before August; a confirmation after probation from September.
        $employees->changeEmployment($employee('EMP-009'), 'promotion', $day('2026-07-01'), ['designation_id' => $ids['designations']['Assistant Manager'], 'grade_id' => $ids['grades']['G5'],
            'basic_minor' => 60_000_00, 'note' => 'Promoted after the annual appraisal'], $hr);
        $employees->changeEmployment($employee('EMP-025'), 'transfer', $day('2026-04-01'), ['branch_id' => $chittagongId, 'note' => 'Transferred to the Chittagong claims desk'], $hr);
        $employees->changeEmployment($employee('EMP-006'), 'pay_change', $day('2026-07-01'), ['basic_minor' => 90_000_00, 'note' => 'Annual increment'], $hr);
        $employees->changeEmployment($employee('EMP-016'), 'pay_change', $day('2026-09-01'), ['employment_type' => 'permanent', 'basic_minor' => 30_000_00, 'note' => 'Confirmed after six months of probation'], $hr);

        // August payroll: calculated by HR, approved and posted by finance, released by the accountant.
        $runs = app(PayrollRunService::class);
        $august = $runs->calculate($entityId, 2026, 8, $hr);
        $runs->approve($august, $finance);
        self::postQueuedEvents();
        $runs->pay($august, $bankAccountId, $day('2026-09-01'), $accountant);

        // Nasima Akter's August incentive: target, plan, award, statement approved by finance and paid through payroll by the accountant.
        app(TargetService::class)->set('producer', $bdoProducerId, 'monthly', $day('2026-08-01'), 'premium', 1_000_000, $users['branch_manager']);
        app(IncentivePlanService::class)->create(['code' => 'BDO-MONTHLY-NL', 'name' => 'BDO monthly premium bonus (non-life)', 'period_type' => 'monthly', 'metric' => 'premium',
            'applies_to' => ['producer_type' => 'bdo'], 'effective_from' => '2026-01-01', 'tiers' => [['achievement_bp_from' => 10000, 'bonus' => ['type' => 'fixed_minor', 'value' => 1_500_000]]]], $finance);
        app(IncentiveRun::class)->run($entityId, $day('2026-08-31'), $finance);
        $statements = app(CommissionStatementRun::class);
        $nasimaStatement = null;
        foreach ($statements->prepare($entityId, $day('2026-08-31'), $finance) as $statementId) {
            if ((string) DB::table('commission_statements')->where('id', $statementId)->value('agent_id') === $bdoProducerId) {
                $nasimaStatement = $statementId;
            }
        }
        if ($nasimaStatement === null) {
            throw new RuntimeException('Demo: Nasima Akter earned no August incentive, so no statement goes through payroll.');
        }
        $statements->approve($nasimaStatement, $finance, $day('2026-09-02'));
        app(CommissionPayoutService::class)->pay($nasimaStatement, null, $accountant, $day('2026-09-05'));
        app(OutboxConsumers::class)->deliverCurrentTenant();
        self::postQueuedEvents();

        // September: calculated, waiting for the finance manager.
        $runs->calculate($entityId, 2026, 9, $hr);
    }

    /**
     * Reference data and payroll rules (placeholders, verify). Shared with the payroll tests.
     *
     * @return array{departments: array<string, string>, designations: array<string, string>, grades: array<string, string>}
     */
    public static function configure(string $entityId, string $actorUserId): array
    {
        $tenantId = TenantContext::id();
        $insert = function (string $table, string $code, string $name, array $extra = []) use ($tenantId): string {
            $id = (string) Str::uuid7();
            DB::table($table)->insert(['id' => $id, 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()] + $extra);
            app(Audit::class)->record(Str::singular($table).'.created', AuditSubject::of(Str::singular($table), $id), null, ['code' => $code, 'name' => $name], null, 'payroll.manage_rules', Actor::system());

            return $id;
        };
        $ids = ['departments' => [], 'designations' => [], 'grades' => []];
        foreach (self::DEPARTMENTS as [$code, $name]) {
            $ids['departments'][$code] = $insert('departments', $code, $name);
        }
        foreach (self::DESIGNATIONS as $i => $name) {
            $ids['designations'][$name] = $insert('designations', sprintf('D%02d', $i + 1), $name);
        }
        $rules = app(PayrollRules::class);
        $rank = 0;
        foreach (self::GRADES as $code => [$name, $houseRent, $medical, $cap, $conveyance]) {
            $ids['grades'][$code] = $insert('grades', $code, $name, ['rank' => ++$rank]);
            $rules->saveStructure($ids['grades'][$code], CarbonImmutable::parse('2026-01-01'), ['house_rent_bp' => $houseRent, 'medical_bp' => $medical, 'medical_cap_minor' => $cap * 100,
                'conveyance_minor' => $conveyance * 100, 'verify' => true], $actorUserId);
        }
        $rules->saveSettings($entityId, CarbonImmutable::parse('2026-01-01'), ['pf_employee_bp' => 1000, 'pf_employer_bp' => 1000, 'pf_employment_types' => ['permanent'],
            'festival_bonus_bp' => 10_000, 'festival_bonus_min_service_months' => 6,
            'festivals' => [['name' => 'Eid-ul-Adha', 'month' => '2026-05'], ['name' => 'Eid-ul-Fitr', 'month' => '2027-03'], ['name' => 'Eid-ul-Adha', 'month' => '2027-05']],
            'tax_year_start_month' => 7, 'tax_exempt_fraction_bp' => 3333, 'tax_exempt_cap_minor' => 500_000_00, 'minimum_tax_minor' => 5_000_00, 'tax_category' => 'general',
            'commission_taxable' => false, 'verify' => true], $actorUserId);
        // ASSUMPTION A-284: FY2026-27 slabs for resident individuals (general), placeholders to verify against the Finance Act: 3,75,000 at 0%, then 3,00,000 at 10%,
        // 4,00,000 at 15%, 5,00,000 at 20%, 20,00,000 at 25%, the rest at 30%.
        $rules->saveSlabs('2026-27', 'general', [['band_minor' => 375_000_00, 'rate_bp' => 0], ['band_minor' => 300_000_00, 'rate_bp' => 1000], ['band_minor' => 400_000_00, 'rate_bp' => 1500],
            ['band_minor' => 500_000_00, 'rate_bp' => 2000], ['band_minor' => 2_000_000_00, 'rate_bp' => 2500], ['band_minor' => null, 'rate_bp' => 3000]], true, $actorUserId);

        return $ids;
    }

    private function hrManager(): string
    {
        $tenantId = TenantContext::id();
        $slug = (string) DB::table('tenants')->where('id', $tenantId)->value('slug');
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'tenant_id' => $tenantId, 'email' => "hr.manager@{$slug}.local", 'name' => 'HR manager',
            'password' => Hash::make((string) config('erp.seed.admin_password')), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $roleId = (string) DB::table('roles')->where('code', 'hr_manager')->value('id');
        DB::table('user_roles')->insert(['tenant_id' => $tenantId, 'user_id' => $id, 'role_id' => $roleId, 'scope_type' => 'tenant', 'scope_id' => $tenantId]);
        app(Audit::class)->record('user_role.assigned', AuditSubject::of('user', $id), null, ['role_id' => $roleId, 'role_code' => 'hr_manager', 'scope_type' => 'tenant', 'scope_id' => $tenantId],
            'Part A demo', 'platform.manage_users', Actor::system());

        return $id;
    }

    /** Inside the story's transaction the after-commit dispatch waits, so the demo posts queued events itself (as PartADemoSeeder does). */
    private static function postQueuedEvents(): void
    {
        foreach (DB::table('accounting_events')->where('status', 'queued')->orderBy('created_at')->orderBy('id')->pluck('id') as $eventId) {
            app(PostingEngine::class)->post((string) $eventId);
        }
        $failed = DB::table('accounting_events')->where('status', 'failed')->get(['event_type', 'failure_reason']);
        if ($failed->isNotEmpty()) {
            throw new RuntimeException('Demo accounting events failed: '.$failed->map(fn (object $e): string => "{$e->event_type} ({$e->failure_reason})")->implode(', '));
        }
    }
}
