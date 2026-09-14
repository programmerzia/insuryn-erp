<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Flow fix X6 (Part A step 1): a new quote starts with the product the user quoted last — remembered when a quotation is saved or issued and when a
 * typed-premium quote is created — so the officer no longer picks the product each time.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    $this->ctx = seedDemoTenant();
    $this->world = ratedProductsWorld($this->ctx);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->officer = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['quotation.create', 'policy.create', 'party.manage'])));
});

it('starts the quote workbench with the product of the last quotation saved', function (): void {
    actingAs($this->officer)->get('/quotations/create', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('quotations/Workbench')
        ->where('lastProductId', null));

    actingAs($this->officer)->post('/quotations', ['branch_id' => $this->ctx['branch_id'], 'product_id' => $this->world['fire_product_id'], 'customer_party_id' => $this->world['policyholder_id'],
        'inception' => '2026-09-15', 'risk_inputs' => $this->world['fire_inputs'], 'coverages' => []], $this->headers)->assertSessionHasNoErrors();
    actingAs($this->officer)->get('/quotations/create', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('lastProductId', $this->world['fire_product_id']));

    // Someone else's last product is theirs.
    $other = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['quotation.create'])));
    actingAs($other)->get('/quotations/create', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('lastProductId', null));
});

it('starts the typed-premium quote form with the product of the last quote created there', function (): void {
    actingAs($this->officer)->get('/policies/create', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('policies/Create')->where('lastProductId', null));
    actingAs($this->officer)->post('/policies', ['branch_id' => $this->ctx['branch_id'], 'product_id' => $this->world['product_id'], 'policyholder_party_id' => $this->world['policyholder_id'],
        'agent_id' => $this->world['agent_id'], 'inception' => '2026-09-15', 'premium' => '12,000.00', 'installment_count' => 1], $this->headers)->assertSessionHasNoErrors();

    expect(($this->in)(fn () => json_decode((string) DB::table('user_preferences')->where('user_id', $this->officer->id)->value('preferences'), true)['drafts']['last-product']))
        ->toBe(['value' => $this->world['product_id']]);
    actingAs($this->officer)->get('/policies/create', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('lastProductId', $this->world['product_id']));
});
