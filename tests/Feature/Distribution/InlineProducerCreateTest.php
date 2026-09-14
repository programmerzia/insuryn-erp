<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Quotation\Application\ProducerEligibility;
use App\Modules\Platform\Authorization\RoleTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Flow fix X9 (Part A step 1): the officer quoting motor no longer leaves the quote to add a producer (Distribution → Producers, licence, back). A holder of
 * agent.manage creates the producer from the producer lookup: party, producer on the quote's branch and the licence it needs to write new business, together.
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->userWith = fn (array $permissions): User => ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    $this->form = fn (array $overrides = []): array => ['name' => 'Kamal Hossain', 'producer_type' => 'agent', 'code' => 'AG-002', 'branch_id' => $this->ctx['branch_id'],
        'licence_no' => 'IDRA-KH-2026', 'licence_class' => 'non_life', 'issued_on' => '2026-09-01', 'expires_on' => '2027-08-31', ...$overrides];
    $this->manager = ($this->userWith)(RoleTemplates::all()['branch_manager']['permissions']);
    $this->officer = ($this->userWith)(RoleTemplates::all()['branch_officer']['permissions']);
});

it('suggests the next free code for the producer type', function (): void {
    actingAs($this->manager)->getJson('/lookup/producer/new?type=agent', $this->headers)->assertOk()->assertJson(['code' => 'AG-002', 'today' => '2026-09-15']);
    actingAs($this->manager)->getJson('/lookup/producer/new?type=broker', $this->headers)->assertOk()->assertJsonPath('code', 'BRK-001');
    actingAs($this->officer)->getJson('/lookup/producer/new?type=agent', $this->headers)->assertForbidden();
});

it('creates the party, the producer on the quote branch and its licence, audited, and returns it for the lookup', function (): void {
    actingAs($this->manager)->postJson('/lookup/producer', ($this->form)(), $this->headers)
        ->assertCreated()->assertJsonPath('result.label', 'AG-002 · Kamal Hossain');

    ($this->in)(function (): void {
        $producer = DB::table('producers')->where('code', 'AG-002')->first(['id', 'party_id', 'branch_id', 'type', 'status']) ?? throw new RuntimeException('The producer was not created.');
        expect($producer->id)->not->toBe('')
            ->and($producer->branch_id)->toBe($this->ctx['branch_id'])->and($producer->type)->toBe('agent')->and($producer->status)->toBe('active')
            ->and(DB::table('parties')->where('id', $producer->party_id)->value('display_name'))->toBe('Kamal Hossain')
            ->and(DB::table('party_roles')->where('party_id', $producer->party_id)->pluck('role')->all())->toBe(['agent'])
            ->and(DB::table('producer_licences')->where('producer_id', $producer->id)->first(['licence_no', 'class', 'expires_on', 'status']))
            ->toEqual((object) ['licence_no' => 'IDRA-KH-2026', 'class' => 'non_life', 'expires_on' => '2027-08-31', 'status' => 'active'])
            ->and(DB::table('audit_events')->whereIn('object_id', [$producer->party_id, $producer->id])->pluck('action')->sort()->values()->all())->toBe(['party.created', 'producer.created', 'producer_licence.recorded'])
            // The new producer may write the quote's (non-life) business today: the quotation records it as eligible.
            ->and(ProducerEligibility::check((string) $producer->id, $this->world['product_id'], CarbonImmutable::today())->eligible)->toBeTrue();
    });
    actingAs($this->officer)->getJson('/lookup/agent?q=AG-002', $this->headers)->assertJsonPath('results.0.label', 'AG-002 · Kamal Hossain');
});

it('is for holders of agent.manage only, and validates the producer and its licence on the drawer fields', function (): void {
    actingAs($this->officer)->postJson('/lookup/producer', ($this->form)(), $this->headers)->assertForbidden();

    actingAs($this->manager)->postJson('/lookup/producer', ($this->form)(['name' => '', 'code' => 'AG-001', 'licence_no' => '', 'expires_on' => '2026-09-14', 'producer_type' => 'reseller']), $this->headers)
        ->assertUnprocessable()->assertJsonValidationErrors([
            'name' => "Enter the producer's name.", 'code' => 'Another producer has this code.', 'producer_type', 'expires_on' => 'The licence must be valid today to write new business.',
            'licence_no' => 'Enter the licence number: a producer writing new business needs a valid licence.',
        ]);
    actingAs($this->manager)->postJson('/lookup/producer', ($this->form)(['branch_id' => '']), $this->headers)->assertJsonValidationErrors(['branch_id' => 'Choose the branch on the quote first.']);

    // Nothing is created when a field is refused.
    actingAs($this->manager)->postJson('/lookup/producer', ($this->form)(['licence_class' => 'marine']), $this->headers)->assertJsonValidationErrors('licence_class');
    expect(($this->in)(fn (): array => [DB::table('producers')->where('code', 'AG-002')->count(), DB::table('parties')->where('display_name', 'Kamal Hossain')->count()]))->toBe([0, 0]);
});
