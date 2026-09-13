<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ChequeBounceService;
use App\Modules\Insurance\Collections\Application\ChequeDetails;
use App\Modules\Insurance\Collections\Application\ChequeRegisterQuery;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Collections\Application\SuspenseService;
use App\Modules\Insurance\Commission\Application\CommissionPlanService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Spec §4 "Payment channels: … cheque (with cheque register and bounce handling)". A bounced cheque undoes its receipt with compensating business
 * events (never journal reversals from a business module): each allocation is reversed, the unallocated rest leaves suspense, installments are
 * unpaid again, commission earned on the money is clawed back, and every subledger still reconciles as of any date.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $planner = userWithPermissions($this->ctx['tenant_id'], ['commission.manage_plans']);
    $planId = asTenant($this->ctx['tenant_id'], fn (): string => app(CommissionPlanService::class)->create('P10', 'Plan 10%', 1000, null, null, $planner)->id);
    $this->world = seedInsuranceWorld($this->ctx, 'monthly', true, $planId);
    $this->cashier = userWithPermissions($this->ctx['tenant_id'], ['receipt.create']);
    $this->accountant = userWithPermissions($this->ctx['tenant_id'], ['receipt.allocate']);
    [$this->policyId, $this->installments] = asTenant($this->ctx['tenant_id'], function (): array {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 3), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return [$policy->id, DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->pluck('id')->map(fn ($id): string => (string) $id)->all()];
    });
    $this->cheque = fn (string $no, int $amount, array $allocations, string $date = '2026-09-10') => app(ReceiptService::class)->record(new RecordReceiptRequest(
        $this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cheque', $amount, 'BDT', CarbonImmutable::parse($date), null, "CHQ {$no}",
        array_values(array_filter($allocations, fn (mixed $a): bool => $a instanceof AllocationLine)), new ChequeDetails($no, 'Sonali Bank', CarbonImmutable::parse($date))), $this->world['admin']);
    $this->glBalance = fn (string $role): int => (int) DB::table('journal_lines')->where('account_id', $this->ctx['accounts'][$role])
        ->selectRaw("coalesce(sum(case when side = 'debit' then amount_minor else -amount_minor end), 0) as b")->value('b');
});

/** @return list<array{string, string, int}> */
function bounceLines(string $eventType): array
{
    return array_values(DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.description', $eventType)->orderBy('j.id')->orderBy('l.line_no')
        ->get(['l.role_code', 'l.side', 'l.amount_minor'])->map(fn (object $l): array => [(string) $l->role_code, (string) $l->side, (int) $l->amount_minor])->all());
}

it('requires cheque details and refuses a cheque presented twice until it bounces', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        expect(thrownBy(fn () => app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cheque', 1_000, 'BDT',
            CarbonImmutable::parse('2026-09-10'), null, null, []), $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('CHEQUE_DETAILS_REQUIRED');

        $first = ($this->cheque)('000123', 1_000_000, []);
        expect(thrownBy(fn () => ($this->cheque)('000123', 1_000_000, []), BusinessRuleViolation::class)->reasonCode)->toBe('DUPLICATE_CHEQUE');

        app(ChequeBounceService::class)->bounce($first->id, 'Insufficient funds', $this->accountant, CarbonImmutable::parse('2026-09-14'));
        expect(($this->cheque)('000123', 1_000_000, [], '2026-09-20')->status->value)->toBe('unallocated'); // re-presented
    });
});

it('undoes a bounced cheque: allocations, suspense and bank, keeping every subledger reconciled as of each date', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $receipt = ($this->cheque)('555001', 7_000_000, [new AllocationLine($this->installments[0], 4_000_000)]); // 3,000,000 to suspense
        app(SuspenseService::class)->allocate((string) DB::table('suspense_items')->value('id'), $this->installments[1], 1_000_000, $this->world['admin'], CarbonImmutable::parse('2026-09-12'));
        app(ReconciliationService::class)->runAll((string) DB::table('fiscal_periods')->where('ends', '2026-09-30')->value('id'), CarbonImmutable::parse('2026-09-13'));

        app(ChequeBounceService::class)->bounce($receipt->id, 'Refer to drawer', $this->accountant, CarbonImmutable::parse('2026-09-15'));

        expect(DB::table('receipts')->where('id', $receipt->id)->get(['status', 'bounced_on', 'bounce_reason'])->map(fn (object $r): array => (array) $r)->all())
            ->toBe([['status' => 'bounced', 'bounced_on' => '2026-09-15', 'bounce_reason' => 'Refer to drawer']])
            ->and(DB::table('installments')->whereIn('id', $this->installments)->orderBy('no')->get(['paid_minor', 'status'])->map(fn (object $i): array => [(int) $i->paid_minor, (string) $i->status])->all())
                ->toBe([[0, 'pending'], [0, 'pending'], [0, 'pending']])
            ->and(DB::table('suspense_items')->value('status'))->toBe('bounced')
            ->and(DB::table('receipt_allocations')->whereNull('reversed_on')->count())->toBe(0)
            ->and(bounceLines('PREMIUM_RECEIPT_REVERSED'))->toBe([['premium_receivable', 'debit', 4_000_000], ['bank_main', 'credit', 4_000_000]])
            ->and(bounceLines('RECEIPT_ALLOCATION_REVERSED'))->toBe([['premium_receivable', 'debit', 1_000_000], ['suspense_receipts', 'credit', 1_000_000]])
            ->and(bounceLines('RECEIPT_BOUNCED'))->toBe([['suspense_receipts', 'debit', 3_000_000], ['bank_main', 'credit', 3_000_000]])
            ->and(($this->glBalance)('bank_main'))->toBe(0)
            ->and(($this->glBalance)('suspense_receipts'))->toBe(0)
            ->and(DB::table('accounting_events')->where('status', '<>', 'posted')->count())->toBe(0);

        foreach (['2026-09-14', '2026-09-30'] as $asOf) {
            app(ReconciliationService::class)->runAll((string) DB::table('fiscal_periods')->where('ends', '2026-09-30')->value('id'), CarbonImmutable::parse($asOf));
        }
        expect(DB::table('reconciliation_runs')->where('status', 'variance')->count())->toBe(0);
    });
});

it('claws back commission earned on bounced money without double counting a later cancellation', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $receipt = ($this->cheque)('555002', 4_000_000, [new AllocationLine($this->installments[0], 4_000_000)]);
        ($this->cheque)('555003', 4_000_000, [new AllocationLine($this->installments[1], 4_000_000)]);
        app(ChequeBounceService::class)->bounce($receipt->id, 'Account closed', $this->accountant, CarbonImmutable::parse('2026-09-15'));

        $bounceClawback = DB::table('commission_entries')->where('kind', 'clawback')->whereNotNull('receipt_allocation_id')->first();
        expect([(int) $bounceClawback?->amount_minor, $bounceClawback?->earned_on])->toBe([-400_000, '2026-09-15'])
            ->and(DB::table('accounting_events')->where('event_type', 'COMMISSION_CLAWBACK')->count())->toBe(1);

        app(PolicyLifecycle::class)->cancel($this->policyId, CarbonImmutable::parse('2026-10-01'), 'sold', $this->world['admin']);
        $policy = DB::table('policies')->where('id', $this->policyId)->first();
        $unearned = (int) json_decode((string) DB::table('policy_transactions')->where('policy_id', $this->policyId)->where('type', 'cancellation')->value('amounts'), true)['unearned_remaining'];
        $cancellationClawback = (int) DB::table('commission_entries')->where('kind', 'clawback')->whereNull('receipt_allocation_id')->value('amount_minor');

        expect($cancellationClawback)->toBe(-App\Modules\Insurance\Policy\Domain\PremiumMath::prorate(400_000, $unearned, (int) $policy?->net_premium_minor));
        app(ReconciliationService::class)->runAll((string) DB::table('fiscal_periods')->where('ends', '2026-10-31')->value('id'));
        expect(DB::table('reconciliation_runs')->where('subledger', 'commission')->value('status'))->toBe('clean');
    });
});

it('refuses bounces that are not cheques, repeated, unexplained or unauthorised', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $bounce = app(ChequeBounceService::class);
        $cash = app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 1_000, 'BDT',
            CarbonImmutable::parse('2026-09-10'), null, null, []), $this->world['admin']);
        $cheque = ($this->cheque)('555004', 1_000, []);

        expect(thrownBy(fn () => $bounce->bounce($cash->id, 'x', $this->accountant, CarbonImmutable::parse('2026-09-12')), BusinessRuleViolation::class)->reasonCode)->toBe('NOT_A_CHEQUE')
            ->and(thrownBy(fn () => $bounce->bounce($cheque->id, ' ', $this->accountant, CarbonImmutable::parse('2026-09-12')), BusinessRuleViolation::class)->reasonCode)->toBe('REASON_REQUIRED')
            ->and(fn () => $bounce->bounce($cheque->id, 'x', $this->cashier, CarbonImmutable::parse('2026-09-12')))->toThrow(PermissionDenied::class)
            ->and(thrownBy(fn () => $bounce->bounce($cheque->id, 'x', $this->accountant, CarbonImmutable::parse('2026-09-09')), BusinessRuleViolation::class)->reasonCode)->toBe('BOUNCE_BEFORE_RECEIPT');

        $bounce->bounce($cheque->id, 'Signature differs', $this->accountant, CarbonImmutable::parse('2026-09-12'));
        expect(thrownBy(fn () => $bounce->bounce($cheque->id, 'again', $this->accountant, CarbonImmutable::parse('2026-09-13')), BusinessRuleViolation::class)->reasonCode)->toBe('ALREADY_BOUNCED');
    });
});

it('lists the cheque register with presented and bounced cheques, over the API too', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $bounced = ($this->cheque)('700001', 1_000_000, [], '2026-09-05');
        ($this->cheque)('700002', 2_000_000, [], '2026-09-06');
        app(ChequeBounceService::class)->bounce($bounced->id, 'Insufficient funds', $this->accountant, CarbonImmutable::parse('2026-09-08'));

        $register = app(ChequeRegisterQuery::class)->register($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
        expect(array_map(fn (array $r): array => [$r['cheque_no'], $r['state'], $r['amount_minor'], $r['bounced_on']], $register['rows']))
            ->toBe([['700001', 'bounced', 1_000_000, '2026-09-08'], ['700002', 'presented', 2_000_000, null]])
            ->and($register['totals'])->toBe(['presented_minor' => 2_000_000, 'bounced_minor' => 1_000_000]);
    });

    $headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
    $cashier = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail((string) $this->cashier));
    $accountant = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail((string) $this->accountant));
    $receiptId = Pest\Laravel\actingAs($cashier)->postJson('/api/insurance/receipts', ['branch_id' => $this->ctx['branch_id'], 'channel' => 'cheque', 'amount_minor' => 5_000,
        'value_date' => '2026-09-20', 'cheque_no' => '800001', 'cheque_bank' => 'City Bank', 'cheque_date' => '2026-09-19'], $headers)->assertCreated()->json('data.id');
    Pest\Laravel\actingAs($cashier)->getJson("/api/insurance/cheques?entity_id={$this->ctx['entity_id']}&from=2026-09-01&to=2026-09-30", $headers)->assertOk()->assertJsonCount(3, 'data.rows');
    Pest\Laravel\actingAs($cashier)->postJson("/api/insurance/receipts/{$receiptId}/bounce", ['bounced_on' => '2026-09-22', 'reason' => 'x'], $headers)->assertForbidden();
    Pest\Laravel\actingAs($accountant)->postJson("/api/insurance/receipts/{$receiptId}/bounce", ['bounced_on' => '2026-09-22', 'reason' => 'Stopped'], $headers)
        ->assertOk()->assertJsonPath('data.status', 'bounced');
});
