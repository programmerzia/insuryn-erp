<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * Flow fix X8 (Part A step 7): approving a claim payment, the payee is looked up among the parties, and a payee who is not one yet (a garage)
 * is created inline — by whoever may approve payments on that claim, without party.manage, audited like any new party.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->userWith = fn (array $permissions, string $scopeType = 'tenant', ?string $scopeId = null): User => asTenant($this->ctx['tenant_id'],
        fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions), $scopeType, $scopeId)));
    $this->claimId = asTenant($this->ctx['tenant_id'], function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);
        $claim = app(ClaimService::class)->register($policy->id, CarbonImmutable::parse('2026-09-05'), 'Collision', $this->world['admin'], CarbonImmutable::parse('2026-09-06'));
        app(ClaimService::class)->reserve($claim->id, 20_000_000, 'Initial', userWithPermissions($this->ctx['tenant_id'], ['claim.reserve']), CarbonImmutable::parse('2026-09-06'));

        return $claim->id;
    });
});

it('looks up any party as a payee for claim approvers only', function (): void {
    actingAs(($this->userWith)(['claim.approve']))->getJson('/lookup/payee?q=rahi', $this->headers)->assertOk()
        ->assertJsonPath('results.0.id', $this->world['policyholder_id'])->assertJsonPath('results.0.label', 'Rahima Akter');
    actingAs(($this->userWith)(['claim.approve']))->getJson('/lookup/payee?q=jamal', $this->headers)->assertOk()->assertJsonPath('results.0.label', 'Jamal Agent');
    actingAs(($this->userWith)(['claim.reserve', 'party.manage']))->getJson('/lookup/payee?q=rahi', $this->headers)->assertForbidden();
});

it('creates a payee inline for a claim approver, audited, and the payment can be approved to it', function (): void {
    $manager = ($this->userWith)(['claim.approve']);

    $response = actingAs($manager)->postJson('/lookup/payee', ['claim_id' => $this->claimId, 'display_name' => 'Rahman Motors', 'kind' => 'organization', 'tax_id' => null, 'role' => 'vendor'], $this->headers)
        ->assertCreated()->assertJsonPath('result.label', 'Rahman Motors')->assertJsonPath('result.detail', 'Organization · vendor');
    $partyId = (string) $response->json('result.id');

    asTenant($this->ctx['tenant_id'], function () use ($partyId, $manager): void {
        expect(DB::table('party_roles')->where('party_id', $partyId)->pluck('role')->all())->toBe(['vendor'])
            ->and(DB::table('audit_events')->where('object_type', 'party')->where('object_id', $partyId)->where('action', 'party.created')->first(['actor_user_id', 'permission']))
            ->toEqual((object) ['actor_user_id' => $manager->id, 'permission' => 'claim.approve']);
    });
    actingAs($manager)->post("/claims/{$this->claimId}/payments", ['amount' => '50,000.00', 'payee_party_id' => $partyId, 'on' => '2026-09-14'], $this->headers)->assertSessionHasNoErrors();
    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('claim_payments')->value('payee_party_id')))->toBe($partyId);
});

it('refuses payee creation without claim approval on that claim, with a role other than vendor or beneficiary, or for an unknown claim', function (): void {
    $body = ['claim_id' => $this->claimId, 'display_name' => 'Rahman Motors', 'kind' => 'organization', 'role' => 'vendor'];

    actingAs(($this->userWith)(['claim.reserve', 'claim.pay_request']))->postJson('/lookup/payee', $body, $this->headers)->assertForbidden();
    actingAs(($this->userWith)(['party.manage']))->postJson('/lookup/payee', $body, $this->headers)->assertForbidden();
    $otherBranch = asTenant($this->ctx['tenant_id'], function (): string {
        DB::table('branches')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => 'CTG', 'name' => 'Chattogram', 'status' => 'active']);

        return $id;
    });
    actingAs(($this->userWith)(['claim.approve'], 'branch', $otherBranch))->postJson('/lookup/payee', $body, $this->headers)->assertForbidden();

    $manager = ($this->userWith)(['claim.approve']);
    actingAs($manager)->postJson('/lookup/payee', [...$body, 'role' => 'customer'], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('role');
    actingAs($manager)->postJson('/lookup/payee', [...$body, 'display_name' => ''], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('display_name');
    actingAs($manager)->postJson('/lookup/payee', [...$body, 'claim_id' => (string) Str::uuid7()], $this->headers)->assertNotFound();

    expect(asTenant($this->ctx['tenant_id'], fn (): int => DB::table('parties')->where('display_name', 'Rahman Motors')->count()))->toBe(0);
});
