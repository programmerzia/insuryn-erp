<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * Design §2.4 parties / party_roles / party_bank_accounts / agents; spec §3 party model: one party, many
 * roles; KYC and bank accounts attach to the party; agents are not employees.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->officer = partyUser($this->ctx['tenant_id'], ['party.manage', 'agent.manage']);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
});

/** @param list<string> $permissions */
function partyUser(string $tenantId, array $permissions): User
{
    return asTenant($tenantId, fn (): User => User::query()->findOrFail(userWithPermissions($tenantId, $permissions)));
}

it('creates a party with several roles and reads it back', function (): void {
    $response = actingAs($this->officer)->postJson('/api/insurance/parties', [
        'kind' => 'individual', 'display_name' => 'Rahima Akter', 'tax_id' => '1234567890',
        'roles' => ['customer', 'policyholder'],
    ], $this->headers)->assertCreated();

    $partyId = (string) $response->json('data.id');
    actingAs($this->officer)->getJson("/api/insurance/parties/{$partyId}", $this->headers)
        ->assertOk()
        ->assertJsonPath('data.display_name', 'Rahima Akter')
        ->assertJsonPath('data.kind', 'individual')
        ->assertJsonPath('data.roles', ['customer', 'policyholder']);

    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('audit_events')->where('object_id', $partyId)->value('action')))->toBe('party.created');
});

it('validates party input', function (): void {
    actingAs($this->officer)->postJson('/api/insurance/parties', ['kind' => 'robot', 'display_name' => '', 'roles' => ['astronaut']], $this->headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['kind', 'display_name', 'roles.0']);
});

it('updates a party and adds or removes roles', function (): void {
    $partyId = (string) actingAs($this->officer)->postJson('/api/insurance/parties', ['kind' => 'organization', 'display_name' => 'Acme Traders', 'roles' => ['customer']], $this->headers)->json('data.id');

    actingAs($this->officer)->patchJson("/api/insurance/parties/{$partyId}", ['display_name' => 'Acme Traders Ltd', 'roles' => ['customer', 'insured']], $this->headers)
        ->assertOk()->assertJsonPath('data.display_name', 'Acme Traders Ltd')->assertJsonPath('data.roles', ['customer', 'insured']);

    actingAs($this->officer)->patchJson("/api/insurance/parties/{$partyId}", ['roles' => ['insured']], $this->headers)
        ->assertOk()->assertJsonPath('data.roles', ['insured']);
});

it('stores bank account numbers encrypted and only ever returns them masked', function (): void {
    $partyId = (string) actingAs($this->officer)->postJson('/api/insurance/parties', ['kind' => 'individual', 'display_name' => 'Karim', 'roles' => ['customer']], $this->headers)->json('data.id');

    actingAs($this->officer)->postJson("/api/insurance/parties/{$partyId}/bank-accounts", ['bank_name' => 'Sonali Bank', 'account_no' => '0012-3456-7890', 'is_default' => true], $this->headers)
        ->assertCreated()
        ->assertJsonPath('data.account_no_masked', '********7890')
        ->assertJsonMissingPath('data.account_no')
        ->assertJsonMissingPath('data.account_no_enc');

    $second = actingAs($this->officer)->postJson("/api/insurance/parties/{$partyId}/bank-accounts", ['bank_name' => 'BRAC Bank', 'account_no' => '5555111122223333', 'is_default' => true], $this->headers)->json('data.id');

    asTenant($this->ctx['tenant_id'], function () use ($partyId, $second): void {
        $rows = DB::table('party_bank_accounts')->where('party_id', $partyId)->get();
        $stored = $rows->pluck('account_no_enc')->implode(' ');
        expect($rows)->toHaveCount(2)
            ->and(str_contains($stored, '7890') || str_contains($stored, '3333'))->toBeFalse()
            ->and($rows->where('is_default', true)->pluck('id')->all())->toBe([$second]);
    });

    actingAs($this->officer)->getJson("/api/insurance/parties/{$partyId}", $this->headers)
        ->assertJsonPath('data.bank_accounts.1.account_no_masked', '************3333')
        ->assertJsonPath('data.bank_accounts.1.is_default', true);
});

it('requires party.manage and never shows another tenant\'s parties', function (): void {
    $viewerOnly = partyUser($this->ctx['tenant_id'], ['policy.create']);
    actingAs($viewerOnly)->postJson('/api/insurance/parties', ['kind' => 'individual', 'display_name' => 'X', 'roles' => ['customer']], $this->headers)->assertForbidden();

    $partyId = (string) actingAs($this->officer)->postJson('/api/insurance/parties', ['kind' => 'individual', 'display_name' => 'Tenant A person', 'roles' => ['customer']], $this->headers)->json('data.id');
    $other = seedDemoTenant('party-other');
    $otherOfficer = partyUser($other['tenant_id'], ['party.manage']);

    actingAs($otherOfficer)->getJson("/api/insurance/parties/{$partyId}", ['X-Tenant' => $other['tenant_id'], 'Accept' => 'application/json'])->assertNotFound();
});

it('builds an agent hierarchy under branches and refuses cycles', function (): void {
    $party = fn (string $name): string => (string) actingAs($this->officer)->postJson('/api/insurance/parties', ['kind' => 'individual', 'display_name' => $name, 'roles' => ['customer']], $this->headers)->json('data.id');

    $leader = actingAs($this->officer)->postJson('/api/insurance/agents', ['party_id' => $party('Team Leader'), 'code' => 'AG-001', 'branch_id' => $this->ctx['branch_id']], $this->headers)
        ->assertCreated()->json('data.id');
    $member = actingAs($this->officer)->postJson('/api/insurance/agents', ['party_id' => $party('Field Agent'), 'code' => 'AG-002', 'branch_id' => $this->ctx['branch_id'], 'parent_agent_id' => $leader], $this->headers)
        ->assertCreated()->assertJsonPath('data.parent_agent_id', $leader)->json('data.id');
    $trainee = actingAs($this->officer)->postJson('/api/insurance/agents', ['party_id' => $party('Trainee'), 'code' => 'AG-003', 'branch_id' => $this->ctx['branch_id'], 'parent_agent_id' => $member], $this->headers)
        ->assertCreated()->json('data.id');

    // the agent role is added to the party; agents are parties, not employees
    actingAs($this->officer)->getJson("/api/insurance/agents/{$trainee}", $this->headers)
        ->assertOk()->assertJsonPath('data.ancestors', [$member, $leader])->assertJsonPath('data.party.roles', ['agent', 'customer']);

    actingAs($this->officer)->patchJson("/api/insurance/agents/{$leader}", ['parent_agent_id' => $trainee], $this->headers)
        ->assertUnprocessable()->assertJsonPath('reason', 'AGENT_HIERARCHY_CYCLE');
    actingAs($this->officer)->patchJson("/api/insurance/agents/{$leader}", ['parent_agent_id' => $leader], $this->headers)
        ->assertUnprocessable()->assertJsonPath('reason', 'AGENT_HIERARCHY_CYCLE');
    actingAs($this->officer)->postJson('/api/insurance/agents', ['party_id' => $party('Duplicate code'), 'code' => 'AG-001', 'branch_id' => $this->ctx['branch_id']], $this->headers)
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);
    actingAs($this->officer)->postJson('/api/insurance/agents', ['party_id' => (string) Str::uuid7(), 'code' => 'AG-009', 'branch_id' => $this->ctx['branch_id']], $this->headers)
        ->assertUnprocessable()->assertJsonValidationErrors(['party_id']);

    actingAs($this->officer)->getJson('/api/insurance/agents', $this->headers)->assertOk()->assertJsonCount(3, 'data');
});
