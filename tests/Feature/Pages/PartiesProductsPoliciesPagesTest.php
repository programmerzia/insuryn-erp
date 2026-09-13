<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Slice 1C.8 operations screens (spec §11 Phase 1 "customer can run daily operations"): parties and agents, products and versions, policies from
 * quote to issue, endorsement and cancellation — Inertia pages over the same application services as the API. A page is open to users holding
 * any permission of its area; business-rule refusals return to the form with the reason.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->admin = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $this->world['admin']));
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
});

it('opens each area only to users holding one of its permissions', function (): void {
    $claimsOfficer = ($this->userWith)(['claim.register']);
    $branchOfficer = ($this->userWith)(['policy.create']);

    get('/policies', $this->headers)->assertRedirect('/login');
    foreach (['/parties', '/agents', '/products', '/policies', '/policies/create'] as $page) {
        actingAs($claimsOfficer)->get($page, $this->headers)->assertForbidden();
        actingAs($branchOfficer)->get($page, $this->headers)->assertOk();
    }
    actingAs($branchOfficer)->get('/', $this->headers)->assertRedirect();
});

it('lists, searches and creates parties, their bank accounts and agents', function (): void {
    actingAs($this->admin)->get('/parties?search=rahima', $this->headers)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('parties/Index')->has('parties.data', 1)->where('parties.data.0.display_name', 'Rahima Akter'));

    $response = actingAs($this->admin)->post('/parties', ['kind' => 'organization', 'display_name' => 'Acme Garments Ltd', 'tax_id' => 'TIN-99', 'roles' => ['customer', 'policyholder']], $this->headers);
    $partyId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('parties')->where('display_name', 'Acme Garments Ltd')->value('id'));
    $response->assertRedirect("/parties/{$partyId}")->assertSessionHas('status');

    actingAs($this->admin)->post("/parties/{$partyId}/bank-accounts", ['bank_name' => 'City Bank', 'account_number' => '1234567890', 'is_default' => true], $this->headers)->assertRedirect();
    actingAs($this->admin)->get("/parties/{$partyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('parties/Show')
        ->where('party.display_name', 'Acme Garments Ltd')->where('party.roles', ['customer', 'policyholder'])->has('bankAccounts', 1)->where('bankAccounts.0.account_no_masked', '******7890'));

    actingAs($this->admin)->post('/agents', ['party_id' => $partyId, 'code' => 'AG-002', 'branch_id' => $this->ctx['branch_id']], $this->headers)->assertRedirect('/agents');
    actingAs($this->admin)->get('/agents', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('agents/Index')->has('agents', 2));
});

it('lists products with their versions and creates a product and a version', function (): void {
    actingAs($this->admin)->post('/products', ['code' => 'FIRE', 'name' => 'Fire and Allied Perils', 'lob' => 'fire'], $this->headers)->assertRedirect('/products');
    $productId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('products')->where('code', 'FIRE')->value('id'));

    actingAs($this->admin)->post("/products/{$productId}/versions", ['effective_from' => '2026-01-01', 'term_months' => 12, 'earning_method' => 'daily_365',
        'tax_type' => 'VAT', 'jurisdiction' => 'BD', 'inclusive' => true, 'refund_tax_on_cancellation' => true], $this->headers)->assertRedirect('/products')->assertSessionHasNoErrors();

    actingAs($this->admin)->get('/products', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('products/Index')->has('products', 2)
        ->where('products.0.code', 'FIRE')->has('products.0.versions', 1)->where('products.0.versions.0.earning_method', 'daily_365')->has('earningMethods', 2));
});

it('quotes a policy with payers from the form and takes it through issue, endorsement and cancellation', function (): void {
    actingAs($this->admin)->get('/policies/create', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('policies/Create')
        ->has('branches', 1)->has('products', 1)->has('parties', 2)->has('agents', 1));

    actingAs($this->admin)->post('/policies', ['branch_id' => $this->ctx['branch_id'], 'product_id' => $this->world['product_id'], 'policyholder_party_id' => $this->world['policyholder_id'],
        'agent_id' => $this->world['agent_id'], 'inception' => '2026-09-01', 'premium' => '120,000.00', 'installment_count' => 2], $this->headers);
    $policyId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('policies')->value('id'));
    expect(asTenant($this->ctx['tenant_id'], fn () => (int) DB::table('policies')->value('gross_premium_minor')))->toBe(12_000_000);

    actingAs($this->admin)->post("/policies/{$policyId}/issue", ['on' => '2026-09-01'], $this->headers)->assertRedirect("/policies/{$policyId}")->assertSessionHas('status');
    actingAs($this->admin)->post("/policies/{$policyId}/endorse", ['effective_date' => '2025-01-01', 'premium_delta' => '1,000.00', 'reason' => 'too early'], $this->headers)
        ->assertRedirect()->assertSessionHasErrors('form');
    actingAs($this->admin)->post("/policies/{$policyId}/endorse", ['effective_date' => '2026-10-01', 'premium_delta' => '-1,000.00', 'reason' => 'discount'], $this->headers)->assertSessionHasNoErrors();

    actingAs($this->admin)->get("/policies/{$policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('policies/Show')
        ->where('policy.status', 'issued')->where('policy.gross_premium', '119,000.00')->has('transactions', 2)->has('installments', 2)->has('payers', 1)
        ->where('actions.issue', false)->where('actions.cancel', true));

    actingAs($this->admin)->post("/policies/{$policyId}/cancel", ['cancel_date' => '2026-12-01', 'reason' => 'sold'], $this->headers)->assertSessionHasNoErrors();
    actingAs($this->admin)->get('/policies?status=cancelled', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('policies/Index')
        ->has('policies.data', 1)->where('policies.data.0.status', 'cancelled'));
});

it('keeps the JSON API contract for business-rule refusals', function (): void {
    $policyId = asTenant($this->ctx['tenant_id'], fn (): string => app(App\Modules\Insurance\Policy\Application\PolicyLifecycle::class)->quote(new App\Modules\Insurance\Policy\Application\QuoteRequest(
        $this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'], null, Carbon\CarbonImmutable::parse('2026-09-01'), 1_000_000, 'BDT'), $this->world['admin'])->id);

    actingAs($this->admin)->postJson("/api/insurance/policies/{$policyId}/cancel", ['cancel_date' => '2026-10-01', 'reason' => 'x'], $this->headers)
        ->assertStatus(422)->assertJsonPath('reason', 'INVALID_POLICY_TRANSITION');
});
