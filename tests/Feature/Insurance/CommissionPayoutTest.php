<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Finance\Bank\Application\BankAccountService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Commission\Application\CommissionPayoutService;
use App\Modules\Insurance\Commission\Application\CommissionPlanService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Authorization\SodViolation;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design §5.6 commission entry accrued ─▶ approved ─▶ paid; spec §4 "clawback netting", "payout via payroll or AP"; CONTEXT.md non-negotiable #9
 * maker ≠ checker on commission payouts (§7.3 commission.approve ✕ commission.pay). A payout statement nets an agent's accrued entries up to a
 * date (clawbacks included) and is paid by someone else, posting COMMISSION_PAID.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $planner = userWithPermissions($this->ctx['tenant_id'], ['commission.manage_plans']);
    $planId = asTenant($this->ctx['tenant_id'], function () use ($planner): string {
        DB::table('tax_rates')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'jurisdiction' => 'BD', 'tax_type' => 'AIT_COMMISSION',
            'rate_bp' => 500, 'inclusive' => false, 'withholding' => true, 'effective_from' => '2026-01-01']);

        return app(CommissionPlanService::class)->create('P10', 'Plan 10%', 1000, 'BD', 'AIT_COMMISSION', $planner)->id;
    });
    $this->world = seedInsuranceWorld($this->ctx, 'monthly', true, $planId);
    $this->approver = userWithPermissions($this->ctx['tenant_id'], ['commission.approve']);
    $this->payer = userWithPermissions($this->ctx['tenant_id'], ['commission.pay']);
    [$this->policyId, $this->installments] = asTenant($this->ctx['tenant_id'], function (): array {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 3), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return [$policy->id, DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->pluck('id')->map(fn ($id): string => (string) $id)->all()];
    });
    $this->receive = function (int $installment, int $amount, string $date): void {
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', $amount, 'BDT',
            CarbonImmutable::parse($date), null, 'r', [new AllocationLine($this->installments[$installment], $amount)]), $this->world['admin']);
    };
    $this->payableGl = fn (): int => (int) DB::table('journal_lines')->where('account_id', $this->ctx['accounts']['commission_payable'])->where('dim_agent', $this->world['agent_id'])
        ->selectRaw("coalesce(sum(case when side = 'credit' then amount_minor else -amount_minor end), 0) as b")->value('b');
});

it('approves a netted statement up to a date and pays it by another user, posting COMMISSION_PAID', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        ($this->receive)(0, 4_000_000, '2026-09-10');                          // 347,826 commission (gap audit GA-42: 10% of the 3,478,261 net premium in the 4,000,000 allocated (the 15% VAT excluded), not of the cash), 17,391 withheld
        ($this->receive)(1, 4_000_000, '2026-10-10');                          // after the statement date: not included
        $payouts = app(CommissionPayoutService::class);

        $statement = $payouts->approve($this->world['agent_id'], CarbonImmutable::parse('2026-09-30'), $this->approver, CarbonImmutable::parse('2026-10-02'));
        expect([$statement->gross_minor, $statement->withholding_minor, $statement->net_minor, $statement->status])->toBe([347_826, 17_391, 330_435, 'approved'])
            ->and($statement->number)->toStartWith('CST-2026-')
            ->and(DB::table('commission_entries')->where('statement_id', $statement->id)->pluck('status')->all())->toBe(['approved'])
            ->and(DB::table('commission_entries')->whereNull('statement_id')->pluck('status')->all())->toBe(['accrued']);

        $payouts->pay($statement->id, null, $this->payer, CarbonImmutable::parse('2026-10-05'));

        $event = DB::table('accounting_events')->where('event_type', 'COMMISSION_PAID')->first();
        $lines = DB::table('journal_lines')->where('journal_id', DB::table('journals')->where('description', 'COMMISSION_PAID')->value('id'))->orderBy('line_no')
            ->get(['role_code', 'side', 'amount_minor'])->map(fn (object $l): array => [(string) $l->role_code, (string) $l->side, (int) $l->amount_minor])->all();
        expect(DB::table('commission_statements')->where('id', $statement->id)->value('status'))->toBe('paid')
            ->and(DB::table('commission_entries')->where('statement_id', $statement->id)->get(['status', 'paid_on'])->map(fn (object $e): array => [(string) $e->status, (string) $e->paid_on])->all())->toBe([['paid', '2026-10-05']])
            ->and([$event?->idempotency_key, $event?->transaction_date, $event?->status])->toBe(['COMMISSION_PAID:'.$statement->id, '2026-10-05', 'posted'])
            ->and($lines)->toBe([['commission_payable', 'debit', 330_435], ['bank_main', 'credit', 330_435]])
            ->and(($this->payableGl)())->toBe(330_435); // only the October entry remains payable
    });
});

it('nets clawbacks into the statement and refuses when nothing is payable', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $payouts = app(CommissionPayoutService::class);
        expect(thrownBy(fn () => $payouts->approve($this->world['agent_id'], CarbonImmutable::parse('2026-09-30'), $this->approver, CarbonImmutable::parse('2026-09-30')), BusinessRuleViolation::class)->reasonCode)->toBe('NOTHING_TO_PAY');

        ($this->receive)(0, 4_000_000, '2026-09-10');
        app(PolicyLifecycle::class)->cancel($this->policyId, CarbonImmutable::parse('2026-09-20'), 'sold', $this->world['admin']);
        $clawback = DB::table('commission_entries')->where('kind', 'clawback')->first();
        $statement = $payouts->approve($this->world['agent_id'], CarbonImmutable::parse('2026-09-30'), $this->approver, CarbonImmutable::parse('2026-10-01'));

        expect($statement->gross_minor)->toBe(347_826 + (int) $clawback?->amount_minor) // gap audit GA-42: 10% of the 3,478,261 net premium in the 4,000,000 allocated (the 15% VAT excluded), not of the cash
            ->and($statement->net_minor)->toBe(330_435 + (int) $clawback?->amount_minor - (int) $clawback?->withholding_minor)
            ->and(DB::table('commission_entries')->where('statement_id', $statement->id)->count())->toBe(2)
            ->and(thrownBy(fn () => $payouts->approve($this->world['agent_id'], CarbonImmutable::parse('2026-09-30'), $this->approver, CarbonImmutable::parse('2026-10-01')), BusinessRuleViolation::class)->reasonCode)->toBe('NOTHING_TO_PAY');
    });
});

it('never lets the approver pay, and pays a statement once', function (): void {
    $both = userWithPermissions($this->ctx['tenant_id'], ['commission.approve', 'commission.pay']);

    asTenant($this->ctx['tenant_id'], function () use ($both): void {
        ($this->receive)(0, 4_000_000, '2026-09-10');
        $payouts = app(CommissionPayoutService::class);

        expect(fn () => $payouts->approve($this->world['agent_id'], CarbonImmutable::parse('2026-09-30'), $this->payer, CarbonImmutable::parse('2026-10-01')))->toThrow(PermissionDenied::class);
        $statement = $payouts->approve($this->world['agent_id'], CarbonImmutable::parse('2026-09-30'), $both, CarbonImmutable::parse('2026-10-01'));

        expect(fn () => $payouts->pay($statement->id, null, $both, CarbonImmutable::parse('2026-10-05')))->toThrow(SodViolation::class)
            ->and(DB::table('accounting_events')->where('event_type', 'COMMISSION_PAID')->count())->toBe(0);

        $payouts->pay($statement->id, null, $this->payer, CarbonImmutable::parse('2026-10-05'));
        expect(thrownBy(fn () => $payouts->pay($statement->id, null, $this->payer, CarbonImmutable::parse('2026-10-06')), BusinessRuleViolation::class)->reasonCode)->toBe('STATEMENT_NOT_APPROVED')
            ->and(DB::table('accounting_events')->where('event_type', 'COMMISSION_PAID')->count())->toBe(1);
    });
});

it('pays from a named bank account and keeps the commission subledger reconciled as of each date', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $gl = (string) Str::uuid7();
        DB::table('accounts')->insert(['id' => $gl, 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => '1012', 'name' => 'Bank - Payouts',
            'type' => 'asset', 'normal_side' => 'debit', 'is_postable' => true, 'is_control' => false, 'status' => 'active']);
        $bankAccount = app(BankAccountService::class)->create($this->ctx['entity_id'], $gl, 'Payout Bank', '****9', 'BDT', $this->world['admin']);
        ($this->receive)(0, 4_000_000, '2026-09-10');
        $payouts = app(CommissionPayoutService::class);
        $statement = $payouts->approve($this->world['agent_id'], CarbonImmutable::parse('2026-09-30'), $this->approver, CarbonImmutable::parse('2026-10-01'));
        $payouts->pay($statement->id, $bankAccount->id, $this->payer, CarbonImmutable::parse('2026-10-05'));

        $service = app(ReconciliationService::class);
        foreach (['2026-09-30', '2026-10-31'] as $monthEnd) {
            $service->runAll((string) DB::table('fiscal_periods')->where('ends', $monthEnd)->value('id'));
        }
        $runs = DB::table('reconciliation_runs')->where('subledger', 'commission')->orderBy('run_at')->get(['subledger_balance_minor', 'status'])
            ->map(fn (object $r): array => [(int) $r->subledger_balance_minor, (string) $r->status])->all();

        expect(DB::table('journal_lines')->where('role_code', 'bank_main')->where('side', 'credit')->value('account_id'))->toBe($gl)
            ->and($runs)->toBe([[330_435, 'clean'], [0, 'clean']]); // gap audit GA-42: 10% of the 3,478,261 net premium in the 4,000,000 allocated (the 15% VAT excluded), not of the cash
    });
});

it('exposes approval and payment over the API with the commission permissions', function (): void {
    $headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
    $approver = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail((string) $this->approver));
    $payer = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail((string) $this->payer));
    asTenant($this->ctx['tenant_id'], fn () => ($this->receive)(0, 4_000_000, '2026-09-10'));

    Pest\Laravel\actingAs($payer)->postJson("/api/insurance/agents/{$this->world['agent_id']}/commission-statements", ['up_to' => '2026-09-30', 'on' => '2026-10-01'], $headers)->assertForbidden();
    $statementId = Pest\Laravel\actingAs($approver)->postJson("/api/insurance/agents/{$this->world['agent_id']}/commission-statements", ['up_to' => '2026-09-30', 'on' => '2026-10-01'], $headers)
        ->assertCreated()->assertJsonPath('data.net_minor', 330_435)->json('data.id');
    Pest\Laravel\actingAs($approver)->postJson("/api/insurance/commission-statements/{$statementId}/pay", ['paid_on' => '2026-10-05'], $headers)->assertForbidden();
    Pest\Laravel\actingAs($payer)->postJson("/api/insurance/commission-statements/{$statementId}/pay", ['paid_on' => '2026-10-05'], $headers)->assertOk()->assertJsonPath('data.status', 'paid');
});

it('lets the shipped roles pay commission: the finance manager approves and the accountant pays (G3, A-138)', function (): void {
    $templates = App\Modules\Platform\Authorization\RoleTemplates::all();
    expect($templates['accountant']['permissions'])->toContain('commission.pay')
        ->and($templates['finance_manager']['permissions'])->toContain('commission.approve')
        ->and(in_array('commission.pay', $templates['finance_manager']['permissions'], true))->toBeFalse()
        ->and(in_array('commission.pay', $templates['cfo']['permissions'], true))->toBeFalse()
        // No template holds both sides of commission.approve ✕ commission.pay.
        ->and(array_keys(array_filter($templates, fn (array $t): bool => in_array('commission.approve', $t['permissions'], true) && in_array('commission.pay', $t['permissions'], true))))->toBe([]);

    seedRoleTemplates($this->ctx['tenant_id']);
    asTenant($this->ctx['tenant_id'], function (): void {
        $admin = userWithPermissions($this->ctx['tenant_id'], ['platform.manage_users']);
        $person = function (string $roleCode) use ($admin): string {
            $id = userWithPermissions($this->ctx['tenant_id'], []);
            $roleId = (string) DB::table('roles')->where('code', $roleCode)->value('id');
            expect(app(App\Modules\Platform\Authorization\RoleAssignmentService::class)->assign($id, $roleId, 'tenant', $this->ctx['tenant_id'], $admin))->toBe([]);

            return $id;
        };
        $finance = $person('finance_manager');
        $accountant = $person('accountant');
        ($this->receive)(0, 4_000_000, '2026-09-10');
        $payouts = app(CommissionPayoutService::class);

        $statement = $payouts->approve($this->world['agent_id'], CarbonImmutable::parse('2026-09-30'), $finance, CarbonImmutable::parse('2026-10-02'));
        expect(thrownBy(fn () => $payouts->pay($statement->id, null, $finance, CarbonImmutable::parse('2026-10-05')), PermissionDenied::class))->toBeInstanceOf(PermissionDenied::class);
        $payouts->pay($statement->id, null, $accountant, CarbonImmutable::parse('2026-10-05'));

        expect(DB::table('commission_statements')->where('id', $statement->id)->value('status'))->toBe('paid')
            ->and(DB::table('audit_events')->where('action', 'sod.warning')->count())->toBe(0);
    });
});
