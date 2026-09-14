<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\PartyContact;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * GA-17: a customer has a mobile number (required for a person on the Parties form, A-193; optional in the quote's inline drawer), email, address, NID or BRN,
 * a person's date of birth and an organisation's contact person; the customer page shows policies, claims, receipts, documents and audit and is edited by
 * party.manage holders only.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('documents');
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->admin = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $this->world['admin']));
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    $this->party = fn (string $name): ?object => asTenant($this->ctx['tenant_id'], fn () => DB::table('parties')->where('display_name', $name)->first());
});

it('keeps a Bangladeshi mobile number in international form and refuses what is not a number', function (): void {
    foreach (['01712345678', '01712-345678', '+8801712345678', '8801712345678', '017 1234 5678'] as $typed) {
        expect(PartyContact::mobile($typed))->toBe('+8801712345678');
    }
    expect(PartyContact::mobile('+44 7911 123456'))->toBe('+447911123456')
        ->and(PartyContact::mobile(''))->toBeNull()
        ->and(thrownBy(fn () => PartyContact::mobile('01212345678'), BusinessRuleViolation::class)->reasonCode)->toBe('PARTY_MOBILE_INVALID')
        ->and(thrownBy(fn () => PartyContact::mobile('7911 123456'), BusinessRuleViolation::class)->reasonCode)->toBe('PARTY_MOBILE_INVALID')
        ->and(thrownBy(fn () => PartyContact::fromInput(PartyKind::Individual, ['email' => 'not-an-email']), BusinessRuleViolation::class)->reasonCode)->toBe('PARTY_EMAIL_INVALID')
        ->and(thrownBy(fn () => PartyContact::fromInput(PartyKind::Organization, ['date_of_birth' => '1990-01-01']), BusinessRuleViolation::class)->reasonCode)->toBe('PARTY_FIELD_NOT_FOR_KIND')
        ->and(thrownBy(fn () => PartyContact::fromInput(PartyKind::Individual, ['date_of_birth' => '2999-01-01']), BusinessRuleViolation::class)->reasonCode)->toBe('PARTY_DATE_OF_BIRTH_INVALID')
        ->and(PartyContact::fromInput(PartyKind::Individual, ['contact_person' => 'Someone'])->contactPerson)->toBeNull();
});

it('requires a mobile for a person on the Parties form, stores the contact details and audits them', function (): void {
    $person = ['kind' => 'individual', 'display_name' => 'Shirin Akter', 'roles' => ['customer', 'policyholder']];
    actingAs($this->admin)->post('/parties', $person, $this->headers)->assertSessionHasErrors('mobile');
    actingAs($this->admin)->post('/parties', [...$person, 'mobile' => '0171 2345'], $this->headers)->assertSessionHasErrors('mobile');
    expect(($this->party)('Shirin Akter'))->toBeNull();

    actingAs($this->admin)->post('/parties', [...$person, 'mobile' => '01712-345678', 'email' => 'Shirin@Example.com', 'address' => 'House 5, Road 2, Dhanmondi, Dhaka',
        'identity_no' => '1990123456789', 'date_of_birth' => '1990-04-12'], $this->headers)->assertSessionHasNoErrors();
    $row = ($this->party)('Shirin Akter');
    expect([$row?->mobile, $row?->email, $row?->address, $row?->identity_no, $row?->date_of_birth, $row?->contact_person])
        ->toBe(['+8801712345678', 'shirin@example.com', 'House 5, Road 2, Dhanmondi, Dhaka', '1990123456789', '1990-04-12', null])
        ->and(asTenant($this->ctx['tenant_id'], fn () => json_decode((string) DB::table('audit_events')->where('object_id', $row?->id)->where('action', 'party.created')->value('after'), true)['mobile']))->toBe('+8801712345678');

    // An organisation needs no mobile; it has a contact person and a BRN.
    actingAs($this->admin)->post('/parties', ['kind' => 'organization', 'display_name' => 'Rupsha Foods Ltd', 'roles' => ['customer'], 'identity_no' => 'BRN-C-123456', 'contact_person' => 'Mr Hasan'], $this->headers)
        ->assertSessionHasNoErrors();
    expect(($this->party)('Rupsha Foods Ltd')?->contact_person)->toBe('Mr Hasan');

    // Search finds the person by mobile number as typed locally, and by NID.
    foreach (['01712345678', '1990123456789'] as $search) {
        actingAs($this->admin)->get('/parties?search='.$search, $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->has('parties.data', 1)->where('parties.data.0.mobile', '+8801712345678'));
    }
});

it('takes contact details in the quote drawer without requiring the mobile, and finds the customer by mobile', function (): void {
    $officer = ($this->userWith)(['party.manage', 'policy.create', 'quotation.create']);
    actingAs($officer)->postJson('/lookup/customer', ['kind' => 'individual', 'display_name' => 'Walk-in Customer'], $this->headers)->assertCreated();
    actingAs($officer)->postJson('/lookup/customer', ['kind' => 'individual', 'display_name' => 'Bad Mobile', 'mobile' => '12345'], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('mobile');
    actingAs($officer)->postJson('/lookup/customer', ['kind' => 'individual', 'display_name' => 'Jahid Hasan', 'mobile' => '01812 000111', 'identity_no' => '19881234'], $this->headers)
        ->assertCreated()->assertJsonPath('result.detail', 'Individual · +8801812000111');

    actingAs($officer)->getJson('/lookup/customer?q=01812000111', $this->headers)->assertOk()->assertJsonPath('results.0.label', 'Jahid Hasan');
    actingAs($officer)->getJson('/lookup/customer?q=19881234', $this->headers)->assertOk()->assertJsonPath('results.0.label', 'Jahid Hasan');
});

it('shows the customer page with policies, claims, receipts and documents, and lets only party managers edit', function (): void {
    $holder = $this->world['policyholder_id'];
    asTenant($this->ctx['tenant_id'], function () use ($holder): void {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $holder, $this->world['agent_id'],
            CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 12_000_000, 'BDT', CarbonImmutable::parse('2026-09-02'), null, 'r',
            [new AllocationLine((string) DB::table('installments')->where('policy_id', $policy->id)->value('id'), 12_000_000)]), $this->world['admin']);
        $claims = userWithPermissions($this->ctx['tenant_id'], ['claim.register']);
        app(App\Modules\Insurance\Claims\Application\ClaimService::class)->register($policy->id, CarbonImmutable::parse('2026-09-05'), 'Collision', $claims, CarbonImmutable::parse('2026-09-06'));
    });

    $reader = ($this->userWith)(['reports.financial']);
    actingAs($reader)->get("/parties/{$holder}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('parties/Show')
        ->where('party.display_name', 'Rahima Akter')->where('party.mobile', null)->has('policies', 1)->where('policies.0.gross_premium', '120,000.00')
        ->has('claims', 1)->where('claims.0.number', fn ($n): bool => str_starts_with((string) $n, 'CLM-'))->has('receipts', 1)
        ->where('can.manage', false)->where('documentUpload', null)->has('timeline'));
    actingAs($reader)->put("/parties/{$holder}", ['display_name' => 'Changed', 'mobile' => '01712345678'], $this->headers)->assertSessionHasErrors('form');
    actingAs($reader)->post("/parties/{$holder}/documents", ['file' => UploadedFile::fake()->createWithContent('nid.pdf', "%PDF-1.4\nNID copy\n")], $this->headers)->assertSessionHasErrors('form');

    actingAs($this->admin)->put("/parties/{$holder}", ['display_name' => 'Rahima Akter', 'mobile' => ''], $this->headers)->assertSessionHasErrors('mobile');
    actingAs($this->admin)->put("/parties/{$holder}", ['display_name' => 'Rahima Akter', 'mobile' => '01911 222333', 'address' => 'Mirpur 10, Dhaka', 'identity_no' => '1985111222333'], $this->headers)
        ->assertSessionHasNoErrors()->assertRedirect("/parties/{$holder}");
    actingAs($this->admin)->post("/parties/{$holder}/documents", ['file' => UploadedFile::fake()->createWithContent('nid.pdf', "%PDF-1.4\nNID copy\n")], $this->headers)->assertSessionHasNoErrors();
    $response = actingAs($this->admin)->get("/parties/{$holder}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('party.mobile', '+8801911222333')->where('party.address', 'Mirpur 10, Dhaka')->where('can.manage', true)->where('documentUpload', "/parties/{$holder}/documents"));
    expect($response->status())->toBe(200)
        ->and(asTenant($this->ctx['tenant_id'], fn () => DB::table('stored_documents')->where('object_type', 'party')->where('object_id', $holder)->count()))->toBe(1)
        ->and(asTenant($this->ctx['tenant_id'], fn () => DB::table('audit_events')->where('object_id', $holder)->where('action', 'party.updated')->count()))->toBe(1);
});
