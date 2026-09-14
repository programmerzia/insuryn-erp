<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Commission\Application\CommissionPlanService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/** Slice 1C.10 operations screens: claims through their lifecycle, commission plans and payouts, and the approvals inbox. */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $planner = userWithPermissions($this->ctx['tenant_id'], ['commission.manage_plans']);
    $planId = asTenant($this->ctx['tenant_id'], fn (): string => app(CommissionPlanService::class)->create('P10', 'Plan 10%', 1000, null, null, $planner)->id);
    $this->world = seedInsuranceWorld($this->ctx, 'monthly', true, $planId);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    $this->policyId = asTenant($this->ctx['tenant_id'], function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return $policy->id;
    });
});

it('opens claims, commission and approvals screens to the right people', function (): void {
    $cashier = ($this->userWith)(['receipt.create']);
    $officer = ($this->userWith)(['claim.register']);
    $commission = ($this->userWith)(['commission.approve']);

    foreach (['/claims', '/claims/create'] as $page) {
        actingAs($cashier)->get($page, $this->headers)->assertForbidden();
        actingAs($officer)->get($page, $this->headers)->assertOk();
    }
    actingAs($cashier)->get('/commission', $this->headers)->assertForbidden();
    actingAs($commission)->get('/commission', $this->headers)->assertOk();
    actingAs($cashier)->get('/approvals', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('approvals/Index')->has('approvals', 0));
});

it('takes a claim from registration to close, with release by someone else', function (): void {
    $officer = ($this->userWith)(['claim.register', 'claim.reserve']);
    $manager = ($this->userWith)(['claim.approve', 'claim.pay_request', 'claim.close']);
    $finance = ($this->userWith)(['claim.pay_release']);

    actingAs($officer)->post('/claims', ['policy_id' => $this->policyId, 'loss_date' => '2026-09-05', 'reported_on' => '2026-09-06', 'description' => 'Collision'], $this->headers)->assertSessionHasNoErrors();
    $claimId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('claims')->value('id'));
    actingAs($officer)->post("/claims/{$claimId}/reserve", ['reserve' => '30,000.00', 'reason' => 'Initial', 'on' => '2026-09-06'], $this->headers)->assertSessionHasNoErrors();
    actingAs($officer)->post("/claims/{$claimId}/payments", ['amount' => '20,000.00', 'payee_party_id' => $this->world['policyholder_id'], 'on' => '2026-09-07'], $this->headers)->assertSessionHasErrors('form'); // no claim.approve
    actingAs($manager)->post("/claims/{$claimId}/payments", ['amount' => '20,000.00', 'payee_party_id' => $this->world['policyholder_id'], 'on' => '2026-09-07'], $this->headers)->assertSessionHasNoErrors();
    $paymentId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('claim_payments')->value('id'));
    actingAs($manager)->post("/claim-payments/{$paymentId}/request-release", [], $this->headers)->assertSessionHasNoErrors();
    actingAs($finance)->post("/claim-payments/{$paymentId}/release", ['paid_on' => '2026-09-08'], $this->headers)->assertSessionHasNoErrors();
    actingAs($manager)->post("/claims/{$claimId}/recover", ['type' => 'salvage', 'amount' => '1,000.00', 'received_on' => '2026-09-10'], $this->headers)->assertSessionHasNoErrors();
    actingAs($manager)->post("/claims/{$claimId}/close", ['reason' => 'Settled', 'on' => '2026-09-12'], $this->headers)->assertSessionHasNoErrors();

    actingAs($manager)->get("/claims/{$claimId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('claims/Show')
        ->where('claim.status', 'closed')->where('claim.reserve', '20,000.00')->has('reserves', 2)->where('reserves.1.delta', '-10,000.00')
        ->has('payments', 1)->where('payments.0.status', 'paid')->has('recoveries', 1)->where('actions.reopen', true)->where('actions.close', false));
    actingAs($officer)->get('/claims?status=closed', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('claims/Index')->has('claims.data', 1));
});

it('hides reopen while a reopening waits for approval and explains a second request', function (): void {
    approvalPolicy($this->ctx['tenant_id'], 'claim_reopen', ['min_amount_minor' => 1], [['permission' => 'periods.lock']]);
    $officer = ($this->userWith)(['claim.register', 'claim.reserve']);
    $manager = ($this->userWith)(['claim.approve', 'claim.pay_request', 'claim.close']);
    $finance = ($this->userWith)(['claim.pay_release']);

    actingAs($officer)->post('/claims', ['policy_id' => $this->policyId, 'loss_date' => '2026-09-05', 'reported_on' => '2026-09-06', 'description' => 'Collision'], $this->headers)->assertSessionHasNoErrors();
    $claimId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('claims')->value('id'));
    actingAs($officer)->post("/claims/{$claimId}/reserve", ['reserve' => '30,000.00', 'reason' => 'Initial', 'on' => '2026-09-06'], $this->headers)->assertSessionHasNoErrors();
    actingAs($manager)->post("/claims/{$claimId}/payments", ['amount' => '20,000.00', 'payee_party_id' => $this->world['policyholder_id'], 'on' => '2026-09-07'], $this->headers)->assertSessionHasNoErrors();
    $paymentId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('claim_payments')->value('id'));
    actingAs($manager)->post("/claim-payments/{$paymentId}/request-release", [], $this->headers)->assertSessionHasNoErrors();
    actingAs($finance)->post("/claim-payments/{$paymentId}/release", ['paid_on' => '2026-09-08'], $this->headers)->assertSessionHasNoErrors();
    actingAs($manager)->post("/claims/{$claimId}/close", ['reason' => 'Settled', 'on' => '2026-09-12'], $this->headers)->assertSessionHasNoErrors();

    actingAs($manager)->post("/claims/{$claimId}/reopen", ['reason' => 'Further damage', 'on' => '2026-09-13'], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Reopening sent for approval.');
    actingAs($manager)->get("/claims/{$claimId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('claims/Show')
        ->where('claim.status', 'closed')->where('actions.reopen', false));
    actingAs($manager)->post("/claims/{$claimId}/reopen", ['reason' => 'Asked again', 'on' => '2026-09-14'], $this->headers)
        ->assertSessionHasErrors(['form' => 'Reopening this claim is already waiting for approval. It reopens once the approver accepts it.']);
    expect(asTenant($this->ctx['tenant_id'], fn (): int => DB::table('approvals')->where('object_type', 'claim_reopen')->count()))->toBe(1);
});

it('lists pending approvals a user may decide and decides them from the inbox', function (): void {
    approvalPolicy($this->ctx['tenant_id'], 'claim_payment', ['min_amount_minor' => 5_000_000], [['permission' => 'periods.lock']]);
    $officer = ($this->userWith)(['claim.register', 'claim.reserve']);
    $manager = ($this->userWith)(['claim.approve', 'claim.pay_request']);
    $cfo = ($this->userWith)(['periods.lock']);
    actingAs($officer)->post('/claims', ['policy_id' => $this->policyId, 'loss_date' => '2026-09-05', 'reported_on' => '2026-09-06', 'description' => 'Total loss'], $this->headers);
    $claimId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('claims')->value('id'));
    actingAs($officer)->post("/claims/{$claimId}/reserve", ['reserve' => '100,000.00', 'reason' => 'Initial', 'on' => '2026-09-06'], $this->headers);
    actingAs($manager)->post("/claims/{$claimId}/payments", ['amount' => '60,000.00', 'payee_party_id' => $this->world['policyholder_id'], 'on' => '2026-09-07'], $this->headers)->assertSessionHasNoErrors();

    actingAs($manager)->get('/approvals', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->has('approvals', 0)); // requester cannot decide
    actingAs($cfo)->get('/approvals', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->has('approvals', 1)
        ->where('approvals.0.object_type', 'claim_payment')->where('approvals.0.amount', '60,000.00')->where('approvals.0.link', "/claims/{$claimId}"));
    $approvalId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('approvals')->value('id'));

    actingAs($cfo)->post("/approvals/{$approvalId}/decide", ['decision' => 'approved'], $this->headers)->assertSessionHasNoErrors();
    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('claim_payments')->value('status')))->toBe('approved');
});

it('manages commission plans and pays an approved statement by someone else', function (): void {
    $planner = ($this->userWith)(['commission.manage_plans']);
    $approver = ($this->userWith)(['commission.approve']);
    $payer = ($this->userWith)(['commission.pay']);
    asTenant($this->ctx['tenant_id'], fn () => app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 12_000_000, 'BDT',
        CarbonImmutable::parse('2026-09-10'), null, 'r', [new AllocationLine((string) DB::table('installments')->value('id'), 12_000_000)]), $this->world['admin']));

    actingAs($planner)->post('/commission/plans', ['code' => 'P12', 'name' => 'Plan 12%', 'rate_percent' => '12.50'], $this->headers)->assertSessionHasNoErrors();
    expect(asTenant($this->ctx['tenant_id'], fn () => (int) DB::table('commission_plans')->where('code', 'P12')->value('rate_bp')))->toBe(1250);

    actingAs($approver)->post('/commission/statements', ['agent_id' => $this->world['agent_id'], 'up_to' => '2026-09-30', 'on' => '2026-10-01'], $this->headers)->assertSessionHasNoErrors();
    $statementId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('commission_statements')->value('id'));
    actingAs($approver)->post("/commission/statements/{$statementId}/pay", ['paid_on' => '2026-10-02'], $this->headers)->assertSessionHasErrors('form');
    actingAs($payer)->post("/commission/statements/{$statementId}/pay", ['paid_on' => '2026-10-02'], $this->headers)->assertSessionHasNoErrors();

    actingAs($approver)->get('/commission', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('commission/Index')
        ->has('plans', 2)->has('statements', 1)->where('statements.0.status', 'paid')->where('statements.0.net', '12,000.00'));
    actingAs($approver)->get("/commission/agents/{$this->world['agent_id']}?from=2026-09-01&to=2026-10-31", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->component('commission/Statement')->has('statement.entries', 1)->where('statement.totals.net', '12,000.00')->where('statement.closing_payable', '0.00'));
});
