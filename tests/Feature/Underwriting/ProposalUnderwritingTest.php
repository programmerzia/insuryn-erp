<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Distribution\Application\Licences\LicenceService;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use App\Modules\Insurance\Quotation\Domain\Models\Quotation;
use App\Modules\Insurance\Rating\Application\RatingEngine;
use App\Modules\Insurance\Rating\Domain\ManualLoading;
use App\Modules\Insurance\Rating\Domain\RatingFailed;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use App\Modules\Insurance\Underwriting\Application\UnderwritingDecisions;
use App\Modules\Insurance\Underwriting\Application\UnderwritingLimits;
use App\Modules\Insurance\Underwriting\Domain\Models\Proposal;
use App\Modules\Platform\Approvals\ApprovalInboxQuery;
use App\Modules\Platform\Approvals\ApprovalStatus;
use App\Modules\Platform\Approvals\Decision;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Authorization\SodViolation;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Phase 3 design §2 step 2 and §5 (slice R5): a proposal from an issued quotation, KYC verified or waived, documents; underwriting rules approve automatically
 * or refer with every reason (sum insured above the submitter's limit, producer ineligible, risk flags, duplicate risk, KYC pending); limits by role, class and
 * sum insured; referrals decided through the approval engine with maker ≠ checker and SoD on the proposal; approval with a manual loading re-rates and is
 * audited with its reason; the referral queue, the proposal page and Admin → Underwriting limits.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->world = ratedProductsWorld($this->ctx);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->person = fn (string $name, array $roleCodes): User => ($this->in)(function () use ($name, $roleCodes): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'email' => Str::slug($name).'@example.test', 'name' => $name,
            'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($roleCodes as $code) {
            DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $code)->value('id'),
                'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);
        }

        return User::query()->findOrFail($id);
    });
    $this->officer = ($this->person)('Rafiq Officer', ['branch_officer']);
    $this->manager = ($this->person)('Salma Manager', ['branch_manager']);
    $this->otherManager = ($this->person)('Karim Manager', ['branch_manager']);
    $this->finance = ($this->person)('Nadia Finance', ['finance_manager']);
    ($this->in)(function (): void {
        $limits = app(UnderwritingLimits::class);
        $limits->set('branch_officer', 'motor', 200_000_000, CarbonImmutable::today(), $this->world['admin']); // 2,000,000.00
        $limits->set('branch_officer', 'fire', 500_000_000_000, CarbonImmutable::today(), $this->world['admin']);
        $limits->set('branch_manager', 'motor', 1_000_000_000, CarbonImmutable::today(), $this->world['admin']); // 10,000,000.00
        $limits->set('finance_manager', 'motor', 5_000_000_000, CarbonImmutable::today(), $this->world['admin']); // 50,000,000.00
    });
    /** An issued quotation of the officer (motor by default), as a proposal draft with KYC verified unless told otherwise. */
    $this->proposal = fn (array $inputs = [], bool $verify = true, string $product = 'motor', ?string $producer = 'agent'): Proposal => ($this->in)(function () use ($inputs, $verify, $product, $producer): Proposal {
        $quotations = app(QuotationService::class);
        $terms = new QuotationTerms($this->ctx['branch_id'], $this->world["{$product}_product_id"], $this->world['policyholder_id'], $producer === null ? null : $this->world['agent_id'],
            CarbonImmutable::parse('2026-09-15'), [...$this->world["{$product}_inputs"], ...$inputs], $product === 'motor' ? ['passenger_liability'] : []);
        $quotation = $quotations->issue($quotations->saveDraft($terms, null, $this->officer->id)->id, CarbonImmutable::today(), $this->officer->id);
        $proposal = app(ProposalService::class)->createFromQuotation($quotation->id, $this->officer->id);

        return $verify ? app(ProposalService::class)->verifyKyc($proposal->id, 'nid', '1990123456789', $this->officer->id) : $proposal;
    });
    $this->submit = fn (Proposal $proposal, ?User $by = null): Proposal => ($this->in)(fn (): Proposal => app(ProposalService::class)->submit($proposal->id, ($by ?? $this->officer)->id));
    $this->codes = fn (Proposal $proposal): array => array_column($proposal->referral_reasons ?? [], 'code');
});

it('approves a clean proposal automatically, converts its quotation and hands R7 the approved terms', function (): void {
    $draft = ($this->proposal)();
    $proposal = ($this->submit)($draft);

    ($this->in)(function () use ($proposal): void {
        $quotation = Quotation::query()->whereKey($proposal->quotation_id)->firstOrFail();
        expect($proposal->number)->toBe('PRP-HO-2026-000001')->and($proposal->status->value)->toBe('approved')->and($proposal->underwriting_status?->value)->toBe('auto_approved')
            ->and($proposal->referral_reasons)->toBe([])->and($proposal->approval_id)->toBeNull()
            ->and($quotation->status->value)->toBe('converted')->and($proposal->gross_premium_minor)->toBe(3_091_830)
            ->and($proposal->rating_result)->toBe($quotation->rating_result)
            ->and(DB::table('approvals')->count())->toBe(0)
            ->and(DB::table('audit_events')->where('object_id', $proposal->id)->orderBy('occurred_at')->pluck('action')->all())->toBe(['proposal.created', 'proposal.kyc_verified', 'proposal.submitted']);

        $approved = app(ProposalService::class)->approvedForIssue($proposal->id);
        expect($approved->number)->toBe('PRP-HO-2026-000001')->and($approved->ratingResult->grossPremiumMinor)->toBe(3_091_830)->and($approved->customerPartyId)->toBe($this->world['policyholder_id'])
            ->and($approved->inception->toDateString())->toBe('2026-09-15')->and($approved->specialTerms)->toBeNull()
            ->and(fn () => app(ProposalService::class)->markIssued($proposal->id, (string) Str::uuid7(), $this->officer->id))->toThrow(LogicException::class)
            ->and(thrownBy(fn () => app(ProposalService::class)->createFromQuotation($quotation->id, $this->officer->id), BusinessRuleViolation::class)->reasonCode)->toBe('QUOTATION_NOT_ISSUED')
            ->and(thrownBy(fn () => app(ProposalService::class)->submit($proposal->id, $this->officer->id), BusinessRuleViolation::class)->reasonCode)->toBe('PROPOSAL_NOT_DRAFT');
    });
});

it('refers with each reason that holds: limit, producer, risk flag, duplicate risk and KYC', function (): void {
    $aboveLimit = ($this->submit)(($this->proposal)(['sum_insured' => 300_000_000, 'registration_no' => 'DHK-1001', 'chassis_no' => 'CH-1001']));
    $oldVehicle = ($this->submit)(($this->proposal)(['year_of_manufacture' => 2008, 'registration_no' => 'DHK-1002', 'chassis_no' => 'CH-1002']));
    $noKyc = ($this->submit)(($this->proposal)(['registration_no' => 'DHK-1003', 'chassis_no' => 'CH-1003'], verify: false));
    $fire = ($this->submit)(($this->proposal)(['construction_class' => 'class_3'], product: 'fire', producer: null));
    // The same vehicle again, typed differently, while the first proposal is still open.
    $duplicate = ($this->submit)(($this->proposal)(['registration_no' => 'dhk 1001', 'chassis_no' => 'OTHER-9']));
    $sameAddress = ($this->submit)(($this->proposal)(['address' => 'plot 12 tejgaon i a dhaka'], product: 'fire', producer: null));
    ($this->in)(function (): void {
        $licence = (string) DB::table('producer_licences')->where('producer_id', $this->world['agent_id'])->value('id');
        app(LicenceService::class)->revoke($licence, 'Licence cancelled by IDRA', $this->world['admin']);
    });
    $ineligible = ($this->submit)(($this->proposal)(['registration_no' => 'DHK-1004', 'chassis_no' => 'CH-1004']));

    expect(($this->codes)($aboveLimit))->toBe(['SUM_INSURED_ABOVE_LIMIT'])->and($aboveLimit->status->value)->toBe('submitted')->and($aboveLimit->underwriting_status?->value)->toBe('referred')
        ->and($aboveLimit->referral_reasons[0]['detail'] ?? '')->toBe("Sum insured 3,000,000.00 is above the submitter's limit of 2,000,000.00 for motor.")
        ->and(($this->codes)($oldVehicle))->toBe(['RISK_FLAG'])->and($oldVehicle->referral_reasons[0]['detail'] ?? '')->toBe('Vehicle older than 15 years.')
        ->and(($this->codes)($noKyc))->toBe(['KYC_NOT_VERIFIED'])
        ->and(($this->codes)($fire))->toBe(['RISK_FLAG'])
        ->and(($this->codes)($duplicate))->toBe(['DUPLICATE_RISK'])->and($duplicate->referral_reasons[0]['detail'] ?? '')->toBe("The same risk is on {$aboveLimit->number}.")
        ->and(($this->codes)($sameAddress))->toBe(['DUPLICATE_RISK'])->and($sameAddress->referral_reasons[0]['detail'] ?? '')->toBe("The same risk is on {$fire->number}.")
        ->and(($this->codes)($ineligible))->toBe(['PRODUCER_INELIGIBLE']);

    // A declined proposal no longer counts as the same risk elsewhere; an issued quotation does.
    ($this->in)(function () use ($aboveLimit, $duplicate): void {
        app(UnderwritingDecisions::class)->decide($aboveLimit->id, Decision::Rejected, null, 'Not for us', $this->manager->id);
        app(UnderwritingDecisions::class)->decide($duplicate->id, Decision::Rejected, null, 'Same vehicle', $this->manager->id);
        $quotations = app(QuotationService::class);
        $quotations->issue($quotations->saveDraft(new QuotationTerms($this->ctx['branch_id'], $this->world['motor_product_id'], $this->world['policyholder_id'], null,
            CarbonImmutable::parse('2026-09-15'), [...$this->world['motor_inputs'], 'registration_no' => 'DHK-7777', 'chassis_no' => 'CH-7777']), null, $this->officer->id)->id, CarbonImmutable::today(), $this->officer->id);
    });
    $afterDecline = ($this->submit)(($this->proposal)(['registration_no' => 'DHK1001', 'chassis_no' => 'CH-5555']));
    $onQuotation = ($this->submit)(($this->proposal)(['registration_no' => 'DHK-8888', 'chassis_no' => 'ch 7777']));
    expect(($this->codes)($afterDecline))->not->toContain('DUPLICATE_RISK')
        ->and(($this->codes)($onQuotation))->toContain('DUPLICATE_RISK');
});

it('reads underwriting limits by role, class and sum insured, effective-dated and audited', function (): void {
    $both = ($this->person)('Two Hats', ['branch_officer', 'branch_manager']);
    ($this->in)(function () use ($both): void {
        $limits = app(UnderwritingLimits::class);
        $today = CarbonImmutable::today();
        expect($limits->limitFor($this->officer->id, 'motor', $today))->toBe(200_000_000)
            ->and($limits->limitFor($both->id, 'motor', $today))->toBe(1_000_000_000)
            ->and($limits->limitFor($this->manager->id, 'fire', $today))->toBeNull()
            ->and($limits->rolesCovering('motor', 150_000_000, $today))->toBe(['branch_officer', 'branch_manager', 'finance_manager'])
            ->and($limits->rolesCovering('motor', 300_000_000, $today))->toBe(['branch_manager', 'finance_manager'])
            ->and($limits->rolesCovering('motor', 6_000_000_000, $today))->toBe([]);

        $limits->set('branch_officer', 'motor', 300_000_000, CarbonImmutable::parse('2026-10-01'), $this->world['admin']);
        expect($limits->limitFor($this->officer->id, 'motor', CarbonImmutable::parse('2026-09-30')))->toBe(200_000_000)
            ->and($limits->limitFor($this->officer->id, 'motor', CarbonImmutable::parse('2026-10-01')))->toBe(300_000_000)
            ->and(thrownBy(fn () => $limits->set('branch_officer', 'motor', 1, CarbonImmutable::parse('2026-09-20'), $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('UNDERWRITING_LIMIT_OVERLAP')
            ->and(thrownBy(fn () => $limits->set('branch_officer', 'motor', 1, CarbonImmutable::parse('2026-09-01'), $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('UNDERWRITING_LIMIT_INVALID')
            ->and(thrownBy(fn () => $limits->set('no_such_role', 'motor', 1, $today, $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('UNDERWRITING_LIMIT_INVALID')
            ->and(thrownBy(fn () => $limits->set('branch_officer', 'life', 1, $today, $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('UNDERWRITING_LIMIT_INVALID')
            ->and(thrownBy(fn () => $limits->set('branch_officer', 'misc', 1, $today, $this->officer->id), PermissionDenied::class)->permission)->toBe('underwriting.manage_limits')
            ->and(DB::table('audit_events')->where('action', 'underwriting_limit.set')->count())->toBe(5)
            ->and(DB::table('audit_events')->where('action', 'underwriting_limit.ended')->count())->toBe(1);
    });
});

it('decides referrals through the approval engine: role step, maker is not checker, SoD on the proposal, and the decider\'s own limit', function (): void {
    $referred = ($this->submit)(($this->proposal)(['sum_insured' => 300_000_000]));
    ($this->in)(function () use ($referred): void {
        $approval = DB::table('approvals')->where('id', $referred->approval_id)->sole(['object_type', 'policy_id', 'steps', 'status']);
        expect($approval->object_type)->toBe('proposal_referral')->and($approval->policy_id)->toBeNull()
            ->and(json_decode((string) $approval->steps, true))->toEqual([['permission' => 'underwriting.decide', 'role' => 'branch_manager']])
            ->and(array_column(app(ApprovalInboxQuery::class)->decidableBy($this->manager->id), 'object_id'))->toBe([$referred->id])
            ->and(app(ApprovalInboxQuery::class)->decidableBy($this->finance->id))->toBe([]);
    });
    // The branch manager who verified KYC on it (quotation.create on the proposal) may not decide it; the finance manager does not hold the step's role.
    $kycDraft = ($this->proposal)(['sum_insured' => 300_000_000, 'registration_no' => 'DHK-2', 'chassis_no' => 'CH-2'], verify: false);
    ($this->in)(fn () => app(ProposalService::class)->verifyKyc($kycDraft->id, 'passport', 'A1234567', $this->manager->id));
    $kycReferred = ($this->submit)($kycDraft);
    ($this->in)(function () use ($referred, $kycReferred): void {
        $decisions = app(UnderwritingDecisions::class);

        expect(thrownBy(fn () => $decisions->decide($kycReferred->id, Decision::Approved, null, null, $this->manager->id), SodViolation::class)->ruleCode)->toBe('SOD8')
            ->and(thrownBy(fn () => $decisions->decide($referred->id, Decision::Approved, null, null, $this->officer->id), PermissionDenied::class)->permission)->toBe('underwriting.decide')
            ->and(thrownBy(fn () => $decisions->decide($referred->id, Decision::Approved, null, null, $this->finance->id), PermissionDenied::class)->permission)->toBe('underwriting.decide');

        expect($decisions->decide($referred->id, Decision::Approved, null, 'Known customer', $this->otherManager->id))->toBe(ApprovalStatus::Approved);
        $approved = Proposal::query()->whereKey($referred->id)->firstOrFail();
        expect($approved->status->value)->toBe('approved')->and($approved->underwriting_status?->value)->toBe('approved')->and($approved->decided_by)->toBe($this->otherManager->id)
            ->and(DB::table('approvals')->where('id', $referred->approval_id)->value('status'))->toBe('approved')
            ->and(thrownBy(fn () => $decisions->decide($referred->id, Decision::Approved, null, null, $this->manager->id), BusinessRuleViolation::class)->reasonCode)->toBe('PROPOSAL_NOT_REFERRED');
    });

    // The submitter holding underwriting.decide still cannot decide their own referral (maker ≠ checker in the engine).
    $managerOwn = ($this->in)(function (): Proposal {
        $quotations = app(QuotationService::class);
        $terms = new QuotationTerms($this->ctx['branch_id'], $this->world['motor_product_id'], $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-09-15'),
            [...$this->world['motor_inputs'], 'registration_no' => 'DHK-3', 'chassis_no' => 'CH-3'], []);
        $q = $quotations->issue($quotations->saveDraft($terms, null, $this->manager->id)->id, CarbonImmutable::today(), $this->manager->id);
        $p = app(ProposalService::class)->createFromQuotation($q->id, $this->manager->id);

        return app(ProposalService::class)->submit($p->id, $this->manager->id); // KYC pending: referred to a branch manager, the submitter's own role
    });
    ($this->in)(function () use ($managerOwn): void {
        expect(json_decode((string) DB::table('approvals')->where('id', $managerOwn->approval_id)->value('steps'), true))->toEqual([['permission' => 'underwriting.decide', 'role' => 'branch_manager']])
            ->and(thrownBy(fn () => app(UnderwritingDecisions::class)->decide($managerOwn->id, Decision::Approved, null, null, $this->manager->id), SodViolation::class)->ruleCode)->toBe('MAKER_CHECKER');
    });

    // An approval policy for referrals wins over the limits; approving still needs the decider's own limit to cover the sum insured.
    approvalPolicy($this->ctx['tenant_id'], 'proposal_referral', ['min_amount_minor' => 2_000_000_000], [['permission' => 'underwriting.decide', 'role' => 'branch_manager']]);
    $large = ($this->submit)(($this->proposal)(['sum_insured' => 3_000_000_000, 'registration_no' => 'DHK-4', 'chassis_no' => 'CH-4']));
    ($this->in)(function () use ($large): void {
        $decisions = app(UnderwritingDecisions::class);
        expect(DB::table('approvals')->where('id', $large->approval_id)->value('policy_id'))->not->toBeNull()
            ->and(thrownBy(fn () => $decisions->decide($large->id, Decision::Approved, null, null, $this->otherManager->id), BusinessRuleViolation::class)->reasonCode)->toBe('UNDERWRITING_LIMIT_EXCEEDED')
            ->and(Proposal::query()->whereKey($large->id)->firstOrFail()->status->value)->toBe('submitted')
            ->and(thrownBy(fn () => $decisions->decide($large->id, Decision::Rejected, null, ' ', $this->otherManager->id), App\Modules\Platform\Approvals\ApprovalException::class)->reasonCode)->toBe('REASON_REQUIRED');
        expect($decisions->decide($large->id, Decision::Rejected, null, 'Sum insured too high for the branch', $this->otherManager->id))->toBe(ApprovalStatus::Rejected)
            ->and(Proposal::query()->whereKey($large->id)->firstOrFail()->decision_reason)->toBe('Sum insured too high for the branch')
            ->and(Proposal::query()->whereKey($large->id)->firstOrFail()->status->value)->toBe('declined');
    });
});

it('approves with a manual loading: re-rated on the original tariff, reason required and audited, shown as special terms', function (): void {
    $referred = ($this->submit)(($this->proposal)(['sum_insured' => 300_000_000]));
    // A newer tariff from today does not change the counter-offer: it re-rates on the quotation's plan version.
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/rating/01_motor_comprehensive.json'), true, 512, JSON_THROW_ON_ERROR);
    activeRatingPlan($this->ctx['tenant_id'], [...$fixture['plan'], 'version' => 2, 'effective_from' => '2026-09-15', 'steps' => array_values(array_filter($fixture['plan']['steps'], fn (array $s): bool => $s['code'] !== 'young_driver'))], supersede: true);

    ($this->in)(function () use ($referred): void {
        $decisions = app(UnderwritingDecisions::class);
        $before = Proposal::query()->whereKey($referred->id)->firstOrFail();
        expect(thrownBy(fn () => $decisions->decide($referred->id, Decision::Approved, 1000, '  ', $this->manager->id), RatingFailed::class)->reasonCode)->toBe('LOADING_REASON_REQUIRED')
            ->and(thrownBy(fn () => $decisions->decide($referred->id, Decision::Approved, 0, 'x', $this->manager->id), RatingFailed::class)->reasonCode)->toBe('MANUAL_LOADING_INVALID')
            ->and(Proposal::query()->whereKey($referred->id)->firstOrFail()->rating_result)->toBe($before->rating_result);

        $decisions->decide($referred->id, Decision::Approved, 1000, 'Vehicle used for ride sharing', $this->manager->id);
        $loaded = Proposal::query()->whereKey($referred->id)->firstOrFail();
        $quotationResult = Quotation::query()->whereKey($loaded->quotation_id)->firstOrFail()->ratingResult();
        $expected = app(RatingEngine::class)->rerate($quotationResult ?? throw new LogicException(), new ManualLoading(1000, 'Vehicle used for ride sharing'));
        $result = $loaded->ratingResult();

        expect($loaded->status->value)->toBe('approved')->and($loaded->manual_loading_bp)->toBe(1000)->and($loaded->manual_loading_reason)->toBe('Vehicle used for ride sharing')
            ->and($result->toArray())->toEqual($expected->toArray())->and($result->plan['version'])->toBe(1)
            ->and(array_column($result->explanation, 'step_code'))->toContain('young_driver', 'manual_loading')
            ->and($result->loadings[1] ?? null)->toMatchArray(['code' => 'manual_loading', 'label_en' => 'Special terms loading 10.00%', 'label_bn' => 'বিশেষ শর্ত লোডিং ১০.০০%'])
            ->and($loaded->gross_premium_minor)->toBe($result->grossPremiumMinor)->and($loaded->gross_premium_minor)->toBeGreaterThan($before->gross_premium_minor)
            ->and(app(ProposalService::class)->approvedForIssue($loaded->id)->specialTerms)->toBe('Vehicle used for ride sharing');
        $audit = DB::table('audit_events')->where('object_id', $loaded->id)->where('action', 'proposal.loading_applied')->sole(['reason', 'permission', 'actor_user_id', 'after']);
        expect($audit->reason)->toBe('Vehicle used for ride sharing')->and($audit->permission)->toBe('underwriting.decide')->and($audit->actor_user_id)->toBe($this->manager->id)
            ->and(json_decode((string) $audit->after, true)['manual_loading_bp'])->toBe(1000);
    });
});

it('verifies or waives KYC while the proposal is a draft', function (): void {
    $pending = ($this->proposal)(verify: false);
    ($this->in)(function () use ($pending): void {
        $proposals = app(ProposalService::class);
        expect(thrownBy(fn () => $proposals->verifyKyc($pending->id, 'library_card', '1', $this->officer->id), BusinessRuleViolation::class)->reasonCode)->toBe('KYC_INVALID')
            ->and(thrownBy(fn () => $proposals->waiveKyc($pending->id, 'Known to the branch', $this->officer->id), PermissionDenied::class)->permission)->toBe('underwriting.decide')
            ->and(thrownBy(fn () => $proposals->waiveKyc($pending->id, '', $this->manager->id), BusinessRuleViolation::class)->reasonCode)->toBe('REASON_REQUIRED');
        $waived = $proposals->waiveKyc($pending->id, 'Government customer, identity held on file', $this->manager->id);
        expect($waived->kyc_status->value)->toBe('waived')->and($waived->kyc_verified_by)->toBe($this->manager->id)
            ->and(DB::table('audit_events')->where('object_id', $pending->id)->where('action', 'proposal.kyc_waived')->value('reason'))->toBe('Government customer, identity held on file');
        $submitted = $proposals->submit($pending->id, $this->officer->id);
        expect($submitted->referral_reasons)->toBe([])->and($submitted->status->value)->toBe('approved')
            ->and(thrownBy(fn () => $proposals->verifyKyc($pending->id, 'nid', '1', $this->officer->id), BusinessRuleViolation::class)->reasonCode)->toBe('PROPOSAL_NOT_DRAFT');
        expect(fn () => DB::table('proposals')->where('id', $pending->id)->update(['kyc_status' => 'verified', 'kyc_id_type' => null]))->toThrow(Illuminate\Database\QueryException::class, 'proposals_kyc_complete');
    });
});

it('serves the proposal page, the referral queue and the underwriting limits screen to the people who use them', function (): void {
    Storage::fake('documents');
    $quotationId = ($this->in)(function (): string {
        $quotations = app(QuotationService::class);
        $terms = new QuotationTerms($this->ctx['branch_id'], $this->world['motor_product_id'], $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::parse('2026-09-15'),
            [...$this->world['motor_inputs'], 'sum_insured' => 300_000_000], ['passenger_liability']);

        return $quotations->issue($quotations->saveDraft($terms, null, $this->officer->id)->id, CarbonImmutable::today(), $this->officer->id)->id;
    });
    actingAs($this->officer)->post("/quotations/{$quotationId}/proposal", [], $this->headers)->assertSessionHasNoErrors()->assertSessionHas('status', 'Proposal PRP-HO-2026-000001 created.');
    $id = ($this->in)(fn (): string => Proposal::query()->sole()->id);
    actingAs($this->officer)->get("/quotations/{$quotationId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('quotation.proposal_id', $id)->where('can.convert', false));
    actingAs($this->officer)->post("/proposals/{$id}/kyc", ['action' => 'verify', 'id_type' => 'nid', 'id_number' => '1990123456789'], $this->headers)->assertSessionHasNoErrors();
    actingAs($this->officer)->post("/proposals/{$id}/documents", ['file' => UploadedFile::fake()->createWithContent('nid-scan.pdf', '%PDF-1.4 national id scan')], $this->headers)
        ->assertRedirect("/proposals/{$id}?tab=documents");
    actingAs($this->officer)->get("/proposals/{$id}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('proposals/Show')
        ->where('proposal.number', 'PRP-HO-2026-000001')->where('proposal.kyc_status', 'verified')->where('proposal.kyc_id_type', 'National ID')->where('proposal.quotation.number', 'QUO-HO-2026-000001')
        ->where('proposal.gross_premium', fn ($v): bool => is_string($v))->where('risk.0', ['label_en' => 'Vehicle type', 'label_bn' => 'যানবাহনের ধরন', 'value' => 'Private car'])
        ->where('risk.7.value', '3,000,000.00')->where('documentUpload', "/proposals/{$id}/documents")
        ->where('can', ['verify_kyc' => true, 'waive_kyc' => false, 'submit' => true, 'decide' => false, 'issue_cover_note' => false])
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload->where('documents.0.name', 'nid-scan.pdf')));
    actingAs($this->officer)->post("/proposals/{$id}/submit", [], $this->headers)->assertSessionHas('status', 'Proposal referred to underwriting.');

    actingAs($this->officer)->get('/underwriting/referrals', $this->headers)->assertForbidden();
    actingAs($this->manager)->get('/underwriting/referrals', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('underwriting/Referrals')
        ->where('referrals.0.id', $id)->where('referrals.0.can_decide', true)->where('referrals.0.referral_reasons.0.code', 'SUM_INSURED_ABOVE_LIMIT')
        ->where('referrals.0.rating_result.gross_premium_minor', fn ($v): bool => is_int($v)));
    actingAs($this->manager)->post("/underwriting/referrals/{$id}/decide", ['decision' => 'approve_with_loading', 'loading_percent' => '12.50', 'reason' => ''], $this->headers)->assertSessionHasErrors('reason');
    actingAs($this->manager)->post("/underwriting/referrals/{$id}/decide", ['decision' => 'approve_with_loading', 'loading_percent' => '12.50', 'reason' => 'Night use'], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Proposal approved.');
    expect(($this->in)(fn (): ?int => Proposal::query()->sole()->manual_loading_bp))->toBe(1250);

    $admin = ($this->person)('Tina Admin', ['tenant_admin']);
    actingAs($this->manager)->get('/admin/underwriting-limits', $this->headers)->assertForbidden();
    actingAs($admin)->get('/admin/underwriting-limits', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('admin/underwriting-limits/Index')
        ->where('limits.0.class_code', 'fire')->where('currency', 'BDT')
        ->where('classes', fn ($classes): bool => count($classes) === 4));
    actingAs($admin)->post('/admin/underwriting-limits', ['role_code' => 'branch_officer', 'class_code' => 'misc', 'max_sum_insured' => '500,000.00', 'effective_from' => '2026-09-15'], $this->headers)
        ->assertSessionHasNoErrors();
    $limitId = ($this->in)(fn (): string => (string) DB::table('underwriting_limits')->where('class_code', 'misc')->value('id'));
    actingAs($admin)->post("/admin/underwriting-limits/{$limitId}/end", ['effective_to' => '2026-09-30'], $this->headers)->assertSessionHasNoErrors();
    expect(($this->in)(fn () => DB::table('underwriting_limits')->where('id', $limitId)->first(['max_sum_insured_minor', 'effective_to'])))->toEqual((object) ['max_sum_insured_minor' => 50_000_000, 'effective_to' => '2026-09-30']);

    ($this->in)(function (): void {
        $holders = fn (string $permission): array => DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')->where('rp.permission_code', $permission)
            ->where('r.code', 'not like', 'test-%')->orderBy('r.code')->pluck('r.code')->all();
        expect($holders('underwriting.decide'))->toBe(['branch_manager', 'cfo', 'finance_manager'])->and($holders('underwriting.manage_limits'))->toBe(['tenant_admin']);
    });
});
