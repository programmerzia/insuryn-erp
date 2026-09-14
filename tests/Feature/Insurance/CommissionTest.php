<?php

declare(strict_types=1);

use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Collections\Application\SuspenseService;
use App\Modules\Insurance\Commission\Application\CommissionPlanService;
use App\Modules\Insurance\Commission\Application\CommissionStatementQuery;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Policy\Domain\PremiumMath;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design §4.5 commission earned on receipt (10% on 50,000, 5% withholding), §4.4 event C clawback on the unearned share, §2.4
 * commission_entries, §5.6 entry states; spec §4 Commission "earned on receipt; clawback netting; withholding tax via tax engine".
 * Gap audit GA-42 (D-70): the base is the net premium inside the money allocated, without the 15% VAT the test product includes, so the expected
 * figures are 10% of the net share (`$this->commission`) instead of 10% of the cash; the rates, withholding and postings are checked as before.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $planner = userWithPermissions($this->ctx['tenant_id'], ['commission.manage_plans']);
    $this->planId = asTenant($this->ctx['tenant_id'], function () use ($planner): string {
        DB::table('tax_rates')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'jurisdiction' => 'BD', 'tax_type' => 'AIT_COMMISSION',
            'rate_bp' => 500, 'inclusive' => false, 'withholding' => true, 'effective_from' => '2026-01-01']);

        return app(CommissionPlanService::class)->create('MOTOR-10', 'Motor 10%', 1000, 'BD', 'AIT_COMMISSION', $planner)->id;
    });
    $this->world = seedInsuranceWorld($this->ctx, 'monthly', true, $this->planId);
    $this->issue = function (?string $agentId, int $installments = 2): array {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], $agentId, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', $installments), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return [$policy->id, DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->pluck('id')->map(fn ($id): string => (string) $id)->all()];
    };
    // GA-42: the net premium inside $cash allocated to a policy of this world (12,000.00 gross, VAT inclusive), and 10% commission with 5% withheld on it.
    $this->netShare = function (int $cash): int {
        $policy = DB::table('policies')->orderBy('created_at')->first(['net_premium_minor', 'gross_premium_minor']);

        return PremiumMath::prorate($cash, (int) $policy?->net_premium_minor, (int) $policy?->gross_premium_minor);
    };
    $this->commission = function (int $cash, int $rateBp = 1000, int $withholdingBp = 500): array {
        $amount = PremiumMath::prorate(($this->netShare)($cash), $rateBp, 10_000);

        return [$amount, PremiumMath::prorate($amount, $withholdingBp, 10_000)];
    };
    $this->receive = function (int $amount, array $allocations, string $date = '2026-09-15'): void {
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', $amount, 'BDT',
            CarbonImmutable::parse($date), null, 'ref', array_values(array_filter($allocations, fn (mixed $line): bool => $line instanceof AllocationLine))), $this->world['admin']);
    };
});

/** @return list<array{role: string, side: string, amount: int}> */
function commissionLines(string $eventType): array
{
    return array_values(array_map(fn (object $l): array => ['role' => (string) $l->role_code, 'side' => (string) $l->side, 'amount' => (int) $l->amount_minor],
        DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.description', $eventType)
            ->orderBy('j.id')->orderBy('l.line_no')->get(['l.role_code', 'l.side', 'l.amount_minor'])->all()));
}

it('earns commission with withholding when premium is allocated (§4.5)', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        [$policyId, $installments] = ($this->issue)($this->world['agent_id']);
        ($this->receive)(5_000_000, [new AllocationLine($installments[0], 5_000_000)]);
        $entry = DB::table('commission_entries')->first();
        [$amount, $withholding] = ($this->commission)(5_000_000);

        expect(($this->netShare)(5_000_000))->toBeLessThan(5_000_000)
            ->and(DB::table('commission_entries')->count())->toBe(1)
            ->and([$entry?->kind, (int) $entry?->base_minor, (int) $entry?->rate_bp, (int) $entry?->amount_minor, (int) $entry?->withholding_minor, $entry?->status])
                ->toBe(['earned', ($this->netShare)(5_000_000), 1000, $amount, $withholding, 'accrued'])
            ->and($entry?->agent_id)->toBe($this->world['agent_id'])
            ->and($entry?->policy_id)->toBe($policyId)
            ->and($entry?->receipt_allocation_id)->toBe(DB::table('receipt_allocations')->value('id'))
            ->and(DB::table('accounting_events')->where('event_type', 'COMMISSION_EARNED')->value('idempotency_key'))->toBe('COMMISSION_EARNED:'.$entry?->id)
            ->and(commissionLines('COMMISSION_EARNED'))->toBe([
                ['role' => 'commission_expense', 'side' => 'debit', 'amount' => $amount],
                ['role' => 'commission_payable', 'side' => 'credit', 'amount' => $amount - $withholding],
                ['role' => 'commission_withholding_payable', 'side' => 'credit', 'amount' => $withholding],
            ]);
    });
});

it('earns commission when suspense is allocated, on the allocation date', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        [, $installments] = ($this->issue)($this->world['agent_id']);
        ($this->receive)(1_000_000, []);
        app(SuspenseService::class)->allocate((string) DB::table('suspense_items')->value('id'), $installments[0], 1_000_000, $this->world['admin'], CarbonImmutable::parse('2026-09-20'));

        expect((int) DB::table('commission_entries')->value('amount_minor'))->toBe(($this->commission)(1_000_000)[0])
            ->and(DB::table('commission_entries')->value('earned_on'))->toBe('2026-09-20')
            ->and(DB::table('accounting_events')->where('event_type', 'COMMISSION_EARNED')->value('transaction_date'))->toBe('2026-09-20');
    });
});

it('earns nothing for direct business or when no plan applies', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        [, $direct] = ($this->issue)(null);
        ($this->receive)(1_000_000, [new AllocationLine($direct[0], 1_000_000)]);

        DB::table('product_versions')->update(['commission_plan_id' => null]);
        DB::table('producers')->update(['commission_plan_id' => null]);
        [, $unplanned] = ($this->issue)($this->world['agent_id']);
        ($this->receive)(1_000_000, [new AllocationLine($unplanned[0], 1_000_000)]);

        expect(DB::table('commission_entries')->count())->toBe(0)
            ->and(DB::table('accounting_events')->where('event_type', 'COMMISSION_EARNED')->count())->toBe(0);
    });
});

it('takes the product version plan before the agent plan by default, configurably', function (): void {
    $planner = userWithPermissions($this->ctx['tenant_id'], ['commission.manage_plans']);

    asTenant($this->ctx['tenant_id'], function () use ($planner): void {
        $agentPlan = app(CommissionPlanService::class)->create('AGENT-7', 'Agent 7%', 700, null, null, $planner);
        DB::table('producers')->update(['commission_plan_id' => $agentPlan->id]);
        [, $installments] = ($this->issue)($this->world['agent_id'], 3);

        ($this->receive)(1_000_000, [new AllocationLine($installments[0], 1_000_000)]);
        config(['erp.commission.plan_precedence' => ['agent', 'product_version']]);
        ($this->receive)(1_000_000, [new AllocationLine($installments[1], 1_000_000)]);

        expect(DB::table('commission_entries')->orderBy('id')->get(['rate_bp', 'withholding_minor'])->map(fn (object $e): array => [(int) $e->rate_bp, (int) $e->withholding_minor])->all())
            ->toBe([[1000, ($this->commission)(1_000_000)[1]], [700, 0]]);
    });
});

it('claws back commission on the unearned share when the policy is cancelled (§4.4 event C)', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        [$policyId, $installments] = ($this->issue)($this->world['agent_id']);
        ($this->receive)(12_000_000, [new AllocationLine($installments[0], 6_000_000), new AllocationLine($installments[1], 6_000_000)]);
        app(PolicyLifecycle::class)->cancel($policyId, CarbonImmutable::parse('2026-12-01'), 'sold', $this->world['admin']);

        $policy = DB::table('policies')->where('id', $policyId)->first();
        $cancellation = DB::table('policy_transactions')->where('policy_id', $policyId)->where('type', 'cancellation')->first();
        $unearned = (int) json_decode((string) $cancellation?->amounts, true)['unearned_remaining'];
        [$earned, $earnedWithholding] = ($this->commission)(6_000_000);
        $amount = PremiumMath::prorate(2 * $earned, $unearned, (int) $policy?->net_premium_minor);
        $withholding = PremiumMath::prorate(2 * $earnedWithholding, $unearned, (int) $policy?->net_premium_minor);
        $clawback = DB::table('commission_entries')->where('kind', 'clawback')->first();

        expect([(int) $clawback?->amount_minor, (int) $clawback?->withholding_minor, $clawback?->policy_transaction_id])->toBe([-$amount, -$withholding, $cancellation?->id])
            ->and(DB::table('accounting_events')->where('event_type', 'COMMISSION_CLAWBACK')->value('idempotency_key'))->toBe('COMMISSION_CLAWBACK:'.$clawback?->id)
            ->and(DB::table('accounting_events')->where('event_type', 'COMMISSION_CLAWBACK')->value('transaction_date'))->toBe('2026-12-01')
            ->and(commissionLines('COMMISSION_CLAWBACK'))->toBe([
                ['role' => 'commission_payable', 'side' => 'debit', 'amount' => $amount - $withholding],
                ['role' => 'commission_withholding_payable', 'side' => 'debit', 'amount' => $withholding],
                ['role' => 'commission_expense', 'side' => 'credit', 'amount' => $amount],
            ]);

        $payableGl = (int) DB::table('journal_lines')->where('account_id', $this->ctx['accounts']['commission_payable'])->where('dim_agent', $this->world['agent_id'])
            ->selectRaw("sum(case when side = 'credit' then amount_minor else -amount_minor end) as bal")->value('bal');
        expect($payableGl)->toBe((int) DB::table('commission_entries')->selectRaw('sum(amount_minor - withholding_minor) as net')->value('net'));
    });
});

it('claws back nothing when no commission was earned on the policy', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        [$policyId] = ($this->issue)($this->world['agent_id']);
        app(PolicyLifecycle::class)->cancel($policyId, CarbonImmutable::parse('2026-12-01'), 'sold', $this->world['admin']);

        expect(DB::table('commission_entries')->count())->toBe(0);
    });
});

it('manages plans under commission.manage_plans with valid rates', function (): void {
    $clerk = userWithPermissions($this->ctx['tenant_id'], ['policy.create']);
    $planner = userWithPermissions($this->ctx['tenant_id'], ['commission.manage_plans']);

    asTenant($this->ctx['tenant_id'], function () use ($clerk, $planner): void {
        $plans = app(CommissionPlanService::class);

        expect(fn () => $plans->create('X', 'X', 100, null, null, $clerk))->toThrow(PermissionDenied::class)
            ->and(thrownBy(fn () => $plans->create('X', 'X', 10_001, null, null, $planner), BusinessRuleViolation::class)->reasonCode)->toBe('INVALID_RATE')
            ->and(thrownBy(fn () => $plans->create('X', 'X', 100, 'BD', null, $planner), BusinessRuleViolation::class)->reasonCode)->toBe('INVALID_WITHHOLDING')
            ->and(thrownBy(fn () => $plans->create('MOTOR-10', 'dup', 100, null, null, $planner), BusinessRuleViolation::class)->reasonCode)->toBe('DUPLICATE_PLAN_CODE');
    });
});

it('produces an agent statement with earned, clawed back, withholding and net totals', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        [$policyId, $installments] = ($this->issue)($this->world['agent_id']);
        ($this->receive)(6_000_000, [new AllocationLine($installments[0], 6_000_000)], '2026-09-15');
        ($this->receive)(6_000_000, [new AllocationLine($installments[1], 6_000_000)], '2026-10-15');
        app(PolicyLifecycle::class)->cancel($policyId, CarbonImmutable::parse('2026-12-01'), 'sold', $this->world['admin']);

        $october = app(CommissionStatementQuery::class)->statement($this->world['agent_id'], CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-12-31'));
        $clawback = (int) DB::table('commission_entries')->where('kind', 'clawback')->value('amount_minor');
        $clawbackWithholding = (int) DB::table('commission_entries')->where('kind', 'clawback')->value('withholding_minor');
        [$earned, $withheld] = ($this->commission)(6_000_000);

        expect(array_column($october['entries'], 'kind'))->toBe(['earned', 'clawback'])
            ->and($october['entries'][0]['policy_number'])->toStartWith('POL-')
            ->and($october['totals'])->toBe([
                'earned_minor' => $earned, 'clawback_minor' => $clawback, 'withholding_minor' => $withheld + $clawbackWithholding,
                'net_minor' => $earned + $clawback - $withheld - $clawbackWithholding,
            ])
            ->and($october['opening_payable_minor'])->toBe($earned - $withheld)
            ->and($october['closing_payable_minor'])->toBe(2 * ($earned - $withheld) + $clawback - $clawbackWithholding);
    });
});

it('exposes plans and the agent statement over the API', function (): void {
    $headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
    $planner = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['commission.manage_plans'])));
    $reader = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['reports.financial'])));

    Pest\Laravel\actingAs($reader)->postJson('/api/insurance/commission-plans', ['code' => 'P2', 'name' => 'Plan 2', 'rate_bp' => 800], $headers)->assertForbidden();
    Pest\Laravel\actingAs($planner)->postJson('/api/insurance/commission-plans', ['code' => 'P2', 'name' => 'Plan 2', 'rate_bp' => 800], $headers)
        ->assertCreated()->assertJsonPath('data.rate_bp', 800);
    Pest\Laravel\actingAs($planner)->getJson("/api/insurance/agents/{$this->world['agent_id']}/commission-statement?from=2026-09-01&to=2026-09-30", $headers)->assertForbidden();
    Pest\Laravel\actingAs($reader)->getJson("/api/insurance/agents/{$this->world['agent_id']}/commission-statement?from=2026-09-01&to=2026-09-30", $headers)
        ->assertOk()->assertJsonPath('data.totals.net_minor', 0);
});
