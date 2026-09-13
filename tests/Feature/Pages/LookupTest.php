<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * UX brief §4 forms (slice U5): lookups for customers, agents, policies and outstanding installments type ahead by number or name, and a
 * customer can be created inline (Ctrl+N) without leaving the form.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    asTenant($this->ctx['tenant_id'], function (): void {
        $lifecycle = app(PolicyLifecycle::class);
        $issued = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 2), $this->world['admin']);
        $lifecycle->issue($issued->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);
        $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            null, CarbonImmutable::parse('2026-09-05'), 5_000_000, 'BDT'), $this->world['admin']);
    });
});

it('types ahead customers, agents, issued policies and outstanding installments', function (): void {
    $clerk = ($this->userWith)(['policy.create', 'receipt.create']);
    $number = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('policies')->whereNotNull('number')->value('number'));

    actingAs($clerk)->getJson('/lookup/customer?q=rahi', $this->headers)->assertOk()->assertJsonPath('results.0.label', 'Rahima Akter')
        ->assertJsonPath('results.0.id', $this->world['policyholder_id']);
    actingAs($clerk)->getJson('/lookup/agent?q=AG-0', $this->headers)->assertJsonPath('results.0.label', 'AG-001 · Jamal Agent');
    actingAs($clerk)->getJson('/lookup/policy?q=rahima', $this->headers)->assertJsonCount(1, 'results')->assertJsonPath('results.0.label', $number);
    actingAs($clerk)->getJson('/lookup/installment?q='.urlencode($number), $this->headers)->assertJsonCount(2, 'results')
        ->assertJsonPath('results.0.detail', fn (string $detail): bool => str_contains($detail, '60,000.00 outstanding'))
        ->assertJsonPath('results.0.amount', '60,000.00');
    actingAs($clerk)->getJson('/lookup/unknown?q=x', $this->headers)->assertNotFound();
});

it('refuses lookups outside the user\'s areas', function (): void {
    actingAs(($this->userWith)(['claim.register']))->getJson('/lookup/installment?q=POL', $this->headers)->assertForbidden();
});

it('creates a customer inline for users who manage parties', function (): void {
    actingAs(($this->userWith)(['policy.create']))->postJson('/lookup/customer', ['display_name' => 'Nazmul Huda', 'kind' => 'individual'], $this->headers)->assertForbidden();

    actingAs(($this->userWith)(['party.manage']))->postJson('/lookup/customer', ['display_name' => 'Nazmul Huda', 'kind' => 'individual', 'tax_id' => 'TIN-7'], $this->headers)
        ->assertCreated()->assertJsonPath('result.label', 'Nazmul Huda')->assertJsonPath('result.detail', 'Individual · TIN TIN-7');
    actingAs(($this->userWith)(['party.manage']))->postJson('/lookup/customer', ['kind' => 'individual'], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('display_name');

    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('party_roles')->join('parties', 'parties.id', '=', 'party_roles.party_id')
        ->where('parties.display_name', 'Nazmul Huda')->pluck('party_roles.role')->sort()->values()->all()))->toBe(['customer', 'policyholder']);
});
