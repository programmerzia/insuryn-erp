<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Finance\Bank\Application\BankAccountService;
use App\Modules\Insurance\Collections\Application\AgentCashPositionQuery;
use App\Modules\Insurance\Collections\Application\AgentDepositService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spec §4 "Agent cash collection with deposit reconciliation": cash an agent collects moves premium receivable into agent_receivable (the agent owes
 * the company the cash) until the agent deposits it in the bank; the cash position compares collections, deposits and the ledger per agent.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    [$this->policyId, $this->installments] = asTenant($this->ctx['tenant_id'], function (): array {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 3), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return [$policy->id, DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->pluck('id')->map(fn ($id): string => (string) $id)->all()];
    });
    $this->collect = fn (int $amount, int $installment, string $date, string $channel = 'cash', ?string $agentId = null, ?int $allocate = null) => app(ReceiptService::class)->record(
        new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, $channel, $amount, 'BDT', CarbonImmutable::parse($date), null, 'field collection',
            [new AllocationLine($this->installments[$installment], $allocate ?? $amount)], null, $agentId ?? $this->world['agent_id']), $this->world['admin']);
    $this->lines = fn (string $eventType): array => DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.description', $eventType)
        ->orderBy('j.id')->orderBy('l.line_no')->get(['l.role_code', 'l.side', 'l.amount_minor', 'l.dim_agent'])
        ->map(fn (object $l): array => [(string) $l->role_code, (string) $l->side, (int) $l->amount_minor, $l->dim_agent])->all();
});

it('moves premium an agent collects in cash into the agent receivable, not the bank', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        ($this->collect)(4_000_000, 0, '2026-09-10');

        expect(($this->lines)('AGENT_CASH_COLLECTED'))->toBe([
            ['agent_receivable', 'debit', 4_000_000, $this->world['agent_id']],
            ['premium_receivable', 'credit', 4_000_000, $this->world['agent_id']],
        ])
            ->and(DB::table('accounting_events')->where('event_type', 'PREMIUM_RECEIVED')->count())->toBe(0)
            ->and(DB::table('journal_lines')->where('role_code', 'bank_main')->count())->toBe(0)
            ->and((int) DB::table('installments')->where('id', $this->installments[0])->value('paid_minor'))->toBe(4_000_000)
            ->and(DB::table('receipts')->value('collected_by_agent_id'))->toBe($this->world['agent_id']);
    });
});

it('accepts agent cash collections only fully allocated and from a known agent', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        expect(thrownBy(fn () => ($this->collect)(4_000_000, 0, '2026-09-10', 'cash', null, 3_000_000), BusinessRuleViolation::class)->reasonCode)->toBe('AGENT_COLLECTION_UNALLOCATED')
            ->and(thrownBy(fn () => ($this->collect)(4_000_000, 0, '2026-09-10', 'cash', (string) Str::uuid7()), BusinessRuleViolation::class)->reasonCode)->toBe('UNKNOWN_AGENT')
            ->and(thrownBy(fn () => ($this->collect)(4_000_000, 0, '2026-09-10', 'mobile_money', (string) Str::uuid7()), BusinessRuleViolation::class)->reasonCode)->toBe('UNKNOWN_AGENT')
            ->and(DB::table('receipts')->count())->toBe(0);
    });
});

it('records the agent on a mobile-money or cheque collection, which reaches the bank and is not agent cash (GA-38, A-194)', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        ($this->collect)(4_000_000, 0, '2026-09-10', 'mobile_money', null, 3_000_000); // part may wait in suspense: the money is the company's, not the agent's
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cheque', 1_000_000, 'BDT', CarbonImmutable::parse('2026-09-11'), null, 'chq',
            [new AllocationLine($this->installments[1], 1_000_000)], new App\Modules\Insurance\Collections\Application\ChequeDetails('000777', 'Sonali Bank', CarbonImmutable::parse('2026-09-11')),
            $this->world['agent_id']), $this->world['admin']);

        expect(DB::table('receipts')->whereNotNull('collected_by_agent_id')->orderBy('value_date')->pluck('channel')->all())->toBe(['mobile_money', 'cheque'])
            ->and(DB::table('accounting_events')->where('event_type', 'AGENT_CASH_COLLECTED')->count())->toBe(0)
            ->and(DB::table('accounting_events')->where('event_type', 'PREMIUM_RECEIVED')->count())->toBe(2)
            ->and(DB::table('journal_lines')->where('role_code', 'agent_receivable')->count())->toBe(0)
            ->and(app(AgentCashPositionQuery::class)->undepositedMinor($this->world['agent_id']))->toBe(0);
    });
});

it('records deposits against the agent\'s undeposited cash and reconciles collections, deposits and the ledger per agent', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $gl = (string) Str::uuid7();
        DB::table('accounts')->insert(['id' => $gl, 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => '1013', 'name' => 'Bank - Collections',
            'type' => 'asset', 'normal_side' => 'debit', 'is_postable' => true, 'is_control' => false, 'status' => 'active']);
        $bankAccount = app(BankAccountService::class)->create($this->ctx['entity_id'], $gl, 'Collections Bank', '****7', 'BDT', $this->world['admin']);
        ($this->collect)(4_000_000, 0, '2026-09-10');
        ($this->collect)(1_500_000, 1, '2026-09-12');
        $deposits = app(AgentDepositService::class);

        expect(thrownBy(fn () => $deposits->record($this->world['agent_id'], 5_500_001, $bankAccount->id, 'DEP-1', $this->world['admin'], CarbonImmutable::parse('2026-09-13')), BusinessRuleViolation::class)->reasonCode)
            ->toBe('DEPOSIT_EXCEEDS_UNDEPOSITED_CASH');

        $deposit = $deposits->record($this->world['agent_id'], 4_000_000, $bankAccount->id, 'DEP-1', $this->world['admin'], CarbonImmutable::parse('2026-09-13'));
        expect($deposit->number)->toBe('ADP-HO-2026-000002') // 000001 was reserved by the refused deposit above (voided when it expires)
            ->and(($this->lines)('AGENT_DEPOSIT_RECORDED'))->toBe([['bank_main', 'debit', 4_000_000, $this->world['agent_id']], ['agent_receivable', 'credit', 4_000_000, $this->world['agent_id']]])
            ->and(DB::table('journal_lines')->where('role_code', 'bank_main')->value('account_id'))->toBe($gl);

        $position = app(AgentCashPositionQuery::class)->position($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-20'));
        $early = app(AgentCashPositionQuery::class)->position($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-11'));
        expect($position['rows'])->toBe([[
            'agent_id' => $this->world['agent_id'], 'agent_code' => 'AG-001', 'collected_minor' => 5_500_000, 'deposited_minor' => 4_000_000,
            'undeposited_minor' => 1_500_000, 'gl_minor' => 1_500_000, 'difference_minor' => 0, 'oldest_undeposited_on' => '2026-09-12', 'days_undeposited' => 8,
        ]])
            ->and([$early['rows'][0]['collected_minor'], $early['rows'][0]['deposited_minor'], $early['rows'][0]['gl_minor']])->toBe([4_000_000, 0, 4_000_000])
            ->and($position['totals'])->toBe(['collected_minor' => 5_500_000, 'deposited_minor' => 4_000_000, 'undeposited_minor' => 1_500_000, 'gl_minor' => 1_500_000]);

        app(ReconciliationService::class)->runAll((string) DB::table('fiscal_periods')->where('ends', '2026-09-30')->value('id'));
        expect(DB::table('reconciliation_runs')->where('status', 'variance')->count())->toBe(0);
    });
});

it('exposes agent collections, deposits and the cash position over the API', function (): void {
    $headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
    $cashier = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['receipt.create', 'receipt.allocate'])));
    $reader = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['reports.financial'])));

    Pest\Laravel\actingAs($cashier)->postJson('/api/insurance/receipts', ['branch_id' => $this->ctx['branch_id'], 'channel' => 'cash', 'amount_minor' => 1_000_000, 'value_date' => '2026-09-10',
        'collected_by_agent_id' => $this->world['agent_id'], 'allocations' => [['installment_id' => $this->installments[0], 'amount_minor' => 1_000_000]]], $headers)->assertCreated();
    Pest\Laravel\actingAs($reader)->postJson("/api/insurance/agents/{$this->world['agent_id']}/deposits", ['amount_minor' => 600_000, 'deposited_on' => '2026-09-11'], $headers)->assertForbidden();
    Pest\Laravel\actingAs($cashier)->postJson("/api/insurance/agents/{$this->world['agent_id']}/deposits", ['amount_minor' => 600_000, 'deposited_on' => '2026-09-11', 'reference' => 'slip 42'], $headers)
        ->assertCreated()->assertJsonPath('data.amount_minor', 600_000);
    Pest\Laravel\actingAs($cashier)->getJson("/api/reports/agent-cash?entity_id={$this->ctx['entity_id']}&as_of=2026-09-30", $headers)->assertForbidden();
    Pest\Laravel\actingAs($reader)->getJson("/api/reports/agent-cash?entity_id={$this->ctx['entity_id']}&as_of=2026-09-30", $headers)->assertOk()->assertJsonPath('data.totals.undeposited_minor', 400_000);
});
