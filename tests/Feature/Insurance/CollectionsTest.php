<?php

declare(strict_types=1);

use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Collections\Application\RefundService;
use App\Modules\Insurance\Collections\Application\SuspenseQuery;
use App\Modules\Insurance\Collections\Application\SuspenseService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Authorization\SodViolation;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design §4.2 premium received, §4.9 suspense receipt then allocation, §4.4 event B refund; spec §2 suspense with
 * ageing; §7.3 receipt.refund_request ✕ receipt.refund_release.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    [$this->policyId, $this->installments] = asTenant($this->ctx['tenant_id'], function (): array {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 2), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return [$policy->id, DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->pluck('id')->map(fn ($id): string => (string) $id)->all()];
    });
});

/**
 * @param array{tenant_id: string, entity_id: string, branch_id: string, book_id: string, accounts: array<string, string>} $ctx
 * @param list<AllocationLine> $allocations
 */
function receiptRequest(array $ctx, int $amount, array $allocations, string $reference = 'TRX-778812'): RecordReceiptRequest
{
    return new RecordReceiptRequest($ctx['entity_id'], $ctx['branch_id'], null, 'bank_transfer', $amount, 'BDT', CarbonImmutable::parse('2026-09-15'), null, $reference, $allocations);
}

/** @return list<array{role: string, side: string, amount: int}> */
function linesForEvent(string $eventType): array
{
    return array_values(array_map(fn (object $l): array => ['role' => (string) $l->role_code, 'side' => (string) $l->side, 'amount' => (int) $l->amount_minor],
        DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('journal_batches as b', 'b.id', '=', 'j.batch_id')
            ->join('accounting_events as e', 'e.id', '=', 'b.accounting_event_id')->where('e.event_type', $eventType)
            ->orderBy('j.posted_at')->orderBy('j.id')->orderBy('l.line_no')->get(['l.role_code', 'l.side', 'l.amount_minor'])->all()));
}

it('allocates a receipt to an installment and posts PREMIUM_RECEIVED per allocation (§4.2)', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $receipt = app(ReceiptService::class)->record(receiptRequest($this->ctx, 5_000_000, [new AllocationLine($this->installments[0], 5_000_000)]), $this->world['admin']);

        expect($receipt->status->value)->toBe('allocated')
            ->and($receipt->number)->toBe('RCT-HO-2026-000001')
            ->and((int) DB::table('installments')->where('id', $this->installments[0])->value('paid_minor'))->toBe(5_000_000)
            ->and(DB::table('installments')->where('id', $this->installments[0])->value('status'))->toBe('partially_paid')
            ->and(DB::table('accounting_events')->where('event_type', 'PREMIUM_RECEIVED')->value('idempotency_key'))
                ->toBe('PREMIUM_RECEIVED:'.DB::table('receipt_allocations')->where('receipt_id', $receipt->id)->value('id'))
            ->and(linesForEvent('PREMIUM_RECEIVED'))->toBe([
                ['role' => 'bank_main', 'side' => 'debit', 'amount' => 5_000_000],
                ['role' => 'premium_receivable', 'side' => 'credit', 'amount' => 5_000_000],
            ])
            ->and(DB::table('suspense_items')->count())->toBe(0);
    });
});

it('parks an unidentified receipt in suspense and posts it out on allocation (§4.9)', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $receipt = app(ReceiptService::class)->record(receiptRequest($this->ctx, 2_500_000, [], 'unreadable ref'), $this->world['admin']);
        $item = DB::table('suspense_items')->where('receipt_id', $receipt->id)->first();

        expect($receipt->status->value)->toBe('unallocated')
            ->and((int) $item?->amount_minor)->toBe(2_500_000)
            ->and(linesForEvent('RECEIPT_RECORDED'))->toBe([
                ['role' => 'bank_main', 'side' => 'debit', 'amount' => 2_500_000],
                ['role' => 'suspense_receipts', 'side' => 'credit', 'amount' => 2_500_000],
            ])
            ->and(DB::table('journal_lines')->where('role_code', 'suspense_receipts')->value('dims_ext'))->toContain($receipt->id);

        app(SuspenseService::class)->allocate((string) $item?->id, $this->installments[0], 1_000_000, $this->world['admin'], CarbonImmutable::parse('2026-09-18'));
        app(SuspenseService::class)->allocate((string) $item?->id, $this->installments[0], 1_500_000, $this->world['admin'], CarbonImmutable::parse('2026-09-18'));

        expect(DB::table('suspense_items')->where('id', $item?->id)->value('status'))->toBe('allocated')
            ->and(DB::table('receipts')->where('id', $receipt->id)->value('status'))->toBe('allocated')
            ->and(DB::table('accounting_events')->where('event_type', 'RECEIPT_ALLOCATED')->count())->toBe(2)
            ->and(array_slice(linesForEvent('RECEIPT_ALLOCATED'), 0, 2))->toBe([
                ['role' => 'suspense_receipts', 'side' => 'debit', 'amount' => 1_000_000],
                ['role' => 'premium_receivable', 'side' => 'credit', 'amount' => 1_000_000],
            ])
            ->and(fn () => app(SuspenseService::class)->allocate((string) $item?->id, $this->installments[0], 1, $this->world['admin'], CarbonImmutable::parse('2026-09-18')))->toThrow(BusinessRuleViolation::class);
    });
});

it('splits a receipt between an installment and suspense', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $receipt = app(ReceiptService::class)->record(receiptRequest($this->ctx, 6_500_000, [new AllocationLine($this->installments[0], 6_000_000)]), $this->world['admin']);

        expect($receipt->status->value)->toBe('partially_allocated')
            ->and(DB::table('installments')->where('id', $this->installments[0])->value('status'))->toBe('paid')
            ->and((int) DB::table('suspense_items')->where('receipt_id', $receipt->id)->value('amount_minor'))->toBe(500_000);
    });
});

it('refuses allocations beyond the receipt or the installment outstanding, writing nothing', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        expect(thrownBy(fn () => app(ReceiptService::class)->record(receiptRequest($this->ctx, 1_000, [new AllocationLine($this->installments[0], 1_001)]), $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('ALLOCATION_EXCEEDS_RECEIPT')
            ->and(thrownBy(fn () => app(ReceiptService::class)->record(receiptRequest($this->ctx, 7_000_000, [new AllocationLine($this->installments[0], 6_000_001)]), $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('ALLOCATION_EXCEEDS_OUTSTANDING')
            ->and(DB::table('receipts')->count())->toBe(0)
            ->and(DB::table('accounting_events')->whereIn('event_type', ['PREMIUM_RECEIVED', 'RECEIPT_RECORDED'])->count())->toBe(0);
    });
});

it('ages open suspense into buckets by days since receipt', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $service = app(ReceiptService::class);
        foreach (['2026-09-14' => 100_000, '2026-08-01' => 200_000, '2026-07-10' => 300_000] as $date => $amount) {
            $service->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', $amount, 'BDT', CarbonImmutable::parse($date), null, 'no ref', []), $this->world['admin']);
        }

        $ageing = app(SuspenseQuery::class)->ageing($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-15'));

        expect($ageing['buckets'])->toBe(['0-30' => 100_000, '31-60' => 200_000, '61-90' => 300_000, '90+' => 0])
            ->and($ageing['total_minor'])->toBe(600_000)
            ->and($ageing['items'][0]['days'])->toBe(67);
    });
});

it('releases a customer refund only by someone other than the requester, within the refund due (§4.4 event B)', function (): void {
    $requester = userWithPermissions($this->ctx['tenant_id'], ['receipt.refund_request', 'receipt.refund_release']);
    $releaser = userWithPermissions($this->ctx['tenant_id'], ['receipt.refund_release']);

    asTenant($this->ctx['tenant_id'], function () use ($requester, $releaser): void {
        app(ReceiptService::class)->record(receiptRequest($this->ctx, 12_000_000, [new AllocationLine($this->installments[0], 6_000_000), new AllocationLine($this->installments[1], 6_000_000)]), $this->world['admin']);
        app(PolicyLifecycle::class)->cancel($this->policyId, CarbonImmutable::parse('2026-12-01'), 'sold', $this->world['admin']);
        $refundDue = (int) json_decode((string) DB::table('policy_transactions')->where('policy_id', $this->policyId)->where('type', 'cancellation')->value('amounts'), true)['refund_due'];

        expect(thrownBy(fn () => app(RefundService::class)->request($this->policyId, $refundDue + 1, 'overpaid', $requester), BusinessRuleViolation::class)->reasonCode)->toBe('REFUND_EXCEEDS_DUE');

        $refund = app(RefundService::class)->request($this->policyId, $refundDue, 'policy cancelled', $requester);
        expect(fn () => app(RefundService::class)->release($refund->id, $requester, CarbonImmutable::parse('2026-12-05')))->toThrow(SodViolation::class)
            ->and(DB::table('accounting_events')->where('event_type', 'REFUND_ISSUED')->count())->toBe(0);

        app(RefundService::class)->release($refund->id, $releaser, CarbonImmutable::parse('2026-12-05'));

        expect(DB::table('refunds')->where('id', $refund->id)->value('status'))->toBe('released')
            ->and(DB::table('accounting_events')->where('event_type', 'REFUND_ISSUED')->value('transaction_date'))->toBe('2026-12-05')
            ->and(linesForEvent('REFUND_ISSUED'))->toBe([
                ['role' => 'customer_refund_payable', 'side' => 'debit', 'amount' => $refundDue],
                ['role' => 'bank_main', 'side' => 'credit', 'amount' => $refundDue],
            ])
            ->and(thrownBy(fn () => app(RefundService::class)->request($this->policyId, 1, 'again', $requester), BusinessRuleViolation::class)->reasonCode)->toBe('REFUND_EXCEEDS_DUE');
    });
});

it('frees the refund due again when a requested refund is rejected', function (): void {
    $requester = userWithPermissions($this->ctx['tenant_id'], ['receipt.refund_request']);
    $releaser = userWithPermissions($this->ctx['tenant_id'], ['receipt.refund_release']);

    asTenant($this->ctx['tenant_id'], function () use ($requester, $releaser): void {
        app(ReceiptService::class)->record(receiptRequest($this->ctx, 12_000_000, [new AllocationLine($this->installments[0], 6_000_000), new AllocationLine($this->installments[1], 6_000_000)]), $this->world['admin']);
        app(PolicyLifecycle::class)->cancel($this->policyId, CarbonImmutable::parse('2026-12-01'), 'sold', $this->world['admin']);
        $refund = app(RefundService::class)->request($this->policyId, 1_000, 'first attempt', $requester);

        expect(thrownBy(fn () => app(RefundService::class)->reject($refund->id, '', $releaser), BusinessRuleViolation::class)->reasonCode)->toBe('REASON_REQUIRED');
        app(RefundService::class)->reject($refund->id, 'wrong bank details', $releaser);

        expect(DB::table('refunds')->where('id', $refund->id)->value('status'))->toBe('rejected')
            ->and(thrownBy(fn () => app(RefundService::class)->release($refund->id, $releaser, CarbonImmutable::parse('2026-12-05')), BusinessRuleViolation::class)->reasonCode)->toBe('REFUND_NOT_REQUESTED')
            ->and(app(RefundService::class)->request($this->policyId, 1_000, 'second attempt', $requester)->status->value)->toBe('requested');
    });
});

it('records and allocates receipts over the API with the receipt permissions', function (): void {
    $headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
    $cashier = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['receipt.create'])));
    $allocator = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['receipt.allocate'])));

    $receiptId = Pest\Laravel\actingAs($cashier)->postJson('/api/insurance/receipts', [
        'branch_id' => $this->ctx['branch_id'], 'channel' => 'bank_transfer', 'amount_minor' => 300_000, 'value_date' => '2026-09-15', 'reference' => '??',
    ], $headers)->assertCreated()->assertJsonPath('data.status', 'unallocated')->json('data.id');
    $itemId = asTenant($this->ctx['tenant_id'], fn () => (string) DB::table('suspense_items')->where('receipt_id', $receiptId)->value('id'));

    Pest\Laravel\actingAs($cashier)->postJson("/api/insurance/suspense-items/{$itemId}/allocate", ['installment_id' => $this->installments[0], 'amount_minor' => 300_000], $headers)->assertForbidden();
    Pest\Laravel\actingAs($allocator)->postJson("/api/insurance/suspense-items/{$itemId}/allocate", ['installment_id' => $this->installments[0], 'amount_minor' => 300_000, 'on' => '2026-09-16'], $headers)->assertOk();
    Pest\Laravel\actingAs($allocator)->getJson('/api/insurance/suspense/ageing?as_of=2026-09-30', $headers)->assertOk()->assertJsonPath('data.total_minor', 0);
});
