<?php

declare(strict_types=1);

use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design §5.4 policy state machine and §4.1 / §4.4: issue, endorse and cancel each emit their accounting
 * event exactly once (idempotency key from the policy transaction); cancellation releases the pro-rata
 * unearned premium, reverses VAT per D-06, credits the outstanding receivable and owes the rest as a refund.
 * Events post through the sync queue in tests, so the journals are checked end to end.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
});

function lifecycle(): PolicyLifecycle
{
    return app(PolicyLifecycle::class);
}

/**
 * @param array{admin: string, product_id: string, product_version_id: string, policyholder_id: string, agent_id: string, agent_party_id: string} $world
 * @param array{tenant_id: string, entity_id: string, branch_id: string, book_id: string, accounts: array<string, string>} $ctx
 */
function quoteFor(array $ctx, array $world, int $grossPremium = 12_000_000, int $installments = 2, ?string $agentId = 'default'): string
{
    return lifecycle()->quote(new QuoteRequest(
        entityId: $ctx['entity_id'], branchId: $ctx['branch_id'], productId: $world['product_id'], policyholderPartyId: $world['policyholder_id'],
        agentId: $agentId === 'default' ? $world['agent_id'] : $agentId, inception: CarbonImmutable::parse('2026-09-01'),
        premiumMinor: $grossPremium, currency: 'BDT', installmentCount: $installments,
    ), $world['admin'])->id;
}

/** @return list<array{role: string, side: string, amount: int}> */
function journalLinesFor(string $eventType, string $policyId): array
{
    return array_values(array_map(fn (object $l): array => ['role' => (string) $l->role_code, 'side' => (string) $l->side, 'amount' => (int) $l->amount_minor],
        DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('journal_batches as b', 'b.id', '=', 'j.batch_id')
            ->join('accounting_events as e', 'e.id', '=', 'b.accounting_event_id')
            ->where('e.event_type', $eventType)->where('l.dim_policy', $policyId)->orderBy('j.posted_at')->orderBy('l.line_no')
            ->get(['l.role_code', 'l.side', 'l.amount_minor'])->all()));
}

it('issues a quote with a number, tax split, installments and one POLICY_ISSUED journal', function (): void {
    $world = seedInsuranceWorld($this->ctx);

    asTenant($this->ctx['tenant_id'], function () use ($world): void {
        $policyId = quoteFor($this->ctx, $world);
        $policy = lifecycle()->issue($policyId, CarbonImmutable::parse('2026-08-25'), $world['admin']);

        expect($policy->status->value)->toBe('issued')
            ->and($policy->number)->toStartWith('POL-2026-')
            ->and($policy->gross_premium_minor)->toBe(12_000_000)
            ->and($policy->net_premium_minor)->toBe(10_434_783)
            ->and($policy->tax_minor)->toBe(1_565_217)
            ->and($policy->expiry->toDateString())->toBe('2027-08-31')
            ->and(DB::table('installments')->where('policy_id', $policyId)->orderBy('no')->pluck('amount_minor')->map(fn ($a) => (int) $a)->all())->toBe([6_000_000, 6_000_000])
            ->and(DB::table('document_numbers')->where('object_id', $policyId)->value('status'))->toBe('used')
            ->and(DB::table('accounting_events')->where('event_type', 'POLICY_ISSUED')->where('source_id', $policyId)->count())->toBe(0) // source is the policy transaction
            ->and(DB::table('accounting_events')->where('event_type', 'POLICY_ISSUED')->count())->toBe(1)
            ->and(DB::table('accounting_events')->where('event_type', 'POLICY_ISSUED')->value('idempotency_key'))->toStartWith('POLICY_ISSUED:');

        expect(journalLinesFor('POLICY_ISSUED', $policyId))->toBe([
            ['role' => 'premium_receivable', 'side' => 'debit', 'amount' => 12_000_000],
            ['role' => 'unearned_premium', 'side' => 'credit', 'amount' => 10_434_783],
            ['role' => 'premium_tax_payable', 'side' => 'credit', 'amount' => 1_565_217],
        ]);

        expect(thrownBy(fn () => lifecycle()->issue($policyId, CarbonImmutable::parse('2026-08-25'), $world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('INVALID_POLICY_TRANSITION')
            ->and(DB::table('accounting_events')->where('event_type', 'POLICY_ISSUED')->count())->toBe(1);
    });
});

it('moves through activation and expiry by date, and refuses transitions outside the state machine', function (): void {
    $world = seedInsuranceWorld($this->ctx);

    asTenant($this->ctx['tenant_id'], function () use ($world): void {
        $policyId = quoteFor($this->ctx, $world);
        expect(thrownBy(fn () => lifecycle()->endorse($policyId, CarbonImmutable::parse('2026-10-01'), 100_000, 'more cover', $world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('INVALID_POLICY_TRANSITION');

        lifecycle()->issue($policyId, CarbonImmutable::parse('2026-08-25'), $world['admin']);
        expect(lifecycle()->activateDue(CarbonImmutable::parse('2026-08-31')))->toBe(0)
            ->and(lifecycle()->activateDue(CarbonImmutable::parse('2026-09-01')))->toBe(1)
            ->and(DB::table('policies')->where('id', $policyId)->value('status'))->toBe('active');

        lifecycle()->lapse($policyId, 'installment 2 unpaid', $world['admin']);
        expect(DB::table('policies')->where('id', $policyId)->value('status'))->toBe('lapsed');
        lifecycle()->reinstate($policyId, 'paid in grace period', $world['admin']);
        expect(DB::table('policies')->where('id', $policyId)->value('status'))->toBe('active');

        expect(lifecycle()->expireDue(CarbonImmutable::parse('2027-08-31')))->toBe(0)
            ->and(lifecycle()->expireDue(CarbonImmutable::parse('2027-09-01')))->toBe(1)
            ->and(DB::table('policies')->where('id', $policyId)->value('status'))->toBe('expired')
            ->and(thrownBy(fn () => lifecycle()->cancel($policyId, CarbonImmutable::parse('2027-09-02'), 'late', $world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('INVALID_POLICY_TRANSITION');

        $renewalId = lifecycle()->renew($policyId, $world['admin'])->id;
        expect(DB::table('policies')->where('id', $policyId)->value('status'))->toBe('renewed')
            ->and(DB::table('policies')->where('id', $renewalId)->value('status'))->toBe('quote')
            ->and(DB::table('policies')->where('id', $renewalId)->value('inception'))->toBe('2027-09-01');
    });
});

it('endorses with a version bump, a policy transaction and one POLICY_ENDORSED journal for the delta', function (): void {
    $world = seedInsuranceWorld($this->ctx);

    asTenant($this->ctx['tenant_id'], function () use ($world): void {
        $policyId = quoteFor($this->ctx, $world);
        lifecycle()->issue($policyId, CarbonImmutable::parse('2026-08-25'), $world['admin']);

        $policy = lifecycle()->endorse($policyId, CarbonImmutable::parse('2026-12-01'), 1_150_000, 'added windscreen cover', $world['admin']);

        expect($policy->version)->toBe(2)
            ->and($policy->gross_premium_minor)->toBe(13_150_000)
            ->and($policy->net_premium_minor)->toBe(11_434_783)
            ->and($policy->tax_minor)->toBe(1_715_217)
            ->and(DB::table('policy_transactions')->where('policy_id', $policyId)->orderBy('created_at')->pluck('type')->all())->toBe(['new', 'endorsement'])
            ->and((int) DB::table('installments')->where('policy_id', $policyId)->sum('amount_minor'))->toBe(13_150_000)
            ->and(DB::table('accounting_events')->where('event_type', 'POLICY_ENDORSED')->count())->toBe(1)
            ->and(journalLinesFor('POLICY_ENDORSED', $policyId))->toBe([
                ['role' => 'premium_receivable', 'side' => 'debit', 'amount' => 1_150_000],
                ['role' => 'unearned_premium', 'side' => 'credit', 'amount' => 1_000_000],
                ['role' => 'premium_tax_payable', 'side' => 'credit', 'amount' => 150_000],
            ]);
    });
});

it('cancels pro-rata (design §4.4): releases unearned premium, reverses VAT, credits the receivable and owes the refund', function (): void {
    $world = seedInsuranceWorld($this->ctx, 'monthly', refundTaxOnCancellation: true);

    asTenant($this->ctx['tenant_id'], function () use ($world): void {
        $policyId = quoteFor($this->ctx, $world, installments: 1);
        lifecycle()->issue($policyId, CarbonImmutable::parse('2026-08-25'), $world['admin']);
        DB::table('installments')->where('policy_id', $policyId)->update(['paid_minor' => 7_000_000, 'status' => 'partially_paid']); // receipts arrive in slice 1A.5

        $policy = lifecycle()->cancel($policyId, CarbonImmutable::parse('2026-12-01'), 'customer sold the vehicle', $world['admin']);

        $transaction = DB::table('policy_transactions')->where('policy_id', $policyId)->where('type', 'cancellation')->first();
        expect($policy->status->value)->toBe('cancelled')
            ->and(json_decode((string) $transaction?->amounts, true))->toEqual([ // jsonb does not keep key order
                'earned_to_date' => 2_608_695, 'unearned_remaining' => 7_826_088, 'tax_reversal' => 1_173_913,
                'receivable_outstanding' => 5_000_000, 'refund_due' => 4_000_001,
            ])
            ->and(journalLinesFor('POLICY_CANCELLED', $policyId))->toBe([
                ['role' => 'unearned_premium', 'side' => 'debit', 'amount' => 7_826_088],
                ['role' => 'premium_tax_payable', 'side' => 'debit', 'amount' => 1_173_913],
                ['role' => 'premium_receivable', 'side' => 'credit', 'amount' => 5_000_000],
                ['role' => 'customer_refund_payable', 'side' => 'credit', 'amount' => 4_000_001],
            ])
            ->and((int) DB::table('installments')->where('policy_id', $policyId)->value('cancelled_minor'))->toBe(5_000_000)
            ->and(DB::table('accounting_events')->where('event_type', 'POLICY_CANCELLED')->count())->toBe(1);
    });
});

it('keeps VAT when the product version does not refund tax on cancellation (D-06)', function (): void {
    $world = seedInsuranceWorld($this->ctx, 'monthly', refundTaxOnCancellation: false);

    asTenant($this->ctx['tenant_id'], function () use ($world): void {
        $policyId = quoteFor($this->ctx, $world, installments: 1);
        lifecycle()->issue($policyId, CarbonImmutable::parse('2026-08-25'), $world['admin']);
        DB::table('installments')->where('policy_id', $policyId)->update(['paid_minor' => 7_000_000, 'status' => 'partially_paid']);

        lifecycle()->cancel($policyId, CarbonImmutable::parse('2026-12-01'), 'customer request', $world['admin']);

        expect(journalLinesFor('POLICY_CANCELLED', $policyId))->toBe([
            ['role' => 'unearned_premium', 'side' => 'debit', 'amount' => 7_826_088],
            ['role' => 'premium_receivable', 'side' => 'credit', 'amount' => 5_000_000],
            ['role' => 'customer_refund_payable', 'side' => 'credit', 'amount' => 2_826_088],
        ]);
    });
});

it('refuses issuing without a tax rate for the product jurisdiction instead of assuming zero tax', function (): void {
    $world = seedInsuranceWorld($this->ctx);

    asTenant($this->ctx['tenant_id'], function () use ($world): void {
        DB::table('tax_rates')->delete();

        expect(thrownBy(fn () => quoteFor($this->ctx, $world), BusinessRuleViolation::class)->reasonCode)->toBe('TAX_RATE_MISSING');
    });
});

it('exposes the lifecycle over the API with the policy permissions', function (): void {
    $world = seedInsuranceWorld($this->ctx);
    $headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
    $admin = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail($world['admin']));
    $quoter = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['policy.create'])));

    $policyId = (string) Pest\Laravel\actingAs($quoter)->postJson('/api/insurance/policies', [
        'branch_id' => $this->ctx['branch_id'], 'product_id' => $world['product_id'], 'policyholder_party_id' => $world['policyholder_id'],
        'agent_id' => $world['agent_id'], 'inception' => '2026-09-01', 'premium_minor' => 12_000_000, 'installment_count' => 2,
    ], $headers)->assertCreated()->assertJsonPath('data.status', 'quote')->json('data.id');

    Pest\Laravel\actingAs($quoter)->postJson("/api/insurance/policies/{$policyId}/issue", ['on' => '2026-08-25'], $headers)->assertForbidden();
    Pest\Laravel\actingAs($admin)->postJson("/api/insurance/policies/{$policyId}/issue", ['on' => '2026-08-25'], $headers)->assertOk()->assertJsonPath('data.status', 'issued');
    Pest\Laravel\actingAs($admin)->postJson("/api/insurance/policies/{$policyId}/cancel", ['cancel_date' => '2026-12-01', 'reason' => 'sold'], $headers)
        ->assertOk()->assertJsonPath('data.status', 'cancelled');
    Pest\Laravel\actingAs($admin)->getJson("/api/insurance/policies/{$policyId}", $headers)
        ->assertOk()->assertJsonCount(2, 'data.transactions')->assertJsonCount(2, 'data.installments');
});
