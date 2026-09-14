<?php

declare(strict_types=1);

use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Approvals\ApprovalService;
use App\Modules\Platform\Approvals\Decision;
use App\Modules\Platform\Authorization\SodViolation;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Design §5.5 claim: registered ─reserve─▶ reserved ─adjust*─▶ reserved ─approve─▶ approved ─pay(partial*)─▶ paid ─close─▶ closed;
 * reject from registered|reserved; recovery after paid; closed ─reopen(approval)─▶ reserved. §4.6 reserve (immutable history), §4.7
 * approve/pay/close (CLAIM_CLOSED releases the remaining reserve; INVARIANT Σ claims_outstanding per claim = 0 after close), §4.8 recovery.
 * §7.3 SoD: claim.reserve ✕ claim.approve on the same claim; claim.pay_request ✕ claim.pay_release. Approval limits from approval_policies.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->officer = userWithPermissions($this->ctx['tenant_id'], ['claim.register', 'claim.reserve']);
    $this->manager = userWithPermissions($this->ctx['tenant_id'], ['claim.approve', 'claim.pay_request', 'claim.close']);
    $this->finance = userWithPermissions($this->ctx['tenant_id'], ['claim.pay_release']);
    $this->policyId = asTenant($this->ctx['tenant_id'], function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::parse('2026-07-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-07-01'), $this->world['admin']);

        return $policy->id;
    });
    $this->claims = fn (): ClaimService => app(ClaimService::class);
    $this->payments = fn (): ClaimPaymentService => app(ClaimPaymentService::class);
    $this->on = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);
    $this->outstanding = fn (string $claimId): int => (int) DB::table('journal_lines')->where('account_id', $this->ctx['accounts']['claims_outstanding'])->where('dim_claim', $claimId)
        ->selectRaw("coalesce(sum(case when side = 'credit' then amount_minor else -amount_minor end), 0) as b")->value('b');
});

/** @return list<array{role: string, side: string, amount: int}> */
function claimLines(string $eventType, int $nth = 0): array
{
    $journalId = DB::table('journals')->where('description', $eventType)->orderBy('posting_date')->orderBy('id')->skip($nth)->value('id');

    return array_values(array_map(fn (object $l): array => ['role' => (string) $l->role_code, 'side' => (string) $l->side, 'amount' => (int) $l->amount_minor],
        DB::table('journal_lines')->where('journal_id', $journalId)->orderBy('line_no')->get(['role_code', 'side', 'amount_minor'])->all()));
}

it('runs a claim through reserve, adjustment, approval, payment and close (§4.6, §4.7)', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $claim = ($this->claims)()->register($this->policyId, ($this->on)('2026-09-05'), 'Rear-end collision', $this->officer, ($this->on)('2026-09-06'));
        expect($claim->status->value)->toBe('registered')->and($claim->number)->toBe('CLM-HO-2026-000001');

        ($this->claims)()->reserve($claim->id, 20_000_000, 'Initial case reserve', $this->officer, ($this->on)('2026-09-06'));
        ($this->claims)()->reserve($claim->id, 25_000_000, 'Surveyor report', $this->officer, ($this->on)('2026-09-10'));
        $payment = ($this->payments)()->approve($claim->id, 18_000_000, $this->world['policyholder_id'], $this->manager, ($this->on)('2026-09-12'));
        ($this->payments)()->requestRelease($payment->id, $this->manager, null);
        ($this->payments)()->release($payment->id, $this->finance, ($this->on)('2026-09-14'));
        ($this->claims)()->close($claim->id, 'Settled', $this->manager, ($this->on)('2026-09-20'));

        expect(DB::table('claims')->where('id', $claim->id)->value('status'))->toBe('closed')
            ->and(claimLines('CLAIM_RESERVED'))->toBe([['role' => 'claims_expense', 'side' => 'debit', 'amount' => 20_000_000], ['role' => 'claims_outstanding', 'side' => 'credit', 'amount' => 20_000_000]])
            ->and(claimLines('CLAIM_RESERVE_ADJUSTED'))->toBe([['role' => 'claims_expense', 'side' => 'debit', 'amount' => 5_000_000], ['role' => 'claims_outstanding', 'side' => 'credit', 'amount' => 5_000_000]])
            ->and(claimLines('CLAIM_APPROVED'))->toBe([['role' => 'claims_outstanding', 'side' => 'debit', 'amount' => 18_000_000], ['role' => 'claims_payable', 'side' => 'credit', 'amount' => 18_000_000]])
            ->and(claimLines('CLAIM_PAID'))->toBe([['role' => 'claims_payable', 'side' => 'debit', 'amount' => 18_000_000], ['role' => 'bank_main', 'side' => 'credit', 'amount' => 18_000_000]])
            ->and(claimLines('CLAIM_CLOSED'))->toBe([['role' => 'claims_outstanding', 'side' => 'debit', 'amount' => 7_000_000], ['role' => 'claims_expense', 'side' => 'credit', 'amount' => 7_000_000]])
            ->and(DB::table('accounting_events')->where('event_type', 'CLAIM_RESERVED')->value('idempotency_key'))->toBe("CLAIM_RESERVED:{$claim->id}:1")
            ->and(DB::table('accounting_events')->where('event_type', 'CLAIM_RESERVE_ADJUSTED')->value('idempotency_key'))->toBe("CLAIM_RESERVE_ADJUSTED:{$claim->id}:2")
            ->and(DB::table('accounting_events')->where('event_type', 'CLAIM_APPROVED')->value('idempotency_key'))->toBe("CLAIM_APPROVED:{$payment->id}")
            ->and(DB::table('accounting_events')->where('status', '<>', 'posted')->count())->toBe(0)
            ->and(($this->outstanding)($claim->id))->toBe(0)
            ->and(DB::table('claim_reserves')->where('claim_id', $claim->id)->orderBy('version')->get(['version', 'reserve_minor', 'delta_minor'])
                ->map(fn (object $r): array => [(int) $r->version, (int) $r->reserve_minor, (int) $r->delta_minor])->all())
                ->toBe([[1, 20_000_000, 20_000_000], [2, 25_000_000, 5_000_000], [3, 18_000_000, -7_000_000]]);
    });
});

it('keeps reserve history immutable in the database', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $claim = ($this->claims)()->register($this->policyId, ($this->on)('2026-09-05'), 'Glass', $this->officer, ($this->on)('2026-09-06'));
        ($this->claims)()->reserve($claim->id, 100_000, 'Initial', $this->officer, ($this->on)('2026-09-06'));

        expect(fn () => DB::table('claim_reserves')->where('claim_id', $claim->id)->update(['reserve_minor' => 1]))->toThrow(QueryException::class);
        expect(fn () => DB::table('claim_reserves')->where('claim_id', $claim->id)->delete())->toThrow(QueryException::class);
    });
});

it('posts a reserve decrease as CLAIM_RESERVE_ADJUSTED mirror lines and never reserves below what was approved', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $claim = ($this->claims)()->register($this->policyId, ($this->on)('2026-09-05'), 'Theft', $this->officer, ($this->on)('2026-09-06'));

        expect(thrownBy(fn () => ($this->claims)()->reserve($claim->id, 0, 'nothing', $this->officer, ($this->on)('2026-09-06')), BusinessRuleViolation::class)->reasonCode)->toBe('INVALID_AMOUNT');

        ($this->claims)()->reserve($claim->id, 5_000_000, 'Initial', $this->officer, ($this->on)('2026-09-06'));
        ($this->claims)()->reserve($claim->id, 3_000_000, 'Parts cheaper', $this->officer, ($this->on)('2026-09-08'));
        ($this->payments)()->approve($claim->id, 2_000_000, $this->world['policyholder_id'], $this->manager, ($this->on)('2026-09-09'));

        expect(claimLines('CLAIM_RESERVE_ADJUSTED'))->toBe([['role' => 'claims_expense', 'side' => 'credit', 'amount' => 2_000_000], ['role' => 'claims_outstanding', 'side' => 'debit', 'amount' => 2_000_000]])
            ->and(thrownBy(fn () => ($this->claims)()->reserve($claim->id, 1_999_999, 'too low', $this->officer, ($this->on)('2026-09-10')), BusinessRuleViolation::class)->reasonCode)->toBe('RESERVE_BELOW_APPROVED')
            ->and(thrownBy(fn () => ($this->payments)()->approve($claim->id, 1_000_001, $this->world['policyholder_id'], $this->manager, ($this->on)('2026-09-10')), BusinessRuleViolation::class)->reasonCode)->toBe('APPROVAL_EXCEEDS_RESERVE')
            ->and(thrownBy(fn () => ($this->claims)()->reserve($claim->id, 3_000_000, 'same', $this->officer, ($this->on)('2026-09-10')), BusinessRuleViolation::class)->reasonCode)->toBe('RESERVE_UNCHANGED');
    });
});

it('registers claims only for losses during cover of an issued policy', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $quote = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-07-01'), 1_000_000, 'BDT'), $this->world['admin']);

        expect(thrownBy(fn () => ($this->claims)()->register($this->policyId, ($this->on)('2026-06-30'), 'x', $this->officer, ($this->on)('2026-07-02')), BusinessRuleViolation::class)->reasonCode)->toBe('LOSS_OUTSIDE_COVER')
            ->and(thrownBy(fn () => ($this->claims)()->register($quote->id, ($this->on)('2026-07-05'), 'x', $this->officer, ($this->on)('2026-07-06')), BusinessRuleViolation::class)->reasonCode)->toBe('POLICY_NOT_ON_COVER')
            ->and(thrownBy(fn () => ($this->claims)()->register($this->policyId, ($this->on)('2026-09-05'), 'x', $this->officer, ($this->on)('2026-09-01')), BusinessRuleViolation::class)->reasonCode)->toBe('REPORTED_BEFORE_LOSS');

        app(PolicyLifecycle::class)->cancel($this->policyId, ($this->on)('2026-10-01'), 'sold', $this->world['admin']);
        expect(thrownBy(fn () => ($this->claims)()->register($this->policyId, ($this->on)('2026-10-02'), 'x', $this->officer, ($this->on)('2026-10-03')), BusinessRuleViolation::class)->reasonCode)->toBe('LOSS_OUTSIDE_COVER')
            ->and(($this->claims)()->register($this->policyId, ($this->on)('2026-09-30'), 'before cancellation', $this->officer, ($this->on)('2026-10-03'))->status->value)->toBe('registered');
    });
});

it('enforces segregation of duties: reserve ✕ approve on the same claim, pay request ✕ pay release', function (): void {
    $allRounder = userWithPermissions($this->ctx['tenant_id'], ['claim.register', 'claim.reserve', 'claim.approve', 'claim.pay_request', 'claim.pay_release']);

    asTenant($this->ctx['tenant_id'], function () use ($allRounder): void {
        $claim = ($this->claims)()->register($this->policyId, ($this->on)('2026-09-05'), 'Flood', $allRounder, ($this->on)('2026-09-06'));
        ($this->claims)()->reserve($claim->id, 1_000_000, 'Initial', $allRounder, ($this->on)('2026-09-06'));

        expect(fn () => ($this->payments)()->approve($claim->id, 500_000, $this->world['policyholder_id'], $allRounder, ($this->on)('2026-09-07')))->toThrow(SodViolation::class);

        $payment = ($this->payments)()->approve($claim->id, 500_000, $this->world['policyholder_id'], $this->manager, ($this->on)('2026-09-07'));
        ($this->payments)()->requestRelease($payment->id, $allRounder, null);

        expect(fn () => ($this->payments)()->release($payment->id, $allRounder, ($this->on)('2026-09-08')))->toThrow(SodViolation::class)
            ->and(DB::table('accounting_events')->where('event_type', 'CLAIM_PAID')->count())->toBe(0);

        ($this->payments)()->release($payment->id, $this->finance, ($this->on)('2026-09-08'));
        expect(DB::table('claim_payments')->where('id', $payment->id)->value('status'))->toBe('paid')
            ->and(DB::table('accounting_events')->where('event_type', 'CLAIM_PAID')->count())->toBe(1);
    });
});

it('routes approvals and payments above a limit through approval_policies', function (): void {
    approvalPolicy($this->ctx['tenant_id'], 'claim_payment', ['min_amount_minor' => 50_000_000], [['permission' => 'claim.pay_release']]);
    approvalPolicy($this->ctx['tenant_id'], 'claim_payment_release', ['min_amount_minor' => 50_000_000], [['permission' => 'periods.lock']]);
    $cfo = userWithPermissions($this->ctx['tenant_id'], ['claim.pay_release', 'periods.lock']);

    asTenant($this->ctx['tenant_id'], function () use ($cfo): void {
        $claim = ($this->claims)()->register($this->policyId, ($this->on)('2026-09-05'), 'Total loss', $this->officer, ($this->on)('2026-09-06'));
        ($this->claims)()->reserve($claim->id, 80_000_000, 'Total loss', $this->officer, ($this->on)('2026-09-06'));

        $small = ($this->payments)()->approve($claim->id, 1_000_000, $this->world['policyholder_id'], $this->manager, ($this->on)('2026-09-07'));
        $large = ($this->payments)()->approve($claim->id, 60_000_000, $this->world['policyholder_id'], $this->manager, ($this->on)('2026-09-07'));
        expect($small->status->value)->toBe('approved')
            ->and($large->status->value)->toBe('pending_approval')
            ->and(DB::table('accounting_events')->where('event_type', 'CLAIM_APPROVED')->count())->toBe(1);

        $approvals = app(ApprovalService::class);
        $approvals->decide((string) $approvals->pendingFor('claim_payment', $large->id), $cfo, Decision::Approved, null);
        expect(DB::table('claim_payments')->where('id', $large->id)->value('status'))->toBe('approved')
            ->and(DB::table('accounting_events')->where('event_type', 'CLAIM_APPROVED')->count())->toBe(2);

        ($this->payments)()->requestRelease($large->id, $this->manager, null);
        ($this->payments)()->release($large->id, $this->finance, ($this->on)('2026-09-09'));
        expect(DB::table('claim_payments')->where('id', $large->id)->value('status'))->toBe('release_pending_approval')
            ->and(DB::table('accounting_events')->where('event_type', 'CLAIM_PAID')->count())->toBe(0);

        $approvals->decide((string) $approvals->pendingFor('claim_payment_release', $large->id), $cfo, Decision::Approved, null);
        expect(DB::table('claim_payments')->where('id', $large->id)->value('status'))->toBe('paid')
            ->and(DB::table('accounting_events')->where('event_type', 'CLAIM_PAID')->value('transaction_date'))->toBe('2026-09-09');
    });
});

it('records recoveries only after payment, and rejects or reopens claims keeping claims_outstanding right', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $claim = ($this->claims)()->register($this->policyId, ($this->on)('2026-09-05'), 'Collision', $this->officer, ($this->on)('2026-09-06'));
        ($this->claims)()->reserve($claim->id, 2_000_000, 'Initial', $this->officer, ($this->on)('2026-09-06'));

        expect(thrownBy(fn () => ($this->claims)()->recover($claim->id, 'salvage', 300_000, null, 'SALV-1', $this->manager, ($this->on)('2026-09-07')), BusinessRuleViolation::class)->reasonCode)->toBe('CLAIM_NOT_PAID');

        $payment = ($this->payments)()->approve($claim->id, 2_000_000, $this->world['policyholder_id'], $this->manager, ($this->on)('2026-09-07'));
        expect(thrownBy(fn () => ($this->claims)()->close($claim->id, 'early', $this->manager, ($this->on)('2026-09-08')), BusinessRuleViolation::class)->reasonCode)->toBe('PAYMENTS_OUTSTANDING');
        ($this->payments)()->requestRelease($payment->id, $this->manager, null);
        ($this->payments)()->release($payment->id, $this->finance, ($this->on)('2026-09-08'));
        ($this->claims)()->recover($claim->id, 'salvage', 300_000, null, 'SALV-1', $this->manager, ($this->on)('2026-09-15'));
        ($this->claims)()->close($claim->id, 'Settled', $this->manager, ($this->on)('2026-09-16'));

        expect(claimLines('CLAIM_RECOVERED'))->toBe([['role' => 'bank_main', 'side' => 'debit', 'amount' => 300_000], ['role' => 'claims_recovery_income', 'side' => 'credit', 'amount' => 300_000]])
            ->and(DB::table('accounting_events')->where('event_type', 'CLAIM_CLOSED')->count())->toBe(0) // nothing left to release
            ->and(($this->outstanding)($claim->id))->toBe(0);

        ($this->claims)()->reopen($claim->id, 'Further damage found', $this->manager, ($this->on)('2026-09-20'));
        expect(DB::table('claims')->where('id', $claim->id)->value('status'))->toBe('reserved');
        ($this->claims)()->reserve($claim->id, 2_500_000, 'Additional repair', $this->officer, ($this->on)('2026-09-21'));
        expect(($this->outstanding)($claim->id))->toBe(500_000);

        $rejected = ($this->claims)()->register($this->policyId, ($this->on)('2026-09-10'), 'Fraudulent', $this->officer, ($this->on)('2026-09-11'));
        ($this->claims)()->reserve($rejected->id, 900_000, 'Initial', $this->officer, ($this->on)('2026-09-11'));
        expect(thrownBy(fn () => ($this->claims)()->reject($rejected->id, '', $this->manager, ($this->on)('2026-09-12')), BusinessRuleViolation::class)->reasonCode)->toBe('REASON_REQUIRED');
        ($this->claims)()->reject($rejected->id, 'Staged accident', $this->manager, ($this->on)('2026-09-12'));
        expect(DB::table('claims')->where('id', $rejected->id)->value('status'))->toBe('rejected')
            ->and(($this->outstanding)($rejected->id))->toBe(0);
    });
});

it('drives claims over the API with the claim permissions', function (): void {
    $headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
    $officer = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail((string) $this->officer));
    $manager = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail((string) $this->manager));

    $claimId = Pest\Laravel\actingAs($officer)->postJson('/api/insurance/claims', ['policy_id' => $this->policyId, 'loss_date' => '2026-09-05', 'reported_on' => '2026-09-06',
        'description' => 'Hail'], $headers)->assertCreated()->assertJsonPath('data.status', 'registered')->json('data.id');
    Pest\Laravel\actingAs($manager)->postJson("/api/insurance/claims/{$claimId}/reserve", ['reserve_minor' => 400_000, 'reason' => 'x', 'on' => '2026-09-06'], $headers)->assertForbidden();
    Pest\Laravel\actingAs($officer)->postJson("/api/insurance/claims/{$claimId}/reserve", ['reserve_minor' => 400_000, 'reason' => 'x', 'on' => '2026-09-06'], $headers)
        ->assertOk()->assertJsonPath('data.reserve_minor', 400_000);
    Pest\Laravel\actingAs($manager)->postJson("/api/insurance/claims/{$claimId}/payments", ['amount_minor' => 100_000, 'payee_party_id' => $this->world['policyholder_id'], 'on' => '2026-09-07'], $headers)
        ->assertCreated()->assertJsonPath('data.status', 'approved');
    Pest\Laravel\actingAs($manager)->getJson("/api/insurance/claims/{$claimId}", $headers)->assertOk()->assertJsonCount(1, 'data.reserves')->assertJsonCount(1, 'data.payments');
});

it('refuses a second reopening while one waits for approval, so no stale approval can reopen the claim later (found by 2.0d)', function (): void {
    approvalPolicy($this->ctx['tenant_id'], 'claim_reopen', ['min_amount_minor' => 1], [['permission' => 'periods.lock']]);
    $cfo = userWithPermissions($this->ctx['tenant_id'], ['periods.lock']);

    asTenant($this->ctx['tenant_id'], function () use ($cfo): void {
        $claim = ($this->claims)()->register($this->policyId, ($this->on)('2026-09-05'), 'Collision', $this->officer, ($this->on)('2026-09-06'));
        ($this->claims)()->reserve($claim->id, 1_000_000, 'Initial', $this->officer, ($this->on)('2026-09-06'));
        $payment = ($this->payments)()->approve($claim->id, 800_000, $this->world['policyholder_id'], $this->manager, ($this->on)('2026-09-07'));
        ($this->payments)()->requestRelease($payment->id, $this->manager, null);
        ($this->payments)()->release($payment->id, $this->finance, ($this->on)('2026-09-08'));
        ($this->claims)()->close($claim->id, 'Settled', $this->manager, ($this->on)('2026-09-09'));
        $approvals = app(ApprovalService::class);

        // A rejected reopening leaves the claim closed, and it can be asked for again.
        $first = ($this->claims)()->reopen($claim->id, 'Another look', $this->manager, ($this->on)('2026-09-10'));
        $approvals->decide((string) $first, $cfo, Decision::Rejected, 'No new facts');
        $second = ($this->claims)()->reopen($claim->id, 'Further damage found', $this->manager, ($this->on)('2026-09-11'));
        $approvalRows = DB::table('approvals')->count();
        $audits = DB::table('audit_events')->count();

        expect($second)->not->toBeNull()
            ->and(thrownBy(fn () => ($this->claims)()->reopen($claim->id, 'Asked again', $this->manager, ($this->on)('2026-09-12')), BusinessRuleViolation::class)->reasonCode)->toBe('REOPEN_PENDING')
            ->and(DB::table('approvals')->count())->toBe($approvalRows)
            ->and(DB::table('audit_events')->count())->toBe($audits)
            ->and(DB::table('claims')->where('id', $claim->id)->value('status'))->toBe('closed');

        $approvals->decide((string) $second, $cfo, Decision::Approved, null);
        expect(DB::table('claims')->where('id', $claim->id)->value('status'))->toBe('reserved')
            ->and(DB::table('approvals')->where('object_type', 'claim_reopen')->where('status', 'pending')->count())->toBe(0);
    });
});
