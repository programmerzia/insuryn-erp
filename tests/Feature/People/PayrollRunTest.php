<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Finance\Bank\Application\BankAccountService;
use App\Modules\People\Employee\Application\EmployeeService;
use App\Modules\People\Payroll\Application\PayrollReconciler;
use App\Modules\People\Payroll\Application\PayrollRunService;
use App\Modules\Platform\Authorization\SodViolation;
use App\Modules\Platform\Messaging\Outbox;
use App\Modules\Platform\Messaging\OutboxConsumers;
use Carbon\CarbonImmutable;
use Database\Seeders\PeopleDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * People and Payroll MVP (addendum §B.10, §B.11): a month's payroll is calculated from the rules as data, approved by someone other than the preparer and
 * posted as PAYROLL_POSTED per branch and department, paid by someone other than the approver (PAYROLL_PAID with the salary bank file); commission paid
 * through payroll arrives by the outbox, is shown on the payslip, is not expensed again, and salary payable reconciles to zero after payment.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $t = $this->ctx['tenant_id'];
    $this->hr = userWithPermissions($t, ['hr.manage_employees', 'payroll.prepare', 'payroll.manage_rules', 'payroll.approve']);
    $this->approver = userWithPermissions($t, ['payroll.approve']);
    $this->payer = userWithPermissions($t, ['payroll.pay', 'bank.manage_accounts']);
});

it('calculates, approves, posts and pays a month with commission paid through payroll', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $ids = PeopleDemoSeeder::configure($this->ctx['entity_id'], $this->hr);
        $hire = function (string $code, string $grade, int $basic, string $type, string $account) use ($ids): string {
            DB::table('parties')->insert(['id' => $party = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'kind' => 'individual', 'display_name' => $code, 'status' => 'active']);

            return app(EmployeeService::class)->hire($this->ctx['entity_id'], ['party_id' => $party, 'code' => $code, 'full_name' => "Employee {$code}", 'joined_on' => '2024-01-01',
                'branch_id' => $this->ctx['branch_id'], 'department_id' => $ids['departments']['UW'], 'designation_id' => $ids['designations']['Officer'], 'grade_id' => $ids['grades'][$grade],
                'employment_type' => $type, 'basic_minor' => $basic, 'bank_name' => 'City Bank', 'bank_branch' => 'Motijheel', 'routing_no' => '225274351', 'account_no' => $account], $this->hr);
        };
        $manager = $hire('E1', 'G4', 100_000_00, 'permanent', '1051000000011');
        $bdo = $hire('E2', 'G6', 40_000_00, 'contract', '1051000000022');

        // Commission paid through payroll (what CommissionPayoutService writes): the payout event and the outbox message.
        $statementId = (string) Str::uuid7();
        DB::transaction(function () use ($statementId, $bdo): void {
            app(SubmitAccountingEvent::class)($this->ctx['entity_id'], 'COMMISSION_PAYOUT_TO_PAYROLL', 'commission_statement', $statementId, "COMMISSION_PAYOUT_TO_PAYROLL:{$statementId}",
                CarbonImmutable::parse('2026-09-05'), CarbonImmutable::parse('2026-09-05'), 'BDT', ['amount' => 10_000_00, 'commission_statement_id' => $statementId, 'employee_id' => $bdo],
                ['branch' => $this->ctx['branch_id'], 'agent' => (string) Str::uuid7()]);
            app(Outbox::class)->add('CommissionPayrollEarning', ['commission_statement_id' => $statementId, 'employee_id' => $bdo, 'earning_type' => 'commission', 'amount_minor' => 10_000_00,
                'currency' => 'BDT', 'period_end' => '2026-08-31', 'paid_on' => '2026-09-05', 'reference' => 'CST-2026-000001']);
        });

        $runs = app(PayrollRunService::class);
        $runId = $runs->calculate($this->ctx['entity_id'], 2026, 9, $this->hr);
        expect(app(OutboxConsumers::class)->deliverCurrentTenant())->toBe(0) // consumed by the calculation, once
            ->and(DB::table('payroll_inputs')->where('source_id', $statementId)->value('status'))->toBe('open');

        $slip = fn (string $employee): object => DB::table('payslips')->where('run_id', $runId)->where('employee_id', $employee)->first() ?? throw new RuntimeException('No payslip.');
        // E1: basic 100,000 + house rent 50,000 + medical 10,000 (cap 10,000) + conveyance 5,000 = 165,000; PF 10,000. Tax: 165,000 × 12 + two Eid bonuses 200,000 = 21,80,000,
        // less the 5,00,000 cap = 16,80,000 → 0 + 30,000 + 60,000 + 1,00,000 + 1,05,000 at 25% (26,250) = 2,16,250 a year → 18,020.83 a month (half-even).
        expect((int) $slip($manager)->gross_minor)->toBe(165_000_00)
            ->and((int) $slip($manager)->pf_employee_minor)->toBe(10_000_00)
            ->and((int) $slip($manager)->tax_minor)->toBe(18_020_83)
            ->and((int) $slip($manager)->net_minor)->toBe(136_979_17)
            // E2 (contract, no PF): 40,000 + 20,000 + 4,000 + 2,500 = 66,500 plus commission 10,000, which is not taxed again (A-283).
            ->and((int) $slip($bdo)->commission_minor)->toBe(10_000_00)
            ->and((int) $slip($bdo)->gross_minor)->toBe(76_500_00)
            ->and((int) $slip($bdo)->pf_employee_minor)->toBe(0);
        $bdoTax = (int) $slip($bdo)->tax_minor;
        expect((int) $slip($bdo)->net_minor)->toBe(76_500_00 - $bdoTax);

        expect(fn () => $runs->approve($runId, $this->hr))->toThrow(SodViolation::class);
        $runs->approve($runId, $this->approver);
        expect(fn () => $runs->pay($runId, (string) Str::uuid7(), CarbonImmutable::parse('2026-09-30'), $this->approver))->toThrow(App\Modules\Platform\Authorization\PermissionDenied::class);

        foreach (DB::table('accounting_events')->where('status', 'queued')->orderBy('created_at')->pluck('id') as $eventId) {
            app(PostingEngine::class)->post((string) $eventId);
        }
        $lines = fn (string $eventType): array => DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.posting_rule_code', 'like', "{$eventType}.%")->groupBy('l.role_code', 'l.side')->orderBy('l.role_code')->orderBy('l.side')
            ->selectRaw('l.role_code, l.side, sum(l.amount_minor) as amount')->get()->map(fn (object $l): array => [$l->role_code, $l->side, (int) $l->amount])->all();
        $netTotal = 136_979_17 + 76_500_00 - $bdoTax;
        expect($lines('PAYROLL_POSTED'))->toEqual([
            ['employee_tax_payable', 'credit', 18_020_83 + $bdoTax],
            ['employer_pf_expense', 'debit', 10_000_00],
            ['pf_payable', 'credit', 20_000_00],
            ['salary_expense', 'debit', 165_000_00 + 66_500_00],          // commission is not expensed again
            ['salary_payable', 'credit', $netTotal - 10_000_00],           // its liability came with COMMISSION_PAYOUT_TO_PAYROLL
        ])->and(DB::table('payroll_runs')->where('id', $runId)->value('status'))->toBe('posted');

        $bank = app(BankAccountService::class)->create($this->ctx['entity_id'], $this->ctx['accounts']['bank_main'], 'City Bank', '****4471', 'BDT', $this->payer);
        $runs->pay($runId, $bank->id, CarbonImmutable::parse('2026-09-30'), $this->payer);
        foreach (DB::table('accounting_events')->where('status', 'queued')->pluck('id') as $eventId) {
            app(PostingEngine::class)->post((string) $eventId);
        }
        expect($lines('PAYROLL_PAID'))->toEqual([['bank_main', 'credit', $netTotal], ['salary_payable', 'debit', $netTotal]])
            ->and((int) DB::table('salary_bank_files')->where('run_id', $runId)->value('total_minor'))->toBe($netTotal)
            ->and(app(PayrollReconciler::class)->balanceAt($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-30'))->getMinorAmount()->toInt())->toBe(0)
            ->and(app(ReconciliationService::class)->currentVariances((string) DB::table('fiscal_periods')->where('starts', '2026-09-01')->value('id')))->not->toHaveKey('payroll'); // (commission differs only because this test fakes the payout without its statement)
    });
});
