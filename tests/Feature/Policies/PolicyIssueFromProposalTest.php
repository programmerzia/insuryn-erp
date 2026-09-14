<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\CoverNote\Application\CoverNoteService;
use App\Modules\Insurance\Policy\Application\Documents\PolicyScheduleDocumentData;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Policy\Domain\Events\PolicyIssued;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Insurance\Product\Domain\Risk\RiskInputsInvalid;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use App\Modules\Insurance\Rating\Application\RatingEngine;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use App\Modules\Insurance\Underwriting\Application\UnderwritingDecisions;
use App\Modules\Insurance\Underwriting\Application\UnderwritingLimits;
use App\Modules\Insurance\Underwriting\Domain\Models\Proposal;
use App\Modules\Platform\Approvals\Decision;
use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Phase 3 design §2 steps 4–5 and INVARIANT "rating_result frozen at issue" (slice R7): a policy is issued from an approved proposal — automatically approved or
 * referred and approved with a loading — with the premium of its frozen rating result (net, VAT, stamp duty on its own line), the proposal marked issued and its cover
 * note superseded in the same transaction; credit issue needs the product flag or a premium-received reference; endorsements re-rate the risk on the original plan
 * version (or the current tariff when the product says so) and post the change; typed premiums are refused for rated products; issued policies are duplicate risks.
 * Amounts worked by hand from the golden motor quote (tests/Fixtures/rating/01: net 26,842.00, stamp 50.00, VAT 4,026.30, gross 30,918.30).
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->world = ratedProductsWorld($this->ctx);
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
    ($this->in)(function (): void {
        $limits = app(UnderwritingLimits::class);
        $limits->set('branch_officer', 'motor', 200_000_000, CarbonImmutable::today(), $this->world['admin']); // 2,000,000.00
        $limits->set('branch_manager', 'motor', 1_000_000_000, CarbonImmutable::today(), $this->world['admin']);
    });
    /** A submitted proposal of the officer for the golden motor risk (a vehicle of its own unless told), approved automatically unless a rule refers it. */
    $this->submitted = fn (array $inputs = []): Proposal => ($this->in)(function () use ($inputs): Proposal {
        $quotations = app(QuotationService::class);
        $terms = new QuotationTerms($this->ctx['branch_id'], $this->world['motor_product_id'], $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::parse('2026-09-15'),
            [...$this->world['motor_inputs'], 'registration_no' => 'DHK-'.Str::random(6), 'chassis_no' => 'CH-'.Str::random(8), ...$inputs], ['passenger_liability']);
        $quotation = $quotations->issue($quotations->saveDraft($terms, null, $this->officer->id)->id, CarbonImmutable::today(), $this->officer->id);
        $proposals = app(ProposalService::class);
        $proposal = $proposals->createFromQuotation($quotation->id, $this->officer->id);
        $proposals->verifyKyc($proposal->id, 'nid', '1990123456789', $this->officer->id);

        return $proposals->submit($proposal->id, $this->officer->id);
    });
    $this->issue = fn (Proposal $proposal, ?string $reference = 'TRF 4471', int $installments = 1): Policy => ($this->in)(fn (): Policy => app(PolicyLifecycle::class)
        ->issueFromProposal($proposal->id, CarbonImmutable::today(), $this->officer->id, $installments, $reference));
    $this->lines = fn (string $eventType, string $policyId): array => ($this->in)(fn (): array => array_values(array_map(fn (object $l): array => [(string) $l->role_code, (string) $l->side, (int) $l->amount_minor],
        DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('journal_batches as b', 'b.id', '=', 'j.batch_id')
            ->join('accounting_events as e', 'e.id', '=', 'b.accounting_event_id')->where('e.event_type', $eventType)->where('l.dim_policy', $policyId)
            ->orderBy('j.posted_at')->orderBy('l.line_no')->get(['l.role_code', 'l.side', 'l.amount_minor'])->all())));
});

it('issues a policy from an auto-approved proposal on its frozen rating, marks the proposal issued and supersedes its cover note in one transaction', function (): void {
    $proposal = ($this->submitted)();
    $note = ($this->in)(fn () => app(CoverNoteService::class)->issue($proposal->id, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-10-14'), $this->officer->id));

    $policy = ($this->issue)($proposal, 'TRF 4471', 2);

    ($this->in)(function () use ($policy, $proposal, $note): void {
        $policy = Policy::query()->whereKey($policy->id)->firstOrFail();
        $frozen = Proposal::query()->whereKey($proposal->id)->firstOrFail()->ratingResult();
        expect($policy->number)->toBe('POL-HO-2026-000001')->and($policy->status->value)->toBe('issued')
            ->and($policy->gross_premium_minor)->toBe(3_091_830)->and($policy->net_premium_minor)->toBe(2_684_200)
            ->and($policy->tax_minor)->toBe(402_630)->and($policy->stamp_duty_minor)->toBe(5_000)
            ->and($policy->rating_result)->toEqual($frozen->toArray())->and($policy->rating_plan_code)->toBe('MOTOR-TARIFF')->and($policy->rating_plan_version)->toBe(1)
            ->and($policy->risk_inputs)->toEqual($frozen->riskInputs)->and($policy->risk_keys)->toHaveCount(2)
            ->and($policy->proposal_id)->toBe($proposal->id)->and($policy->quotation_id)->toBe($proposal->quotation_id)->and($policy->cover_note_id)->toBe($note->id)
            ->and($policy->agent_id)->toBe($this->world['agent_id'])->and($policy->inception->toDateString())->toBe('2026-09-15')->and($policy->expiry->toDateString())->toBe('2027-09-14')
            ->and($policy->issue_basis)->toBe('premium_received')->and($policy->premium_received_reference)->toBe('TRF 4471')->and($policy->special_terms)->toBe([])
            ->and(DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->pluck('amount_minor')->map(fn ($a): int => (int) $a)->sum())->toBe(3_091_830)
            ->and(DB::table('proposals')->where('id', $proposal->id)->first(['status', 'policy_id']))->toEqual((object) ['status' => 'issued', 'policy_id' => $policy->id])
            ->and(DB::table('cover_notes')->where('id', $note->id)->first(['status', 'superseded_by_policy_id']))->toEqual((object) ['status' => 'superseded', 'superseded_by_policy_id' => $policy->id])
            ->and(DB::table('policy_transactions')->where('policy_id', $policy->id)->first(['type', 'premium_delta_minor', 'stamp_duty_delta_minor']))
            ->toEqual((object) ['type' => 'new', 'premium_delta_minor' => 3_091_830, 'stamp_duty_delta_minor' => 5_000]);
        $audit = DB::table('audit_events')->where('object_id', $policy->id)->where('action', 'policy.issued')->sole(['after', 'permission']);
        expect($audit->permission)->toBe('policy.issue')->and(json_decode((string) $audit->after, true))->toMatchArray(['rating_plan' => 'MOTOR-TARIFF v1', 'issue_basis' => 'premium_received',
            'premium_received_reference' => 'TRF 4471', 'stamp_duty_minor' => 5_000, 'cover_notes_superseded' => 1]);
    });
    // POLICY_ISSUED amounts are the rating result's: receivable = gross, unearned = net, VAT, stamp duty on its own account (D-37).
    expect(($this->lines)('POLICY_ISSUED', $policy->id))->toBe([['premium_receivable', 'debit', 3_091_830], ['unearned_premium', 'credit', 2_684_200],
        ['premium_tax_payable', 'credit', 402_630], ['stamp_duty_payable', 'credit', 5_000]]);

    // The proposal is issued: it cannot issue a second policy.
    expect(thrownBy(fn () => ($this->issue)($proposal), BusinessRuleViolation::class)->reasonCode)->toBe('PROPOSAL_NOT_APPROVED');
});

it('issues a referred proposal once approved with a loading, carrying the special terms onto the policy and its schedule', function (): void {
    $referred = ($this->submitted)(['sum_insured' => 300_000_000]);
    expect($referred->status->value)->toBe('submitted')
        ->and(thrownBy(fn () => ($this->issue)($referred), BusinessRuleViolation::class)->reasonCode)->toBe('PROPOSAL_NOT_APPROVED');
    ($this->in)(fn () => app(UnderwritingDecisions::class)->decide($referred->id, Decision::Approved, 1000, 'Vehicle used for ride sharing', $this->manager->id));

    $policy = ($this->issue)($referred);

    ($this->in)(function () use ($policy, $referred): void {
        $loaded = Proposal::query()->whereKey($referred->id)->firstOrFail();
        $policy = Policy::query()->whereKey($policy->id)->firstOrFail();
        expect($policy->gross_premium_minor)->toBe($loaded->gross_premium_minor)->and($policy->rating_result)->toEqual($loaded->rating_result)
            ->and(array_column($policy->rating_result['explanation'] ?? [], 'step_code'))->toContain('manual_loading')
            ->and($policy->special_terms)->toEqual([['code' => 'manual_loading', 'loading_bp' => 1000, 'reason' => 'Vehicle used for ride sharing',
                'text' => 'Special terms loading 10.00%: Vehicle used for ride sharing']]);
        $schedule = app(PolicyScheduleDocumentData::class)->variables($policy->id, DocumentTemplateCode::PolicySchedule, 'en');
        expect($schedule['special_terms'])->toContain('Special terms loading 10.00%: Vehicle used for ride sharing')
            ->and(array_column($schedule['money'], 'label'))->toBe(['Net premium', 'Stamp duty', 'VAT 15%'])
            ->and(array_column($schedule['details'], 'value', 'label')['Tariff'] ?? null)->toBe('MOTOR-TARIFF v1')
            ->and(array_column($schedule['rating'], 'label'))->toContain('Special terms loading 10.00%', 'Young driver loading 10%');
    });
});

it('rolls everything back when the issue fails after the proposal and the cover note were changed', function (): void {
    $proposal = ($this->submitted)();
    $note = ($this->in)(fn () => app(CoverNoteService::class)->issue($proposal->id, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-10-14'), $this->officer->id));
    Event::listen(PolicyIssued::class, fn () => throw new RuntimeException('listener failed'));

    expect(fn () => ($this->issue)($proposal))->toThrow(RuntimeException::class, 'listener failed');

    ($this->in)(function () use ($proposal, $note): void {
        expect(DB::table('policies')->count())->toBe(0)->and(DB::table('policy_transactions')->count())->toBe(0)->and(DB::table('installments')->count())->toBe(0)
            ->and(DB::table('accounting_events')->where('event_type', 'POLICY_ISSUED')->count())->toBe(0)
            ->and(DB::table('proposals')->where('id', $proposal->id)->value('status'))->toBe('approved')
            ->and(DB::table('cover_notes')->where('id', $note->id)->value('status'))->toBe('active')
            ->and(DB::table('document_numbers')->where('number', 'like', 'POL-%')->where('status', 'used')->count())->toBe(0);
    });
});

it('freezes the rating of an issued policy and of its endorsements in the database', function (): void {
    $policy = ($this->issue)(($this->submitted)());

    ($this->in)(function () use ($policy): void {
        foreach (['rating_result' => "'{}'::jsonb", 'risk_inputs' => "'{}'::jsonb", 'rating_plan_version' => '2', 'rating_plan_code' => "'OTHER'", 'special_terms' => "'[]'::jsonb || '[\"x\"]'::jsonb", 'proposal_id' => 'NULL'] as $column => $value) {
            expect(fn () => DB::statement("UPDATE policies SET {$column} = {$value} WHERE id = ?", [$policy->id]))->toThrow(QueryException::class, 'POLICY_RATING_FROZEN');
        }
        expect(fn () => DB::statement('UPDATE policy_transactions SET rating_result = NULL, premium_delta_minor = premium_delta_minor + 1 WHERE policy_id = ?', [$policy->id]))
            ->toThrow(QueryException::class, 'POLICY_RATING_FROZEN');
        // Everything else about a policy still moves (status, version, premium totals through endorsements).
        DB::statement("UPDATE policies SET status = 'active' WHERE id = ?", [$policy->id]);
        expect(DB::table('policies')->where('id', $policy->id)->value('status'))->toBe('active');
    });
});

it('issues on credit only when the product version allows it, otherwise with a premium-received reference, within the quotation validity', function (): void {
    $proposal = ($this->submitted)();
    expect(thrownBy(fn () => ($this->issue)($proposal, null), BusinessRuleViolation::class)->reasonCode)->toBe('PREMIUM_NOT_RECEIVED')
        ->and(thrownBy(fn () => ($this->issue)($proposal, '   '), BusinessRuleViolation::class)->reasonCode)->toBe('PREMIUM_NOT_RECEIVED')
        ->and(($this->in)(fn () => [DB::table('policies')->count(), DB::table('proposals')->where('id', $proposal->id)->value('status')]))->toBe([0, 'approved']);

    // A credit product issues without a reference, recorded as credit.
    ($this->in)(fn () => app(ProductCatalogue::class)->configureRating($this->world['motor_version_id'], ['allow_credit_issue' => true], $this->world['admin']));
    $onCredit = ($this->issue)($proposal, null);
    expect($onCredit->issue_basis)->toBe('credit')->and($onCredit->premium_received_reference)->toBeNull();

    // After the quotation's validity (15 days including the issue day) the premium is no longer guaranteed (A-116), unless configured.
    $late = ($this->submitted)();
    travelTo(CarbonImmutable::parse('2026-09-30 09:00'));
    expect(thrownBy(fn () => ($this->in)(fn () => app(PolicyLifecycle::class)->issueFromProposal($late->id, CarbonImmutable::parse('2026-09-30'), $this->officer->id)), BusinessRuleViolation::class)->reasonCode)
        ->toBe('QUOTATION_EXPIRED');
    config(['erp.policies.issue_within_quotation_validity' => false]);
    expect(($this->in)(fn () => app(PolicyLifecycle::class)->issueFromProposal($late->id, CarbonImmutable::parse('2026-09-30'), $this->officer->id))->status->value)->toBe('issued');
});

it('endorses a risk change re-rated on the original plan version even after a newer tariff, posting the delta and keeping the issue rating frozen', function (): void {
    $policy = ($this->issue)(($this->submitted)());
    // A newer tariff without the young-driver loading takes over from 1 October; the policy keeps rating on version 1.
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/rating/01_motor_comprehensive.json'), true, 512, JSON_THROW_ON_ERROR);
    activeRatingPlan($this->ctx['tenant_id'], [...$fixture['plan'], 'version' => 2, 'effective_from' => '2026-10-01', 'steps' => array_values(array_filter($fixture['plan']['steps'], fn (array $s): bool => $s['code'] !== 'young_driver'))], supersede: true);
    $admin = $this->world['admin'];

    ($this->in)(function () use ($policy, $admin): void {
        $lifecycle = app(PolicyLifecycle::class);
        $issued = Policy::query()->whereKey($policy->id)->firstOrFail();
        $frozen = $issued->rating_result;
        $older = [...($issued->risk_inputs ?? []), 'driver_age' => 30];

        expect(thrownBy(fn () => $lifecycle->endorseRisk($policy->id, CarbonImmutable::parse('2026-10-15'), $issued->risk_inputs ?? [], 'No change', $admin), BusinessRuleViolation::class)->reasonCode)
            ->toBe('ENDORSEMENT_NO_CHANGE')
            ->and(thrownBy(fn () => $lifecycle->endorseRisk($policy->id, CarbonImmutable::parse('2026-10-15'), $older, ' ', $admin), BusinessRuleViolation::class)->reasonCode)->toBe('REASON_REQUIRED')
            ->and(thrownBy(fn () => $lifecycle->endorseRisk($policy->id, CarbonImmutable::parse('2027-10-15'), $older, 'Late', $admin), BusinessRuleViolation::class)->reasonCode)->toBe('ENDORSEMENT_OUTSIDE_COVER')
            // Typing a premium change is refused for a rated policy.
            ->and(thrownBy(fn () => $lifecycle->endorse($policy->id, CarbonImmutable::parse('2026-10-15'), 100_000, 'Typed', $admin), BusinessRuleViolation::class)->reasonCode)->toBe('PRODUCT_RATED');

        // The preview writes nothing. The driver is now 30, so the young-driver loading goes: net 24,402.00 (−2,440.00), VAT 3,660.30 (−366.00), stamp duty unchanged.
        $preview = $lifecycle->rateEndorsement($policy->id, CarbonImmutable::parse('2026-10-15'), $older, $admin);
        expect($preview->basis)->toBe('original_plan')->and($preview->after->plan['version'])->toBe(1)
            ->and([$preview->change->netMinor, $preview->change->taxMinor, $preview->change->stampDutyMinor, $preview->change->grossMinor()])->toBe([-244_000, -36_600, 0, -280_600])
            ->and(DB::table('policy_transactions')->where('policy_id', $policy->id)->count())->toBe(1);

        $endorsed = $lifecycle->endorseRisk($policy->id, CarbonImmutable::parse('2026-10-15'), $older, 'Named driver changed', $admin);
        $transaction = DB::table('policy_transactions')->where('policy_id', $policy->id)->where('type', 'endorsement')->sole();
        expect($endorsed->version)->toBe(2)->and($endorsed->gross_premium_minor)->toBe(2_811_230)->and($endorsed->net_premium_minor)->toBe(2_440_200)
            ->and($endorsed->tax_minor)->toBe(366_030)->and($endorsed->stamp_duty_minor)->toBe(5_000)
            ->and(Policy::query()->whereKey($policy->id)->firstOrFail()->rating_result)->toEqual($frozen)
            ->and((int) $transaction->premium_delta_minor)->toBe(-280_600)->and($transaction->rating_basis)->toBe('original_plan')
            ->and(json_decode((string) $transaction->rating_result, true)['risk_inputs']['driver_age'])->toBe(30)
            ->and(json_decode((string) $transaction->rating_result, true)['net_premium_minor'])->toBe(2_440_200);

        // A second change is rated against the rating now in force (the first endorsement's): 7 seats add 90.00 before the 20% no-claim bonus.
        $second = $lifecycle->rateEndorsement($policy->id, CarbonImmutable::parse('2026-11-01'), [...$older, 'seats' => 7], $admin);
        expect($second->before->netPremiumMinor)->toBe(2_440_200)->and($second->change->netMinor)->toBe(7_200);
    });
    expect(($this->lines)('POLICY_ENDORSED', $policy->id))->toBe([['premium_receivable', 'credit', 280_600], ['unearned_premium', 'debit', 244_000], ['premium_tax_payable', 'debit', 36_600]]);
});

it('re-rates endorsements on the tariff in force when the product version says so, and charges pro rata when configured', function (): void {
    ($this->in)(fn () => app(ProductCatalogue::class)->configureRating($this->world['motor_version_id'], ['endorsement_uses_current_tariff' => true], $this->world['admin']));
    $policy = ($this->issue)(($this->submitted)());
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/rating/01_motor_comprehensive.json'), true, 512, JSON_THROW_ON_ERROR);
    activeRatingPlan($this->ctx['tenant_id'], [...$fixture['plan'], 'version' => 2, 'effective_from' => '2026-10-01', 'steps' => array_values(array_filter($fixture['plan']['steps'], fn (array $s): bool => $s['code'] !== 'young_driver'))], supersede: true);
    $admin = $this->world['admin'];

    ($this->in)(function () use ($policy, $admin): void {
        $lifecycle = app(PolicyLifecycle::class);
        $issued = Policy::query()->whereKey($policy->id)->firstOrFail();
        $seven = [...($issued->risk_inputs ?? []), 'seats' => 7];
        // The same change on both tariffs: version 1 (young-driver loading kept) nets 26,922.00; version 2 in force on 15 October (no loading) nets 24,474.00.
        $current = $lifecycle->rateEndorsement($policy->id, CarbonImmutable::parse('2026-10-15'), $seven, $admin);
        $original = app(RatingEngine::class)->rerateWith($issued->ratingResult() ?? throw new LogicException('rated'), $seven);
        expect($current->basis)->toBe('current_tariff')->and($current->after->plan['version'])->toBe(2)->and($current->after->asOf)->toBe('2026-10-15')
            ->and([$current->change->netMinor, $current->change->taxMinor, $current->change->grossMinor()])->toBe([-236_800, -35_520, -272_320])
            ->and($original->netPremiumMinor)->toBe(2_692_200)->and($original->grossPremiumMinor)->toBe(3_101_030)->and($original->plan['version'])->toBe(1);

        // Pro rata (A-119): net premium and VAT for the 335 of 365 days left, stamp duty in full.
        config(['erp.policies.endorsement_premium' => 'pro_rata']);
        $endorsed = $lifecycle->endorseRisk($policy->id, CarbonImmutable::parse('2026-10-15'), $seven, 'Two more seats', $admin);
        $transaction = DB::table('policy_transactions')->where('policy_id', $policy->id)->where('type', 'endorsement')->sole();
        expect([(int) $transaction->net_delta_minor, (int) $transaction->tax_delta_minor, (int) $transaction->stamp_duty_delta_minor])->toBe([-217_337, -32_601, 0])
            ->and($transaction->rating_basis)->toBe('current_tariff')
            ->and($endorsed->gross_premium_minor)->toBe(3_091_830 - 217_337 - 32_601);
    });
});

it('refuses typed premiums for rated products and keeps them for products without a rating plan', function (): void {
    ($this->in)(function (): void {
        $lifecycle = app(PolicyLifecycle::class);
        $request = fn (string $product): QuoteRequest => new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $product, $this->world['policyholder_id'], null,
            CarbonImmutable::parse('2026-09-20'), 1_200_000, 'BDT');
        expect(thrownBy(fn () => $lifecycle->quote($request($this->world['motor_product_id']), $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('PRODUCT_RATED')
            ->and(DB::table('policies')->count())->toBe(0);
        $unrated = $lifecycle->issue($lifecycle->quote($request($this->world['product_id']), $this->world['admin'])->id, CarbonImmutable::parse('2026-09-20'), $this->world['admin']);
        expect($unrated->status->value)->toBe('issued')->and($unrated->rating_result)->toBeNull()->and($unrated->stamp_duty_minor)->toBe(0)
            ->and($lifecycle->endorse($unrated->id, CarbonImmutable::parse('2026-10-01'), 115_000, 'Extra cover', $this->world['admin'])->version)->toBe(2)
            ->and(thrownBy(fn () => $lifecycle->rateEndorsement($unrated->id, CarbonImmutable::parse('2026-10-01'), [], $this->world['admin']), BusinessRuleViolation::class)->reasonCode)->toBe('POLICY_NOT_RATED');
    });
});

it('finds issued policies as duplicate risks by their current risk keys', function (): void {
    $policy = ($this->issue)(($this->submitted)(['registration_no' => 'Dhaka Metro-GA 11-2233', 'chassis_no' => 'CHS-FIRST-001']));

    $same = ($this->submitted)(['registration_no' => 'DHAKA METRO GA 112233', 'chassis_no' => 'CHS-OTHER-002']);
    expect(array_column($same->referral_reasons ?? [], 'code'))->toBe(['DUPLICATE_RISK'])
        ->and($same->referral_reasons[0]['detail'] ?? '')->toBe("The same risk is on {$policy->number}.");
    ($this->in)(fn () => app(UnderwritingDecisions::class)->decide($same->id, Decision::Rejected, null, 'Vehicle already insured', $this->manager->id));

    // The vehicle on the policy is replaced by endorsement: the old registration is free again, the new one is taken.
    ($this->in)(fn () => app(PolicyLifecycle::class)->endorseRisk($policy->id, CarbonImmutable::parse('2026-10-01'),
        [...(Policy::query()->whereKey($policy->id)->firstOrFail()->risk_inputs ?? []), 'registration_no' => 'DHK-NEW-7788', 'chassis_no' => 'CHS-NEW-7788'], 'Vehicle replaced', $this->world['admin']));
    $old = ($this->submitted)(['registration_no' => 'DHAKA METRO GA 112233', 'chassis_no' => 'CHS-THIRD-003']);
    $new = ($this->submitted)(['registration_no' => 'dhk new 7788', 'chassis_no' => 'CHS-FOURTH-004']);
    expect($old->referral_reasons)->toBe([])->and($old->status->value)->toBe('approved')
        ->and(array_column($new->referral_reasons ?? [], 'code'))->toBe(['DUPLICATE_RISK'])->and($new->referral_reasons[0]['detail'] ?? '')->toContain((string) $policy->number);
});


it('refuses an endorsement that empties a risk detail needed only from the proposal, and endorses while it stays filled', function (): void {
    // Flow fix X7 made the motor chassis number optional on the quote and required at the proposal; an endorsement changes an issued policy, so it needs it too.
    $policy = ($this->issue)(($this->submitted)());
    $admin = $this->world['admin'];
    $headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $user = ($this->in)(fn (): User => User::query()->findOrFail((string) $admin));
    $issued = ($this->in)(fn (): Policy => Policy::query()->whereKey($policy->id)->firstOrFail());
    $chassis = (string) ($issued->risk_inputs['chassis_no'] ?? '');
    $older = [...($issued->risk_inputs ?? []), 'driver_age' => 30];
    expect($chassis)->not->toBe('');

    ($this->in)(function () use ($policy, $older, $admin): void {
        $lifecycle = app(PolicyLifecycle::class);
        foreach ([[...$older, 'chassis_no' => null], [...$older, 'chassis_no' => ''], array_diff_key($older, ['chassis_no' => true])] as $cleared) {
            expect(thrownBy(fn () => $lifecycle->rateEndorsement($policy->id, CarbonImmutable::parse('2026-10-15'), $cleared, $admin), RiskInputsInvalid::class)->errors)->toBe(['chassis_no' => 'REQUIRED'])
                ->and(thrownBy(fn () => $lifecycle->endorseRisk($policy->id, CarbonImmutable::parse('2026-10-15'), $cleared, 'Named driver changed', $admin), RiskInputsInvalid::class)->errors)->toBe(['chassis_no' => 'REQUIRED']);
        }
    });

    // On the policy page: the live re-rating names the field (the drawer shows "Enter the chassis number."), and posting is refused.
    actingAs($user)->postJson("/policies/{$policy->id}/endorsement-rating", ['effective_date' => '2026-10-15', 'risk_inputs' => [...$older, 'chassis_no' => '']], $headers)
        ->assertStatus(422)->assertJsonPath('reason', 'RISK_INPUTS_INVALID')->assertJsonPath('errors', ['chassis_no' => 'REQUIRED'])
        ->assertJsonPath('fields', ['chassis_no' => 'Enter the chassis number.'])->assertJsonPath('message', 'Check the risk details: chassis number.');
    // Follow-up H2: the refusal names the field with its label, not "(chassis_no: REQUIRED)" — on the post, and on the journal preview the drawer asks for first.
    actingAs($user)->post("/policies/{$policy->id}/endorse-risk", ['effective_date' => '2026-10-15', 'risk_inputs' => [...$older, 'chassis_no' => ''], 'reason' => 'Named driver changed'], $headers)
        ->assertSessionHasErrors(['reason' => 'RISK_INPUTS_INVALID', 'form' => 'Check the risk details: chassis number.', 'risk_inputs.chassis_no' => 'Enter the chassis number.']);
    actingAs($user)->postJson("/policies/{$policy->id}/endorse-risk", ['effective_date' => '2026-10-15', 'risk_inputs' => [...$older, 'chassis_no' => ''], 'reason' => 'Named driver changed'],
        [...$headers, 'X-Journal-Preview' => '1'])->assertStatus(422)
        ->assertJson(['errors' => ['form' => 'Check the risk details: chassis number.', 'risk_inputs.chassis_no' => 'Enter the chassis number.', 'reason' => 'RISK_INPUTS_INVALID']]);
    ($this->in)(fn () => app(App\Modules\Platform\Preferences\UserPreferences::class)->set((string) $admin, 'locale', 'bn'));
    actingAs($user)->post("/policies/{$policy->id}/endorse-risk", ['effective_date' => '2026-10-15', 'risk_inputs' => [...$older, 'chassis_no' => ''], 'reason' => 'Named driver changed'], $headers)
        ->assertSessionHasErrors(['form' => 'ঝুঁকির বিবরণ দেখুন: চেসিস নম্বর।', 'risk_inputs.chassis_no' => 'চেসিস নম্বর লিখুন।']);
    ($this->in)(fn () => app(App\Modules\Platform\Preferences\UserPreferences::class)->set((string) $admin, 'locale', 'en'));
    expect(($this->in)(fn (): array => [DB::table('policy_transactions')->where('policy_id', $policy->id)->where('type', 'endorsement')->count(),
        Policy::query()->whereKey($policy->id)->firstOrFail()->risk_inputs['chassis_no'] ?? null]))->toBe([0, $chassis]);

    actingAs($user)->post("/policies/{$policy->id}/endorse-risk", ['effective_date' => '2026-10-15', 'risk_inputs' => $older, 'reason' => 'Named driver changed'], $headers)
        ->assertSessionHasNoErrors()->assertRedirect("/policies/{$policy->id}?tab=rating");
    // The endorsement's rating (the risk now in force) keeps the chassis number; the issue rating stays frozen.
    [$version, $rated] = ($this->in)(fn (): array => [Policy::query()->whereKey($policy->id)->firstOrFail()->version,
        json_decode((string) DB::table('policy_transactions')->where('policy_id', $policy->id)->where('type', 'endorsement')->value('rating_result'), true)['risk_inputs'] ?? []]);
    expect($version)->toBe(2)->and($rated['chassis_no'] ?? null)->toBe($chassis)->and($rated['driver_age'] ?? null)->toBe(30);
});
