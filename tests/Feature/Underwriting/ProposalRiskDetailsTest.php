<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use App\Modules\Insurance\Quotation\Domain\Models\Quotation;
use App\Modules\Insurance\Rating\Application\RatingEngine;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use App\Modules\Insurance\Underwriting\Application\UnderwritingLimits;
use App\Modules\Insurance\Underwriting\Domain\Models\Proposal;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Flow fix X7 (Part A step 1): a risk detail is required only when it can be known. The motor chassis number is `required_at: proposal` — a price is quoted and
 * the quotation issued without it; the draft proposal takes it (re-rated on the frozen plan, premium unchanged) and submitting refuses while it is empty.
 * Duplicate-risk keys work from the registration number alone.
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
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'email' => 'officer@example.test', 'name' => 'Rafiq Officer',
            'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', 'branch_officer')->value('id'),
            'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);
        app(UnderwritingLimits::class)->set('branch_officer', 'motor', 200_000_000, CarbonImmutable::today(), $this->world['admin']);

        return User::query()->findOrFail($id);
    });
    $this->withoutChassis = array_diff_key($this->world['motor_inputs'], ['chassis_no' => true]);
    /** A KYC-verified draft proposal from an issued motor quotation with the given inputs. */
    $this->draft = fn (array $inputs): Proposal => ($this->in)(function () use ($inputs): Proposal {
        $quotations = app(QuotationService::class);
        $terms = new QuotationTerms($this->ctx['branch_id'], $this->world['motor_product_id'], $this->world['policyholder_id'], $this->world['agent_id'],
            CarbonImmutable::parse('2026-09-15'), $inputs, ['passenger_liability']);
        $quotation = $quotations->issue($quotations->saveDraft($terms, null, $this->officer->id)->id, CarbonImmutable::today(), $this->officer->id);
        $proposal = app(ProposalService::class)->createFromQuotation($quotation->id, $this->officer->id);

        return app(ProposalService::class)->verifyKyc($proposal->id, 'nid', '1990123456789', $this->officer->id);
    });
});

it('quotes and issues a motor quotation without the chassis number, at the same premium, with a registration-only duplicate key', function (): void {
    $form = ['branch_id' => $this->ctx['branch_id'], 'product_id' => $this->world['motor_product_id'], 'customer_party_id' => $this->world['policyholder_id'],
        'producer_id' => $this->world['agent_id'], 'inception' => '2026-09-15', 'risk_inputs' => $this->withoutChassis, 'coverages' => ['passenger_liability']];
    actingAs($this->officer)->postJson('/quotations/rate', $form, $this->headers)->assertOk()->assertJsonPath('result.gross_premium_minor', 3_091_830)
        ->assertJsonPath('result.risk_inputs.chassis_no', null);
    // The registration number stays required to quote.
    actingAs($this->officer)->postJson('/quotations/rate', [...$form, 'risk_inputs' => [...$this->withoutChassis, 'registration_no' => '']], $this->headers)
        ->assertStatus(422)->assertJsonPath('errors', ['registration_no' => 'REQUIRED']);
    // Complete inputs rate exactly as before (golden hash unchanged by the schema change).
    ($this->in)(function (): void {
        $complete = app(RatingEngine::class)->rate($this->world['motor_version_id'], $this->world['motor_inputs'], CarbonImmutable::parse('2026-09-15'), ['passenger_liability']);
        expect($complete->riskInputs['chassis_no'])->toBe('CHS-0001-XYZ')->and($complete->grossPremiumMinor)->toBe(3_091_830);
    });

    actingAs($this->officer)->post('/quotations', [...$form, 'intent' => 'issue'], $this->headers)->assertSessionHasNoErrors()->assertSessionHas('status', 'Quotation issued.');
    ($this->in)(function (): void {
        $quotation = Quotation::query()->sole();
        expect($quotation->status->value)->toBe('issued')->and($quotation->gross_premium_minor)->toBe(3_091_830)
            ->and($quotation->risk_keys)->toBe(['motor:registration_no:DHAKAMETROGA123456']);
    });
});

it('refuses to submit the proposal until the chassis number is entered on it, then submits', function (): void {
    $draft = ($this->draft)($this->withoutChassis);
    $service = fn (): ProposalService => app(ProposalService::class);

    $refusal = ($this->in)(fn () => thrownBy(fn () => $service()->submit($draft->id, $this->officer->id), BusinessRuleViolation::class));
    expect($refusal->reasonCode)->toBe('PROPOSAL_RISK_DETAILS_MISSING')->and($refusal->getMessage())->toBe('Enter the chassis number before submitting the proposal.');

    actingAs($this->officer)->get("/proposals/{$draft->id}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('proposals/Show')
        ->where('riskDetails.missing', ['Chassis number'])->where('riskDetails.editable', true)
        ->where('riskDetails.fields', [['key' => 'chassis_no', 'label_en' => 'Chassis number', 'label_bn' => 'চেসিস নম্বর', 'type' => 'text', 'required' => true, 'max_length' => 32, 'required_at' => 'proposal']]));
    actingAs($this->officer)->post("/proposals/{$draft->id}/submit", [], $this->headers)->assertSessionHasErrors(['form' => 'Enter the chassis number before submitting the proposal.']);

    // Only proposal-stage details are entered here; each is checked like a quote input.
    actingAs($this->officer)->post("/proposals/{$draft->id}/risk-details", ['risk_inputs' => ['engine_cc' => 2000]], $this->headers)
        ->assertSessionHasErrors(['reason' => 'PROPOSAL_RISK_DETAIL_NOT_EDITABLE']);
    actingAs($this->officer)->post("/proposals/{$draft->id}/risk-details", ['risk_inputs' => ['chassis_no' => str_repeat('X', 33)]], $this->headers)
        ->assertSessionHasErrors(['risk_inputs.chassis_no' => 'Keep it to 32 characters.', 'form' => 'Check the risk details: chassis number.', 'reason' => 'RISK_INPUTS_INVALID']);
    // Follow-up H2: in Bangla for a Bangla user, with the schema's Bangla label.
    ($this->in)(fn () => app(App\Modules\Platform\Preferences\UserPreferences::class)->set($this->officer->id, 'locale', 'bn'));
    actingAs($this->officer)->post("/proposals/{$draft->id}/risk-details", ['risk_inputs' => ['chassis_no' => str_repeat('X', 33)]], $this->headers)
        ->assertSessionHasErrors(['risk_inputs.chassis_no' => '32 অক্ষরের মধ্যে রাখুন।', 'form' => 'ঝুঁকির বিবরণ দেখুন: চেসিস নম্বর।']);
    ($this->in)(fn () => app(App\Modules\Platform\Preferences\UserPreferences::class)->set($this->officer->id, 'locale', 'en'));

    actingAs($this->officer)->post("/proposals/{$draft->id}/risk-details", ['risk_inputs' => ['chassis_no' => 'CHS-LATE-0001']], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Risk details saved.');
    ($this->in)(function () use ($draft, $service): void {
        $proposal = Proposal::query()->whereKey($draft->id)->firstOrFail();
        $frozen = Quotation::query()->whereKey($proposal->quotation_id)->firstOrFail()->ratingResult();
        expect($proposal->risk_inputs['chassis_no'])->toBe('CHS-LATE-0001')->and($proposal->ratingResult()->riskInputs['chassis_no'])->toBe('CHS-LATE-0001')
            ->and($proposal->gross_premium_minor)->toBe(3_091_830)->and($proposal->ratingResult()->grossPremiumMinor)->toBe($frozen?->grossPremiumMinor)
            ->and($proposal->ratingResult()->explanation)->toBe($frozen?->explanation)
            ->and($proposal->risk_keys)->toBe(['motor:registration_no:DHAKAMETROGA123456', 'motor:chassis_no:CHSLATE0001'])
            ->and(DB::table('audit_events')->where('object_id', $proposal->id)->where('action', 'proposal.risk_details_completed')->value('permission'))->toBe('quotation.create')
            ->and(ProposalService::missingRiskDetails($proposal))->toBe([]);

        expect($service()->submit($proposal->id, $this->officer->id)->status->value)->toBe('approved');
        expect(thrownBy(fn () => $service()->completeRiskDetails($proposal->id, ['chassis_no' => 'OTHER'], $this->officer->id), BusinessRuleViolation::class)->reasonCode)
            ->toBe('PROPOSAL_NOT_DRAFT');
    });
});

it('refuses a detail that would move the frozen premium, and finds duplicates from the registration alone', function (): void {
    $first = ($this->draft)($this->withoutChassis);
    ($this->in)(function () use ($first): void {
        // The stored rating no longer matches what its own plan gives for the inputs: the detail is refused rather than silently re-pricing the proposal.
        $stored = Proposal::query()->whereKey($first->id)->firstOrFail()->rating_result;
        DB::table('proposals')->where('id', $first->id)->update(['rating_result' => json_encode([...$stored, 'gross_premium_minor' => 3_091_831])]);
        expect(thrownBy(fn () => app(ProposalService::class)->completeRiskDetails($first->id, ['chassis_no' => 'CHS-1'], $this->officer->id), BusinessRuleViolation::class)->reasonCode)
            ->toBe('PROPOSAL_RISK_DETAIL_CHANGES_PREMIUM')
            ->and(Proposal::query()->whereKey($first->id)->firstOrFail()->risk_inputs['chassis_no'])->toBeNull();
        DB::table('proposals')->where('id', $first->id)->update(['rating_result' => json_encode($stored)]);
    });

    $second = ($this->draft)([...$this->withoutChassis, 'registration_no' => 'dhaka metro ga 12 3456']);
    ($this->in)(function () use ($second): void {
        $proposal = app(ProposalService::class)->completeRiskDetails($second->id, ['chassis_no' => 'CHS-SECOND'], $this->officer->id);
        $submitted = app(ProposalService::class)->submit($proposal->id, $this->officer->id);
        expect(array_column($submitted->referral_reasons ?? [], 'code'))->toBe(['DUPLICATE_RISK']);
    });
});
