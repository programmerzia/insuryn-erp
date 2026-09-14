<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use App\Modules\Insurance\Underwriting\Application\UnderwritingLimits;
use App\Modules\Insurance\Underwriting\Domain\Models\Proposal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Slice R7 screens: the proposal page issues the policy (premium received with its reference unless the product issues on credit) with the journal shown first;
 * the policy page has a Rating tab and endorses the risk with a live re-rating and a journal preview; the quote form lists only products without a rating plan.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->world = ratedProductsWorld($this->ctx);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->officer = ($this->in)(function (): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'email' => 'rafiq@example.test', 'name' => 'Rafiq Officer',
            'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', 'branch_officer')->value('id'),
            'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);
        app(UnderwritingLimits::class)->set('branch_officer', 'motor', 200_000_000, CarbonImmutable::today(), $this->world['admin']);

        return User::query()->findOrFail($id);
    });
    $this->admin = ($this->in)(fn (): User => User::query()->findOrFail($this->world['admin']));
    $this->proposal = ($this->in)(function (): Proposal {
        $quotations = app(QuotationService::class);
        $terms = new QuotationTerms($this->ctx['branch_id'], $this->world['motor_product_id'], $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-09-15'),
            $this->world['motor_inputs'], ['passenger_liability']);
        $quotation = $quotations->issue($quotations->saveDraft($terms, null, $this->officer->id)->id, CarbonImmutable::today(), $this->officer->id);
        $proposals = app(ProposalService::class);
        $proposal = $proposals->createFromQuotation($quotation->id, $this->officer->id);
        $proposals->verifyKyc($proposal->id, 'nid', '1990123456789', $this->officer->id);

        return $proposals->submit($proposal->id, $this->officer->id);
    });
});

it('issues the policy from the proposal page after showing its journal, and refuses without the premium received on a product not issued on credit', function (): void {
    $id = $this->proposal->id;
    actingAs($this->officer)->get("/proposals/{$id}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('proposals/Show')
        ->where('can.issue_policy', true)->where('policyIssue', ['allow_credit' => false, 'valid_until' => '2026-09-29', 'policy' => null]));

    actingAs($this->officer)->post("/proposals/{$id}/issue-policy", ['on' => '2026-09-15', 'installment_count' => 1, 'premium_received' => false], $this->headers)
        ->assertSessionHasErrors('form');
    actingAs($this->officer)->postJson("/proposals/{$id}/issue-policy", ['on' => '2026-09-15', 'installment_count' => 1, 'premium_received' => true, 'premium_reference' => 'TRF 88'],
        [...$this->headers, 'X-Journal-Preview' => '1'])->assertOk()
        ->assertJsonPath('journals.0.event', 'POLICY_ISSUED')
        ->assertJsonPath('journals.0.lines.*.role', ['premium_receivable', 'unearned_premium', 'premium_tax_payable', 'stamp_duty_payable'])
        ->assertJsonPath('journals.0.lines.3.credit', '50.00')->assertJsonPath('journals.0.totals.debit', '30,918.30');
    expect(($this->in)(fn () => [DB::table('policies')->count(), DB::table('proposals')->where('id', $id)->value('status')]))->toBe([0, 'approved']);

    $response = actingAs($this->officer)->post("/proposals/{$id}/issue-policy", ['on' => '2026-09-15', 'installment_count' => 2, 'premium_received' => true, 'premium_reference' => 'TRF 88'], $this->headers);
    $policy = ($this->in)(fn (): Policy => Policy::query()->where('proposal_id', $id)->firstOrFail());
    $response->assertSessionHasNoErrors()->assertRedirect("/policies/{$policy->id}")->assertSessionHas('status', 'Policy POL-HO-2026-000001 issued.')
        // Flow fix X1: the premium receipt is offered next, prefilled from the policy.
        ->assertSessionHas('next', ['label' => 'Record receipt', 'url' => "/receipts/create?policy={$policy->id}", 'prompt' => 'Record the premium receipt?']);
    actingAs($this->officer)->get("/proposals/{$id}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('can.issue_policy', false)->where('policyIssue.policy', ['id' => $policy->id, 'number' => 'POL-HO-2026-000001']));
});

it('shows the frozen rating on the policy page and endorses the risk with a live re-rating and a journal preview', function (): void {
    $policyId = ($this->in)(fn (): string => app(App\Modules\Insurance\Policy\Application\PolicyLifecycle::class)
        ->issueFromProposal($this->proposal->id, CarbonImmutable::today(), $this->officer->id, 1, 'CHQ 1001')->id);
    actingAs($this->admin)->get("/policies/{$policyId}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('policies/Show')
        ->where('actions.endorse', false)->where('actions.endorse_risk', true)->where('policy.stamp_duty', '50.00')
        ->where('rating.result.plan.code', 'MOTOR-TARIFF')->where('rating.result.gross_premium_minor', 3_091_830)->where('rating.issue_basis', 'premium_received')
        ->where('rating.premium_received_reference', 'CHQ 1001')->where('rating.proposal.number', 'PRP-HO-2026-000001')->where('rating.quotation.number', 'QUO-HO-2026-000001')
        ->where('rating.risk.0', ['label_en' => 'Vehicle type', 'label_bn' => 'যানবাহনের ধরন', 'value' => 'Private car'])
        ->where('rating.endorsements', [])->where('rating.uses_current_tariff', false)->where('rating.current_inputs.driver_age', 23)
        ->where('rating.chosen_coverages', ['own_damage', 'third_party', 'passenger_liability']));

    $inputs = [...$this->world['motor_inputs'], 'driver_age' => 30];
    actingAs($this->admin)->postJson("/policies/{$policyId}/endorsement-rating", ['effective_date' => '2026-10-15', 'risk_inputs' => [...$inputs, 'engine_cc' => 'big']], $this->headers)
        ->assertStatus(422)->assertJsonPath('reason', 'RISK_INPUTS_INVALID')->assertJsonPath('errors.engine_cc', 'NOT_INTEGER');
    actingAs($this->admin)->postJson("/policies/{$policyId}/endorsement-rating", ['effective_date' => '2026-10-15', 'risk_inputs' => $inputs], $this->headers)->assertOk()
        ->assertJsonPath('rating.basis', 'original_plan')->assertJsonPath('rating.change', ['net_minor' => -244_000, 'tax_minor' => -36_600, 'stamp_duty_minor' => 0, 'gross_minor' => -280_600])
        ->assertJsonPath('rating.before.gross_premium_minor', 3_091_830)->assertJsonPath('rating.after.gross_premium_minor', 2_811_230);
    // Typing a premium change is refused for a rated policy.
    actingAs($this->admin)->post("/policies/{$policyId}/endorse", ['effective_date' => '2026-10-15', 'premium_delta' => '1,000.00', 'reason' => 'Typed'], $this->headers)->assertSessionHasErrors('form');

    $endorsement = ['effective_date' => '2026-10-15', 'risk_inputs' => $inputs, 'reason' => 'Named driver changed'];
    actingAs($this->admin)->postJson("/policies/{$policyId}/endorse-risk", $endorsement, [...$this->headers, 'X-Journal-Preview' => '1'])->assertOk()
        ->assertJsonPath('journals.0.event', 'POLICY_ENDORSED')->assertJsonPath('journals.0.lines.*.role', ['premium_receivable', 'unearned_premium', 'premium_tax_payable'])
        ->assertJsonPath('journals.0.totals.debit', '2,806.00');
    actingAs($this->admin)->post("/policies/{$policyId}/endorse-risk", $endorsement, $this->headers)->assertSessionHasNoErrors()->assertRedirect("/policies/{$policyId}?tab=rating");
    actingAs($this->admin)->get("/policies/{$policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('policy.gross_premium', '28,112.30')->where('policy.version', 2)->where('rating.result.gross_premium_minor', 3_091_830)
        ->where('rating.endorsements.0.reason', 'Named driver changed')->where('rating.endorsements.0.rating.basis', 'original_plan')
        ->where('rating.endorsements.0.rating.change.gross_minor', -280_600)->where('rating.endorsements.0.rating.pro_rata', false)
        ->where('rating.endorsements.0.rating.after.net_premium_minor', 2_440_200)->where('rating.current_inputs.driver_age', 30));
});

it('lists only products without a rating plan on the quote form and points to Quotes for the rest', function (): void {
    actingAs($this->admin)->get('/policies/create', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('policies/Create')
        ->where('products', fn ($products): bool => array_column((array) json_decode((string) json_encode($products), true), 'code') === ['MOTOR'])->where('ratedProducts', 2));
});

it('offers the typed-premium form on the policies list only while a product has no rating plan (GA-11)', function (): void {
    actingAs($this->admin)->get('/policies', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('policies/Index')->where('unratedProducts', 1));
    ($this->in)(fn () => DB::table('products')->whereNotExists(fn ($q) => $q->from('product_versions as v')->whereColumn('v.product_id', 'products.id')->whereNotNull('v.class_code'))
        ->get(['id'])->each(fn (object $p) => DB::table('product_versions')->where('product_id', $p->id)->update(['class_code' => 'motor'])));
    actingAs($this->admin)->get('/policies', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('unratedProducts', 0));
});
