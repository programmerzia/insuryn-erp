<?php

declare(strict_types=1);

namespace App\Modules\People\Payroll\Application;

use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Finance\Bank\Application\BankAccountQuery;
use App\Modules\People\Payroll\Domain\PayslipCalculator;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Messaging\OutboxConsumers;
use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The monthly payroll run (design §B.10.3–§B.10.6, MVP): calculate a preview per employee → approve, which posts PAYROLL_POSTED in the same step (PD-24) →
 * pay, which writes the salary bank file and posts PAYROLL_PAID → payslips. `payroll.prepare` ✕ `payroll.approve` and `payroll.approve` ✕ `payroll.pay` on the
 * run (object SoD rules, checked from the run's audit trail).
 *
 * DECISION D-124: the posting kernel has fixed rule lines (PD-3 `for_each` is not built), so PAYROLL_POSTED version 2 is submitted once per branch and
 * department of the run (key `PAYROLL_POSTED:{run}:{branch}:{department}`) with summary lines for that group; per-employee amounts stay on the payslips,
 * which the payroll reconciler compares with the GL in total. PAYROLL_PAID is one event for the run (one bank debit).
 */
final class PayrollRunService
{
    public const PREPARE = 'payroll.prepare';

    public const APPROVE = 'payroll.approve';

    public const PAY = 'payroll.pay';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SodGuard $sod,
        private readonly PayrollRules $rules,
        private readonly SubmitAccountingEvent $submit,
        private readonly DocumentNumberer $numbers,
        private readonly Audit $audit,
    ) {}

    /**
     * Calculates (or recalculates) the month's regular run as a preview. Commission earnings waiting in the outbox are taken in first.
     *
     * @throws BusinessRuleViolation PAYROLL_RUN_ALREADY_POSTED, PAYROLL_SETTINGS_MISSING, PAYROLL_STRUCTURE_MISSING, PAYROLL_TAX_SLABS_MISSING, PAYROLL_NO_EMPLOYEES
     */
    public function calculate(string $entityId, int $year, int $month, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, self::PREPARE, AuthorizationScope::entity($entityId));
        app(OutboxConsumers::class)->deliverCurrentTenant();
        $existing = DB::table('payroll_runs')->where('entity_id', $entityId)->where('period_year', $year)->where('period_month', $month)->where('kind', 'regular')
            ->where('status', '<>', 'cancelled')->first(['id', 'status', 'number']);
        if ($existing !== null && $existing->status !== 'preview') {
            throw new BusinessRuleViolation('PAYROLL_RUN_ALREADY_POSTED', "The payroll for {$year}-".str_pad((string) $month, 2, '0', STR_PAD_LEFT)." is already {$existing->status}; corrections go into the next month.");
        }
        $result = $this->compute($entityId, $year, $month);
        $currency = (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency');

        return DB::transaction(function () use ($entityId, $year, $month, $existing, $result, $currency, $actorUserId): string {
            $runId = $existing === null ? (string) Str::uuid7() : (string) $existing->id;
            if ($existing === null) {
                DB::table('payroll_runs')->insert(['id' => $runId, 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId, 'kind' => 'regular', 'period_year' => $year,
                    'period_month' => $month, 'status' => 'preview', 'currency' => $currency, 'prepared_by' => $actorUserId, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('payroll_runs')->where('id', $runId)->lockForUpdate()->first();
                DB::table('payslips')->where('run_id', $runId)->delete();
            }
            $totals = ['gross_minor' => 0, 'bonus_minor' => 0, 'commission_minor' => 0, 'tax_minor' => 0, 'pf_employee_minor' => 0, 'pf_employer_minor' => 0, 'net_minor' => 0];
            foreach ($result['payslips'] as $slip) {
                $payslipId = (string) Str::uuid7();
                DB::table('payslips')->insert(['id' => $payslipId, 'tenant_id' => TenantContext::id(), 'run_id' => $runId, 'employee_id' => $slip['employee_id'], 'branch_id' => $slip['branch_id'],
                    'department_id' => $slip['department_id'], 'employment_snapshot' => json_encode($slip['snapshot'], JSON_THROW_ON_ERROR), 'basic_minor' => $slip['calc']['basic'],
                    'gross_minor' => $slip['calc']['gross'], 'bonus_minor' => $slip['calc']['bonus'], 'commission_minor' => $slip['calc']['commission'],
                    'taxable_annual_minor' => $slip['calc']['taxable_annual'], 'tax_minor' => $slip['calc']['tax'], 'pf_employee_minor' => $slip['calc']['pf_employee'],
                    'pf_employer_minor' => $slip['calc']['pf_employer'], 'net_minor' => $slip['calc']['net'], 'bank_account_snapshot' => $slip['bank'] === null ? null : json_encode($slip['bank'], JSON_THROW_ON_ERROR),
                    'trace' => json_encode($slip['calc']['trace'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                foreach ($slip['calc']['lines'] as $i => $line) {
                    DB::table('payslip_lines')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'payslip_id' => $payslipId, 'line_no' => $i + 1] + $line);
                }
                foreach (['gross_minor' => 'gross', 'bonus_minor' => 'bonus', 'commission_minor' => 'commission', 'tax_minor' => 'tax', 'pf_employee_minor' => 'pf_employee', 'pf_employer_minor' => 'pf_employer', 'net_minor' => 'net'] as $column => $key) {
                    $totals[$column] += $slip['calc'][$key];
                }
            }
            DB::table('payroll_runs')->where('id', $runId)->update($totals + ['employee_count' => count($result['payslips']), 'inputs_hash' => $result['hash'], 'calculated_at' => now(),
                'prepared_by' => $actorUserId, 'updated_at' => now()]);
            $this->audit->record('payroll_run.calculated', AuditSubject::of('payroll_run', $runId), null, ['period' => sprintf('%04d-%02d', $year, $month),
                'employees' => count($result['payslips']), 'net_minor' => $totals['net_minor']], null, self::PREPARE, Actor::user($actorUserId));

            return $runId;
        });
    }

    /**
     * Approves the preview and posts it (PD-24): numbers the run and payslips, consumes the inputs and submits PAYROLL_POSTED per branch and department,
     * dated the month's last day.
     *
     * @throws BusinessRuleViolation PAYROLL_RUN_NOT_PREVIEW, PAYROLL_INPUTS_CHANGED, PAYROLL_PERIOD_NOT_OPEN, PAYROLL_BANK_ACCOUNT_MISSING
     */
    public function approve(string $runId, string $actorUserId): void
    {
        $run = DB::table('payroll_runs')->where('id', $runId)->first() ?? throw new BusinessRuleViolation('PAYROLL_RUN_UNKNOWN', 'That payroll run does not exist.');
        $this->permissions->authorize($actorUserId, self::APPROVE, AuthorizationScope::entity((string) $run->entity_id));
        $this->sod->assert($actorUserId, self::APPROVE, AuditSubject::of('payroll_run', $runId));
        if ($run->status !== 'preview') {
            throw new BusinessRuleViolation('PAYROLL_RUN_NOT_PREVIEW', "Payroll run {$run->number} is already {$run->status}.");
        }
        $periodEnd = CarbonImmutable::parse(sprintf('%04d-%02d-01', (int) $run->period_year, (int) $run->period_month))->endOfMonth()->startOfDay();
        if ($this->compute((string) $run->entity_id, (int) $run->period_year, (int) $run->period_month)['hash'] !== $run->inputs_hash) {
            throw new BusinessRuleViolation('PAYROLL_INPUTS_CHANGED', 'Employees, salaries, rules or inputs changed after the preview was calculated. Recalculate, check and approve again.');
        }
        $period = DB::table('fiscal_periods')->where('entity_id', $run->entity_id)->where('starts', '<=', $periodEnd->toDateString())->where('ends', '>=', $periodEnd->toDateString())->first(['status']);
        if ($period === null || $period->status !== 'open') {
            throw new BusinessRuleViolation('PAYROLL_PERIOD_NOT_OPEN', "The accounting period of {$periodEnd->format('F Y')} is not open, so its payroll cannot be posted.");
        }
        $noBank = DB::table('payslips')->where('run_id', $runId)->where('net_minor', '>', 0)->whereNull('bank_account_snapshot')->count();
        if ($noBank > 0) {
            throw new BusinessRuleViolation('PAYROLL_BANK_ACCOUNT_MISSING', "{$noBank} employee(s) with pay have no salary bank account. Add their account on the employee page, recalculate and approve.");
        }
        $scope = fn (string $type, string $prefix): DocumentNumberScope => new DocumentNumberScope((string) $run->entity_id, null, $type, $prefix, $periodEnd);
        $runNumber = $this->numbers->reserve($scope('payroll_run', 'PRL'), $actorUserId);
        $slipIds = DB::table('payslips as p')->join('employees as e', 'e.id', '=', 'p.employee_id')->where('p.run_id', $runId)->orderBy('e.code')->pluck('p.id');
        $slipNumbers = [];
        foreach ($slipIds as $slipId) {
            $slipNumbers[(string) $slipId] = $this->numbers->reserve($scope('payslip', 'PSL'), $actorUserId);
        }

        DB::transaction(function () use ($runId, $run, $runNumber, $slipNumbers, $periodEnd, $actorUserId): void {
            $locked = DB::table('payroll_runs')->where('id', $runId)->lockForUpdate()->first(['status']);
            if ($locked === null || $locked->status !== 'preview') {
                throw new BusinessRuleViolation('PAYROLL_RUN_NOT_PREVIEW', 'This payroll run has already moved on. Refresh the page.');
            }
            DB::table('payroll_runs')->where('id', $runId)->update(['status' => 'posted', 'number' => $runNumber->number, 'approved_by' => $actorUserId,
                'posted_on' => $periodEnd->toDateString(), 'updated_at' => now()]);
            $this->numbers->markUsed($runNumber->id, 'payroll_run', $runId);
            foreach ($slipNumbers as $slipId => $number) {
                DB::table('payslips')->where('id', $slipId)->update(['number' => $number->number]);
                $this->numbers->markUsed($number->id, 'payslip', $slipId);
            }
            DB::table('payroll_inputs')->where('status', 'open')->where('period_year', $run->period_year)->where('period_month', $run->period_month)
                ->whereIn('employee_id', DB::table('payslips')->where('run_id', $runId)->select('employee_id'))
                ->update(['status' => 'consumed', 'consumed_by_run_id' => $runId, 'updated_at' => now()]);

            foreach ($this->postingGroups($runId) as $group) {
                ($this->submit)(
                    entityId: (string) $run->entity_id, eventType: 'PAYROLL_POSTED', sourceType: 'payroll_run', sourceId: $runId,
                    idempotencyKey: "PAYROLL_POSTED:{$runId}:{$group['branch_id']}:{$group['department_id']}", transactionDate: $periodEnd, effectiveDate: $periodEnd,
                    currency: (string) $run->currency,
                    payload: ['payroll_run_id' => $runId, 'reference' => $runNumber->number, 'salary' => $group['salary'], 'bonus' => $group['bonus'], 'employer_pf' => $group['pf_employer'],
                        'employee_pf' => $group['pf_employee'], 'employee_tax' => $group['tax'], 'net' => $group['net'], 'pre_accrued' => $group['pre_accrued'], 'gross' => $group['salary'] + $group['bonus']],
                    dimensions: ['branch' => $group['branch_id'], 'department' => $group['department_id'], 'payroll_run' => $runId],
                );
            }
            $this->audit->record('payroll_run.posted', AuditSubject::of('payroll_run', $runId), ['status' => 'preview'], ['status' => 'posted', 'number' => $runNumber->number,
                'net_minor' => (int) $run->net_minor], null, self::APPROVE, Actor::user($actorUserId));
        });
    }

    /**
     * Pays a posted run: the salary bank file (CSV, one row per payslip with pay) is stored on the run and PAYROLL_PAID moves the nets out of salary_payable.
     *
     * @throws BusinessRuleViolation PAYROLL_RUN_NOT_POSTED, INVALID_BANK_ACCOUNT
     */
    public function pay(string $runId, string $bankAccountId, CarbonImmutable $paidOn, string $actorUserId): string
    {
        $run = DB::table('payroll_runs')->where('id', $runId)->first() ?? throw new BusinessRuleViolation('PAYROLL_RUN_UNKNOWN', 'That payroll run does not exist.');
        $this->permissions->authorize($actorUserId, self::PAY, AuthorizationScope::entity((string) $run->entity_id));
        $this->sod->assert($actorUserId, self::PAY, AuditSubject::of('payroll_run', $runId));
        if ($run->status !== 'posted') {
            throw new BusinessRuleViolation('PAYROLL_RUN_NOT_POSTED', "Payroll run {$run->number} is {$run->status}; only a posted run is paid.");
        }
        $bankGl = app(BankAccountQuery::class)->glAccountFor($bankAccountId, (string) $run->entity_id, (string) $run->currency);
        $file = $this->bankFile($runId, $bankAccountId, $paidOn);

        return DB::transaction(function () use ($runId, $run, $bankAccountId, $bankGl, $paidOn, $file, $actorUserId): string {
            $locked = DB::table('payroll_runs')->where('id', $runId)->lockForUpdate()->first(['status']);
            if ($locked === null || $locked->status !== 'posted') {
                throw new BusinessRuleViolation('PAYROLL_RUN_NOT_POSTED', 'This payroll run has already moved on. Refresh the page.');
            }
            $version = (int) DB::table('salary_bank_files')->where('run_id', $runId)->max('version') + 1;
            $fileId = (string) Str::uuid7();
            DB::table('salary_bank_files')->insert(['id' => $fileId, 'tenant_id' => TenantContext::id(), 'run_id' => $runId, 'version' => $version,
                'file_name' => "salary-bank-file-{$run->number}-v{$version}.csv", 'content_enc' => Crypt::encryptString($file['csv']), 'sha256' => hash('sha256', $file['csv']),
                'total_minor' => $file['total'], 'item_count' => $file['count'], 'generated_by' => $actorUserId, 'generated_at' => now()]);
            DB::table('payroll_runs')->where('id', $runId)->update(['status' => 'paid', 'paid_by' => $actorUserId, 'paid_on' => $paidOn->toDateString(), 'bank_account_id' => $bankAccountId, 'updated_at' => now()]);
            if ($file['total'] > 0) {
                ($this->submit)(
                    entityId: (string) $run->entity_id, eventType: 'PAYROLL_PAID', sourceType: 'payroll_run', sourceId: $runId, idempotencyKey: "PAYROLL_PAID:{$runId}",
                    transactionDate: $paidOn, effectiveDate: $paidOn, currency: (string) $run->currency,
                    payload: ['payroll_run_id' => $runId, 'amount' => $file['total'], 'reference' => $run->number, 'bank_account_id' => $bankAccountId, 'account_overrides' => ['bank_main' => $bankGl]],
                    dimensions: ['branch' => $this->payingBranch((string) $run->entity_id), 'payroll_run' => $runId],
                );
            }
            $this->audit->record('payroll_run.paid', AuditSubject::of('payroll_run', $runId), ['status' => 'posted'], ['status' => 'paid', 'paid_on' => $paidOn->toDateString(),
                'bank_file_version' => $version, 'total_minor' => $file['total']], null, self::PAY, Actor::user($actorUserId));

            return $fileId;
        });
    }

    /**
     * Everything a run's payslips are made from, and the hash that proves nothing changed between preview and approval (§B.10.5).
     *
     * @return array{hash: string, payslips: list<array{employee_id: string, branch_id: string, department_id: string, snapshot: array<string, mixed>, bank: array<string, string>|null,
     *     calc: array{lines: list<array{component_code: string, kind: string, label: string, amount_minor: int, pre_accrued: bool}>, basic: int, regular: int, bonus: int, commission: int,
     *     gross: int, pf_employee: int, pf_employer: int, taxable_annual: int, tax: int, net: int, trace: list<array{step: string, value: int|string}>}}>}
     */
    public function compute(string $entityId, int $year, int $month): array
    {
        $start = CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, $month))->startOfDay();
        $end = $start->endOfMonth()->startOfDay();
        $settings = $this->rules->settingsOn($entityId, $end) ?? throw new BusinessRuleViolation('PAYROLL_SETTINGS_MISSING', "No payroll settings are in force on {$end->toDateString()}. Set them up under Payroll settings.");
        $taxYear = PayrollRules::taxYear($year, $month, $settings['tax_year_start_month']);
        $slabs = $this->rules->slabs($taxYear, $settings['tax_category']);
        if ($slabs === []) {
            throw new BusinessRuleViolation('PAYROLL_TAX_SLABS_MISSING', "No income tax slabs are set up for tax year {$taxYear}. Enter them under Payroll settings.");
        }
        $structures = $this->rules->structuresOn($end);
        $festivalsThisMonth = [];
        $festivalsInYear = 0;
        $firstYear = (int) substr($taxYear, 0, 4);
        $yearFrom = sprintf('%04d-%02d', $firstYear, $settings['tax_year_start_month']);
        $yearTo = sprintf('%04d-%02d', $firstYear + 1, $settings['tax_year_start_month']);
        foreach ($settings['festivals'] as $festival) {
            if ($festival['month'] === sprintf('%04d-%02d', $year, $month)) {
                $festivalsThisMonth[] = $festival['name'];
            }
            if ($festival['month'] >= $yearFrom && $festival['month'] < $yearTo) {
                $festivalsInYear++;
            }
        }

        $employments = DB::table('employments as m')->join('employees as e', 'e.id', '=', 'm.employee_id')
            ->leftJoin('designations as d', 'd.id', '=', 'm.designation_id')->leftJoin('grades as g', 'g.id', '=', 'm.grade_id')
            ->leftJoin('departments as dep', 'dep.id', '=', 'm.department_id')->leftJoin('branches as b', 'b.id', '=', 'm.branch_id')
            ->where('e.entity_id', $entityId)->where('e.joined_on', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('e.separated_on')->orWhere('e.separated_on', '>=', $start->toDateString()))
            ->where('m.effective_from', '<=', $end->toDateString())->where(fn ($q) => $q->whereNull('m.effective_to')->orWhere('m.effective_to', '>', $end->toDateString()))
            ->orderBy('e.code')
            ->get(['m.*', 'e.code', 'e.full_name', 'e.joined_on', 'e.bank_name', 'e.bank_branch', 'e.routing_no', 'e.account_no_masked', 'd.name as designation', 'g.code as grade_code',
                'dep.name as department', 'b.code as branch_code']);
        $inputs = DB::table('payroll_inputs')->where('status', 'open')->where('period_year', $year)->where('period_month', $month)->whereNotNull('employee_id')
            ->orderBy('created_at')->get(['id', 'employee_id', 'component_code', 'amount_minor', 'pre_accrued', 'taxable', 'source_reference']);

        $payslips = [];
        foreach ($employments as $m) {
            $structure = $structures[(string) $m->grade_id] ?? throw new BusinessRuleViolation('PAYROLL_STRUCTURE_MISSING', "Grade {$m->grade_code} has no salary structure in force on {$end->toDateString()}.");
            $joined = CarbonImmutable::parse((string) $m->joined_on);
            $firstDay = $joined->greaterThan($start) ? $joined : $start;
            $mine = $inputs->where('employee_id', $m->employee_id);
            $calc = PayslipCalculator::calculate(
                ['basic_minor' => (int) $m->basic_minor, 'employment_type' => (string) $m->employment_type, 'days_in_month' => $end->day, 'days_employed' => (int) $firstDay->diffInDays($end) + 1,
                    'service_months' => (int) $joined->diffInMonths($end->addDay())],
                $structure,
                ['pf_employee_bp' => $settings['pf_employee_bp'], 'pf_employer_bp' => $settings['pf_employer_bp'], 'pf_employment_types' => $settings['pf_employment_types'],
                    'festival_bonus_bp' => $settings['festival_bonus_bp'], 'festival_bonus_min_service_months' => $settings['festival_bonus_min_service_months'],
                    'festivals_this_month' => $festivalsThisMonth, 'festivals_in_year' => $festivalsInYear, 'tax_exempt_fraction_bp' => $settings['tax_exempt_fraction_bp'],
                    'tax_exempt_cap_minor' => $settings['tax_exempt_cap_minor'], 'minimum_tax_minor' => $settings['minimum_tax_minor'], 'commission_taxable' => $settings['commission_taxable']],
                $slabs,
                array_values($mine->map(fn (object $i): array => ['component_code' => (string) $i->component_code,
                    'label' => $i->component_code === 'commission' ? 'Commission (statement '.($i->source_reference ?? '').')' : ucfirst(str_replace('_', ' ', (string) $i->component_code)),
                    'amount_minor' => (int) $i->amount_minor, 'pre_accrued' => (bool) $i->pre_accrued, 'taxable' => (bool) $i->taxable])->all()),
            );
            $payslips[] = ['employee_id' => (string) $m->employee_id, 'branch_id' => (string) $m->branch_id, 'department_id' => (string) $m->department_id,
                'snapshot' => ['code' => $m->code, 'name' => $m->full_name, 'branch' => $m->branch_code, 'department' => $m->department, 'designation' => $m->designation, 'grade' => $m->grade_code,
                    'employment_type' => $m->employment_type, 'joined_on' => (string) $m->joined_on, 'employment_id' => $m->id, 'tax_year' => $taxYear, 'input_ids' => $mine->pluck('id')->values()->all()],
                'bank' => $m->account_no_masked === null ? null : ['bank_name' => (string) $m->bank_name, 'bank_branch' => (string) $m->bank_branch, 'routing_no' => (string) $m->routing_no, 'account_no_masked' => (string) $m->account_no_masked],
                'calc' => $calc];
        }
        if ($payslips === []) {
            throw new BusinessRuleViolation('PAYROLL_NO_EMPLOYEES', "Nobody was employed in {$start->format('F Y')}.");
        }

        return ['hash' => hash('sha256', json_encode([$settings, $structures, $slabs, $payslips], JSON_THROW_ON_ERROR)), 'payslips' => $payslips];
    }

    /** @return list<array{branch_id: string, department_id: string, salary: int, bonus: int, pf_employee: int, pf_employer: int, tax: int, net: int, pre_accrued: int}> */
    private function postingGroups(string $runId): array
    {
        $rows = DB::table('payslips as p')->where('p.run_id', $runId)->groupBy('p.branch_id', 'p.department_id')->orderBy('p.branch_id')->orderBy('p.department_id')
            ->selectRaw("p.branch_id, p.department_id, sum(p.bonus_minor) as bonus, sum(p.pf_employee_minor) as pf_employee, sum(p.pf_employer_minor) as pf_employer,
                sum(p.tax_minor) as tax, sum(p.net_minor) as net,
                sum((select coalesce(sum(l.amount_minor), 0) from payslip_lines l where l.payslip_id = p.id and l.kind = 'earning' and l.pre_accrued)) as pre_accrued,
                sum(p.gross_minor) as gross")->get();

        return array_values($rows->map(fn (object $r): array => ['branch_id' => (string) $r->branch_id, 'department_id' => (string) $r->department_id,
            'salary' => (int) $r->gross - (int) $r->bonus - (int) $r->pre_accrued, 'bonus' => (int) $r->bonus, 'pf_employee' => (int) $r->pf_employee, 'pf_employer' => (int) $r->pf_employer,
            'tax' => (int) $r->tax, 'net' => (int) $r->net, 'pre_accrued' => (int) $r->pre_accrued])->all());
    }

    /**
     * The salary bank file (CQ-J3: the bank's format is unknown). ASSUMPTION A-285: a CSV with a header row — employee code, name, bank, branch, routing
     * number, account number, amount (major units, dot decimals), reference — one row per payslip with pay, a total row last.
     *
     * @return array{csv: string, total: int, count: int}
     */
    private function bankFile(string $runId, string $bankAccountId, CarbonImmutable $paidOn): array
    {
        $run = DB::table('payroll_runs')->where('id', $runId)->first(['number', 'currency', 'period_year', 'period_month']);
        $from = DB::table('bank_accounts')->where('id', $bankAccountId)->first(['bank_name', 'account_no_masked']);
        $rows = DB::table('payslips as p')->join('employees as e', 'e.id', '=', 'p.employee_id')->where('p.run_id', $runId)->where('p.net_minor', '>', 0)->orderBy('e.code')
            ->get(['p.number', 'p.net_minor', 'e.code', 'e.full_name', 'e.bank_name', 'e.bank_branch', 'e.routing_no', 'e.account_no_enc']);
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Cannot open a temporary stream for the salary bank file.');
        }
        fputcsv($out, ['debit_account', 'value_date', 'employee_code', 'employee_name', 'bank', 'branch', 'routing_no', 'account_no', 'amount', 'currency', 'reference'], ',', '"', '');
        $total = 0;
        foreach ($rows as $r) {
            $total += (int) $r->net_minor;
            fputcsv($out, [($from->bank_name ?? '').' '.($from->account_no_masked ?? ''), $paidOn->toDateString(), $r->code, $r->full_name, $r->bank_name, $r->bank_branch, $r->routing_no,
                $r->account_no_enc === null ? '' : Crypt::decryptString((string) $r->account_no_enc), self::major((int) $r->net_minor), $run->currency ?? 'BDT',
                sprintf('SALARY %04d-%02d %s', (int) ($run->period_year ?? 0), (int) ($run->period_month ?? 0), $r->number)], ',', '"', '');
        }
        fputcsv($out, ['TOTAL', '', '', count($rows).' payments', '', '', '', '', self::major($total), $run->currency ?? 'BDT', (string) ($run->number ?? '')], ',', '"', '');
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return ['csv' => $csv, 'total' => $total, 'count' => count($rows)];
    }

    private function payingBranch(string $entityId): string
    {
        return (string) DB::table('branches')->where('entity_id', $entityId)->orderByRaw("case when code = 'HO' then 0 else 1 end")->orderBy('created_at')->value('id');
    }

    private static function major(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
