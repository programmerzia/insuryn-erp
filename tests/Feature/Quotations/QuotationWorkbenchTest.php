<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Party\Application\AgentService;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use App\Modules\Insurance\Quotation\Domain\Models\Quotation;
use App\Modules\Insurance\Quotation\Infrastructure\Jobs\QuotationExpiryJob;
use App\Modules\Insurance\Rating\Application\RatingEngine;
use App\Modules\Insurance\Rating\Domain\RatingResult;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Phase 3 design §2 step 1 and §6 "Quote workbench" (slice R4): live rating through the endpoint equals the engine, risk schema problems come back per
 * field, drafts re-rate when saved, issuing allocates a branch-coded number and freezes the rating result (a later tariff never changes it), quotations
 * expire after their validity (nightly job and on read), decline needs a reason, producer eligibility is recorded without blocking, quotation.create.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    $this->ctx = seedDemoTenant();
    $this->world = ratedProductsWorld($this->ctx);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->officerId = userWithPermissions($this->ctx['tenant_id'], ['quotation.create', 'party.manage'], 'branch', $this->ctx['branch_id']);
    $this->officer = ($this->in)(fn (): User => User::query()->findOrFail($this->officerId));
    $this->form = fn (array $overrides = []): array => [...['branch_id' => $this->ctx['branch_id'], 'product_id' => $this->world['motor_product_id'],
        'customer_party_id' => $this->world['policyholder_id'], 'producer_id' => $this->world['agent_id'], 'inception' => '2026-09-15',
        'risk_inputs' => $this->world['motor_inputs'], 'coverages' => ['passenger_liability']], ...$overrides];
    $this->terms = fn (array $overrides = []): QuotationTerms => (function (array $f): QuotationTerms {
        return new QuotationTerms($f['branch_id'], $f['product_id'], $f['customer_party_id'], $f['producer_id'], CarbonImmutable::parse($f['inception']), $f['risk_inputs'], $f['coverages']);
    })(($this->form)($overrides));
    $this->service = fn (): QuotationService => app(QuotationService::class);
});

it('rates live through the endpoint exactly as the engine does, and saves nothing', function (): void {
    $before = ($this->in)(fn (): array => [DB::table('quotations')->count(), DB::table('audit_events')->count(), DB::table('document_numbers')->count()]);

    $response = actingAs($this->officer)->postJson('/quotations/rate', ($this->form)(), $this->headers)->assertOk();

    $engine = ($this->in)(fn (): RatingResult => app(RatingEngine::class)->rate($this->world['motor_version_id'], $this->world['motor_inputs'], CarbonImmutable::parse('2026-09-15'), ['passenger_liability']));
    expect($response->json('result'))->toBe($engine->toArray())
        ->and($response->json('result.gross_premium_minor'))->toBe(3_091_830)
        ->and($response->json('result.plan.code'))->toBe('MOTOR-TARIFF')
        ->and($response->json('result.explanation.0.label_bn'))->toBe('নিজস্ব ক্ষতির প্রিমিয়াম')
        ->and(($this->in)(fn (): array => [DB::table('quotations')->count(), DB::table('audit_events')->count(), DB::table('document_numbers')->count()]))->toBe($before);
});

it('returns risk schema problems per field, and rating failures with their reason', function (): void {
    $inputs = [...$this->world['motor_inputs'], 'registration_no' => '', 'engine_cc' => 20, 'vehicle_type' => 'tractor', 'wheels' => 4];

    actingAs($this->officer)->postJson('/quotations/rate', ($this->form)(['risk_inputs' => $inputs]), $this->headers)
        ->assertStatus(422)->assertJsonPath('reason', 'RISK_INPUTS_INVALID')
        ->assertJsonPath('errors', ['wheels' => 'UNKNOWN_FIELD', 'vehicle_type' => 'NOT_AN_OPTION', 'registration_no' => 'REQUIRED', 'engine_cc' => 'BELOW_MIN']);
    actingAs($this->officer)->postJson('/quotations/rate', ($this->form)(['coverages' => ['earthquake']]), $this->headers)
        ->assertStatus(422)->assertJsonPath('reason', 'COVERAGE_UNKNOWN');
    actingAs($this->officer)->postJson('/quotations/rate', ($this->form)(['inception' => '2025-06-01']), $this->headers)
        ->assertStatus(422)->assertJsonPath('reason', 'PRODUCT_VERSION_NOT_EFFECTIVE');
    actingAs($this->officer)->postJson('/quotations/rate', ($this->form)(['inception' => '15/09/2026']), $this->headers)->assertStatus(422)->assertJsonValidationErrors('inception');
});

it('words risk problems with the schema labels, in Bangla for a Bangla user, on the live rating and when saving and issuing', function (): void {
    // Follow-up H2: no raw field keys or problem codes in what people read.
    $inputs = [...$this->world['motor_inputs'], 'registration_no' => '', 'engine_cc' => 20, 'vehicle_type' => 'tractor', 'wheels' => 4];

    actingAs($this->officer)->postJson('/quotations/rate', ($this->form)(['risk_inputs' => $inputs]), $this->headers)
        ->assertStatus(422)->assertJsonPath('reason', 'RISK_INPUTS_INVALID')
        ->assertJsonPath('errors', ['wheels' => 'UNKNOWN_FIELD', 'vehicle_type' => 'NOT_AN_OPTION', 'registration_no' => 'REQUIRED', 'engine_cc' => 'BELOW_MIN'])
        ->assertJsonPath('fields', ['wheels' => 'This product does not ask for this.', 'vehicle_type' => 'Choose one of the options.',
            'registration_no' => 'Enter the registration number.', 'engine_cc' => 'Enter at least 50.'])
        ->assertJsonPath('message', 'Check the risk details: wheels, vehicle type, registration number, engine capacity (cc).');

    actingAs($this->officer)->post('/quotations', [...($this->form)(['risk_inputs' => [...$this->world['motor_inputs'], 'registration_no' => '', 'seats' => 99]]), 'intent' => 'issue'], $this->headers)
        ->assertSessionHasErrors(['reason' => 'RISK_INPUTS_INVALID', 'form' => 'Check the risk details: registration number, seats.',
            'risk_inputs.registration_no' => 'Enter the registration number.', 'risk_inputs.seats' => 'Enter at most 60.']);
    $draft = ($this->in)(fn (): string => (string) DB::table('quotations')->value('id'));
    actingAs($this->officer)->post("/quotations/{$draft}/issue", [], $this->headers)
        ->assertSessionHasErrors(['reason' => 'RISK_INPUTS_INVALID', 'risk_inputs.registration_no' => 'Enter the registration number.']);

    ($this->in)(fn () => app(App\Modules\Platform\Preferences\UserPreferences::class)->set($this->officerId, 'locale', 'bn'));
    actingAs($this->officer)->postJson('/quotations/rate', ($this->form)(['risk_inputs' => [...$this->world['motor_inputs'], 'registration_no' => '', 'vehicle_type' => '', 'engine_cc' => 99999]]), $this->headers)
        ->assertStatus(422)->assertJsonPath('fields', ['vehicle_type' => 'যানবাহনের ধরন বেছে নিন।', 'registration_no' => 'নিবন্ধন নম্বর লিখুন।', 'engine_cc' => 'সর্বোচ্চ 10,000 লিখুন।'])
        ->assertJsonPath('message', 'ঝুঁকির বিবরণ দেখুন: যানবাহনের ধরন, নিবন্ধন নম্বর, ইঞ্জিন ক্ষমতা (সিসি)।');
    actingAs($this->officer)->post("/quotations/{$draft}/issue", [], $this->headers)
        ->assertSessionHasErrors(['form' => 'ঝুঁকির বিবরণ দেখুন: নিবন্ধন নম্বর, আসন সংখ্যা।', 'risk_inputs.registration_no' => 'নিবন্ধন নম্বর লিখুন।']);
});

it('saves a draft, re-rates it on every save, and keeps an incomplete draft without a rating', function (): void {
    actingAs($this->officer)->post('/quotations', ($this->form)(), $this->headers)->assertSessionHasNoErrors()->assertSessionHas('status', 'Draft saved.');
    $quotation = ($this->in)(fn (): Quotation => Quotation::query()->sole());

    expect($quotation->status->value)->toBe('draft')->and($quotation->number)->toBeNull()
        ->and($quotation->gross_premium_minor)->toBe(3_091_830)->and($quotation->net_premium_minor)->toBe(2_684_200)->and($quotation->duties_minor)->toBe(407_630)
        ->and($quotation->sum_insured_minor)->toBe(123_456_700)->and($quotation->rating_plan_code)->toBe('MOTOR-TARIFF')->and($quotation->rating_plan_version)->toBe(1)
        ->and($quotation->class_code)->toBe('motor')->and($quotation->producer_eligible)->toBeTrue()
        ->and($quotation->risk_keys)->toBe(['motor:registration_no:DHAKAMETROGA123456', 'motor:chassis_no:CHS0001XYZ']);

    actingAs($this->officer)->put("/quotations/{$quotation->id}", ($this->form)(['coverages' => []]), $this->headers)->assertRedirect("/quotations/{$quotation->id}");
    ($this->in)(function (): void {
        $withoutPassengers = app(RatingEngine::class)->rate($this->world['motor_version_id'], $this->world['motor_inputs'], CarbonImmutable::parse('2026-09-15'));
        expect(Quotation::query()->sole()->gross_premium_minor)->toBe($withoutPassengers->grossPremiumMinor)->and($withoutPassengers->grossPremiumMinor)->toBeLessThan(3_091_830);
    });

    actingAs($this->officer)->put("/quotations/{$quotation->id}", ($this->form)(['risk_inputs' => [...$this->world['motor_inputs'], 'engine_cc' => '']]), $this->headers)->assertSessionHasNoErrors();
    ($this->in)(function () use ($quotation): void {
        $draft = Quotation::query()->sole();
        expect($draft->rating_result)->toBeNull()->and($draft->gross_premium_minor)->toBeNull()->and($draft->risk_inputs['engine_cc'])->toBeNull()
            ->and(DB::table('audit_events')->where('object_id', $quotation->id)->orderBy('occurred_at')->pluck('action')->all())->toBe(['quotation.created', 'quotation.saved', 'quotation.saved'])
            ->and(DB::table('audit_events')->where('object_id', $quotation->id)->pluck('permission')->unique()->values()->all())->toBe(['quotation.create']);
    });
});

it('issues a quotation: branch-coded number, rating frozen, valid for 15 days; a later tariff does not change it', function (): void {
    actingAs($this->officer)->post('/quotations', [...($this->form)(), 'intent' => 'issue'], $this->headers)->assertSessionHasNoErrors()->assertSessionHas('status', 'Quotation issued.');
    $quotation = ($this->in)(fn (): Quotation => Quotation::query()->sole());
    $frozen = $quotation->rating_result;

    expect($quotation->status->value)->toBe('issued')->and($quotation->number)->toBe('QUO-HO-2026-000001')
        ->and($quotation->valid_until?->toDateString())->toBe('2026-09-29')->and($quotation->issued_by)->toBe($this->officerId)
        ->and(RatingResult::fromArray($frozen ?? [])->grossPremiumMinor)->toBe(3_091_830)
        ->and(($this->in)(fn () => DB::table('document_numbers')->where('number', 'QUO-HO-2026-000001')->first(['status', 'object_type', 'object_id'])))
        ->toEqual((object) ['status' => 'used', 'object_type' => 'quotation', 'object_id' => $quotation->id]);

    // A new tariff from the same day: the engine now gives a different premium, the issued quotation keeps its own.
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/rating/01_motor_comprehensive.json'), true, 512, JSON_THROW_ON_ERROR);
    $plan = [...$fixture['plan'], 'version' => 2, 'effective_from' => '2026-09-15'];
    foreach ($plan['tables'] as $i => $table) {
        if ($table['code'] === 'third_party') {
            $plan['tables'][$i]['rows'][0]['value_minor'] = 300_000;
        }
    }
    activeRatingPlan($this->ctx['tenant_id'], $plan, supersede: true);
    ($this->in)(function () use ($quotation, $frozen): void {
        $now = app(RatingEngine::class)->rate($this->world['motor_version_id'], $this->world['motor_inputs'], CarbonImmutable::parse('2026-09-15'), ['passenger_liability']);
        $stored = Quotation::query()->sole();
        expect($now->plan['version'])->toBe(2)->and($now->grossPremiumMinor)->toBeGreaterThan(3_091_830)
            ->and($stored->rating_result)->toBe($frozen)->and($stored->gross_premium_minor)->toBe(3_091_830)->and($stored->rating_plan_version)->toBe(1)
            ->and(thrownBy(fn () => ($this->service)()->saveDraft(($this->terms)(), $quotation->id, $this->officerId), BusinessRuleViolation::class)->reasonCode)->toBe('QUOTATION_NOT_DRAFT')
            ->and(thrownBy(fn () => ($this->service)()->issue($quotation->id, CarbonImmutable::today(), $this->officerId), BusinessRuleViolation::class)->reasonCode)->toBe('QUOTATION_NOT_DRAFT');
        expect(fn () => DB::table('quotations')->where('id', $quotation->id)->update(['gross_premium_minor' => 1]))->toThrow(QueryException::class, 'QUOTATION_FROZEN')
            ->and(fn () => DB::table('quotations')->where('id', $quotation->id)->update(['status' => 'draft']))->toThrow(QueryException::class, 'QUOTATION_TRANSITION');
    });
});

it('needs a customer and a cover start from the issue day to issue; a refused issue keeps the draft', function (): void {
    $response = actingAs($this->officer)->post('/quotations', [...($this->form)(['customer_party_id' => null]), 'intent' => 'issue'], $this->headers)
        ->assertSessionHasErrors(['reason' => 'QUOTATION_CUSTOMER_REQUIRED']);
    $quotation = ($this->in)(fn (): Quotation => Quotation::query()->sole());
    $response->assertRedirect("/quotations/{$quotation->id}");
    expect($quotation->status->value)->toBe('draft')->and($quotation->gross_premium_minor)->toBe(3_091_830);

    ($this->in)(function (): void {
        $past = ($this->service)()->saveDraft(($this->terms)(['inception' => '2026-09-14']), null, $this->officerId);
        expect(thrownBy(fn () => ($this->service)()->issue($past->id, CarbonImmutable::parse('2026-09-15'), $this->officerId), BusinessRuleViolation::class)->reasonCode)->toBe('QUOTATION_INCEPTION_IN_PAST');
        $incomplete = ($this->service)()->saveDraft(($this->terms)(['risk_inputs' => [...$this->world['motor_inputs'], 'seats' => null]]), null, $this->officerId);
        expect(thrownBy(fn () => ($this->service)()->issue($incomplete->id, CarbonImmutable::parse('2026-09-15'), $this->officerId), App\Modules\Insurance\Product\Domain\Risk\RiskInputsInvalid::class)->errors)
            ->toBe(['seats' => 'REQUIRED']);
    });
});

it('expires issued quotations after their last valid day, in the nightly job and when quotations are read', function (): void {
    [$first, $second] = ($this->in)(function (): array {
        $a = ($this->service)()->issue(($this->service)()->saveDraft(($this->terms)(), null, $this->officerId)->id, CarbonImmutable::today(), $this->officerId);
        $b = ($this->service)()->issue(($this->service)()->saveDraft(($this->terms)(['inception' => '2026-09-20']), null, $this->officerId)->id, CarbonImmutable::parse('2026-09-16'), $this->officerId);

        return [$a, $b];
    });
    // Slice 2.1b (D-54): the last valid day ends at midnight in Dhaka (18:00 UTC), not at midnight UTC.
    travelTo(CarbonImmutable::parse('2026-09-29 17:59'));
    app(QuotationExpiryJob::class)->handle(app(QuotationService::class));
    expect(($this->in)(fn () => Quotation::query()->whereKey($first->id)->firstOrFail()->status->value))->toBe('issued');

    travelTo(CarbonImmutable::parse('2026-09-29 18:00'));
    app(QuotationExpiryJob::class)->handle(app(QuotationService::class));
    ($this->in)(function () use ($first, $second): void {
        expect(Quotation::query()->whereKey($first->id)->firstOrFail()->status->value)->toBe('expired')->and(Quotation::query()->whereKey($second->id)->firstOrFail()->status->value)->toBe('issued')
            ->and(DB::table('audit_events')->where('object_id', $first->id)->where('action', 'quotation.expired')->value('actor_type'))->toBe('system');
    });

    travelTo(CarbonImmutable::parse('2026-10-01 08:00'));
    actingAs($this->officer)->get('/quotations', $this->headers)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('quotations/Index')->where('quotations.0.status', 'expired')->where('quotations.1.status', 'expired'));
});

it('declines a draft or an issued quotation with a reason; a declined quotation is final', function (): void {
    ($this->in)(function (): void {
        $draft = ($this->service)()->saveDraft(($this->terms)(), null, $this->officerId);
        $issued = ($this->service)()->issue(($this->service)()->saveDraft(($this->terms)(), null, $this->officerId)->id, CarbonImmutable::today(), $this->officerId);

        expect(thrownBy(fn () => ($this->service)()->decline($draft->id, '  ', $this->officerId), BusinessRuleViolation::class)->reasonCode)->toBe('REASON_REQUIRED');
        ($this->service)()->decline($draft->id, 'Customer bought elsewhere', $this->officerId);
        ($this->service)()->decline($issued->id, 'Price too high', $this->officerId);

        expect(Quotation::query()->whereKey($draft->id)->first()?->decline_reason)->toBe('Customer bought elsewhere')
            ->and(Quotation::query()->whereKey($issued->id)->firstOrFail()->status->value)->toBe('declined')
            ->and(DB::table('audit_events')->where('object_id', $issued->id)->where('action', 'quotation.declined')->value('reason'))->toBe('Price too high')
            ->and(thrownBy(fn () => ($this->service)()->decline($issued->id, 'again', $this->officerId), BusinessRuleViolation::class)->reasonCode)->toBe('QUOTATION_NOT_OPEN');
    });
    $id = ($this->in)(fn (): string => (string) Quotation::query()->where('status', 'declined')->orderBy('created_at')->value('id'));
    actingAs($this->officer)->post("/quotations/{$id}/decline", ['reason' => ''], $this->headers)->assertSessionHasErrors('reason');
});

it('records whether the producer may write the class, without blocking the quotation', function (): void {
    ($this->in)(function (): void {
        $party = app(PartyService::class)->create(PartyKind::Individual, 'Unlicensed Agent', null, [PartyRoleType::Agent], $this->world['admin']);
        $unlicensed = app(AgentService::class)->create($party->id, 'AG-009', $this->ctx['branch_id'], null, null, $this->world['admin']);
        $quotation = ($this->service)()->issue(($this->service)()->saveDraft(($this->terms)(['producer_id' => $unlicensed->id]), null, $this->officerId)->id, CarbonImmutable::today(), $this->officerId);
        $direct = ($this->service)()->saveDraft(($this->terms)(['producer_id' => null]), null, $this->officerId);

        expect($quotation->status->value)->toBe('issued')->and($quotation->producer_eligible)->toBeFalse()->and($quotation->producer_eligibility_reason)->toBe('LICENCE_REQUIRED')
            ->and($quotation->producer_eligibility_note)->toContain('AG-009')
            ->and($direct->producer_eligible)->toBeNull()
            ->and(thrownBy(fn () => ($this->service)()->saveDraft(($this->terms)(['producer_id' => (string) Illuminate\Support\Str::uuid7()]), null, $this->officerId), BusinessRuleViolation::class)->reasonCode)
            ->toBe('PRODUCER_UNKNOWN');
    });
});

it('needs quotation.create on the branch to rate, save, issue or decline; people who quote policies may read', function (): void {
    $reader = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['policy.create'])));
    $stranger = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['receipt.create'])));
    $otherBranch = ($this->in)(function (): string {
        DB::table('branches')->insert(['id' => $id = (string) Illuminate\Support\Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => 'CTG',
            'name' => 'Chattogram', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    });

    actingAs($reader)->get('/quotations', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', false));
    actingAs($reader)->get('/quotations/create', $this->headers)->assertForbidden();
    actingAs($reader)->postJson('/quotations/rate', ($this->form)(), $this->headers)->assertForbidden()->assertJsonPath('permission', 'quotation.create');
    actingAs($reader)->post('/quotations', ($this->form)(), $this->headers)->assertSessionHasErrors('form');
    actingAs($stranger)->get('/quotations', $this->headers)->assertForbidden();
    actingAs($this->officer)->postJson('/quotations/rate', ($this->form)(['branch_id' => $otherBranch]), $this->headers)->assertForbidden();
    expect(($this->in)(fn (): int => Quotation::query()->count()))->toBe(0)
        ->and(thrownBy(fn () => ($this->in)(fn () => ($this->service)()->saveDraft(($this->terms)(), null, $reader->id)), PermissionDenied::class)->permission)->toBe('quotation.create');

    seedRoleTemplates($this->ctx['tenant_id']);
    ($this->in)(function (): void {
        $holders = DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')->where('rp.permission_code', 'quotation.create')->orderBy('r.code')->pluck('r.code')->all();
        expect(array_values(array_filter($holders, fn (mixed $code): bool => ! str_starts_with((string) $code, 'test-'))))->toBe(['branch_manager', 'branch_officer']);
    });
});

it('gives the queue and the workbench what they show', function (): void {
    $id = ($this->in)(fn (): string => ($this->service)()->issue(($this->service)()->saveDraft(($this->terms)(), null, $this->officerId)->id, CarbonImmutable::today(), $this->officerId)->id);

    actingAs($this->officer)->get('/quotations', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('quotations/Index')
        ->where('canCreate', true)->where('currency', 'BDT')
        ->where('quotations.0', ['id' => $id, 'number' => 'QUO-HO-2026-000001', 'status' => 'issued', 'customer' => 'Rahima Akter', 'product' => 'MOTOR-PVT · Private motor',
            'producer' => 'AG-001', 'producer_eligible' => true, 'inception' => '2026-09-15', 'valid_until' => '2026-09-29', 'sum_insured' => '1,234,567.00',
            'gross_premium' => '30,918.30', 'created_at' => '2026-09-15', 'created_by' => 'Test user']));

    actingAs($this->officer)->get('/quotations/create', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('quotations/Workbench')
        ->where('quotation', null)->where('today', '2026-09-15')->where('validDays', 15)->where('can', ['edit' => true, 'issue' => true, 'decline' => false, 'convert' => false])
        ->where('products', function (mixed $products): bool {
            $list = json_decode((string) json_encode($products), true);
            $motor = array_values(array_filter(is_array($list) ? $list : [], fn (array $p): bool => $p['code'] === 'MOTOR-PVT'))[0] ?? null;

            return $motor !== null && $motor['versions'][0]['class_code'] === 'motor'
                && $motor['versions'][0]['coverages'][2] === ['code' => 'passenger_liability', 'name_en' => 'Passenger liability', 'name_bn' => 'যাত্রী দায়', 'mandatory' => false]
                && array_column($motor['versions'][0]['risk_schema'], 'key') === ['vehicle_type', 'registration_no', 'chassis_no', 'engine_cc', 'seats', 'year_of_manufacture', 'driver_age', 'sum_insured', 'ncb_years']
                && ! in_array('MOTOR', array_column(is_array($list) ? $list : [], 'code'), true);
        }));

    actingAs($this->officer)->get("/quotations/{$id}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('quotations/Workbench')
        ->where('quotation.number', 'QUO-HO-2026-000001')->where('quotation.customer.label', 'Rahima Akter')->where('quotation.producer.label', 'AG-001 · Jamal Agent')
        ->where('quotation.rating_result.gross_premium_minor', 3_091_830)->where('quotation.coverages', ['passenger_liability'])
        ->where('can', ['edit' => false, 'issue' => false, 'decline' => true, 'convert' => true]));
});
