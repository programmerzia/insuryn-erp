<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application;

use App\Modules\Distribution\Application\Licences\LicenceRegistry;
use App\Modules\Insurance\CoverNote\Application\CoverNoteService;
use App\Modules\Insurance\Policy\Domain\EarningSchedule;
use App\Modules\Insurance\Policy\Domain\Enums\PolicyStatus;
use App\Modules\Insurance\Policy\Domain\Enums\PolicyTransactionType;
use App\Modules\Insurance\Policy\Domain\Events\PolicyCancelled;
use App\Modules\Insurance\Policy\Domain\Events\PolicyEndorsed;
use App\Modules\Insurance\Policy\Domain\Events\PolicyIssued;
use App\Modules\Insurance\Policy\Domain\Events\PolicyRenewed;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Policy\Domain\Models\PolicyTransaction;
use App\Modules\Insurance\Policy\Domain\PremiumMath;
use App\Modules\Insurance\Policy\Domain\RatedPremium;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Insurance\Product\Domain\Enums\RiskStage;
use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
use App\Modules\Insurance\Quotation\Application\RiskKeys;
use App\Modules\Insurance\Rating\Application\RatingEngine;
use App\Modules\Insurance\Rating\Domain\ManualLoading;
use App\Modules\Insurance\Rating\Domain\RatingResult;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use App\Modules\Platform\Tax\TaxRates;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Design §5.4 policy lifecycle:
 *   quote ─issue─▶ issued ─inception reached─▶ active ─▶ expired
 *   issued | active ─cancel─▶ cancelled;  active ─lapse─▶ lapsed ─reinstate─▶ active;  active | expired ─renew─▶ renewed (+ new quote)
 *   endorse: issued | active, bumps version, creates a policy transaction.
 * Issue, endorse and cancel emit their accounting event (§4.1, §4.4) in the same transaction as the policy change (§8.2).
 *
 * Phase 3 (slice R7): a product version with a product class is rated by its tariff. Its policies are issued from approved proposals (`issueFromProposal`) with
 * the rating result frozen (INVARIANT, database trigger POLICY_RATING_FROZEN) and changed by re-rated endorsements (`endorseRisk`). Typing a premium —
 * `quote` and `endorse` — stays only for products without a rating plan (LATER removal) and is refused for rated ones.
 */
final class PolicyLifecycle
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ProductCatalogue $products,
        private readonly TaxRates $taxRates,
        private readonly DocumentNumberer $numbers,
        private readonly InstallmentPlanner $installments,
        private readonly PolicyAccountingEvents $accounting,
        private readonly Audit $audit,
        private readonly ProposalService $proposals,
        private readonly CoverNoteService $coverNotes,
        private readonly RatingEngine $rating,
    ) {}

    /** ASSUMPTION: A-115 — a product version with a product class is rated by its tariff (RatingEngine refuses PRODUCT_NOT_RATED exactly when it has none). */
    public static function isRated(ProductVersion $version): bool
    {
        return $version->class_code !== null;
    }

    public function quote(QuoteRequest $request, string $actorUserId): Policy
    {
        $this->permissions->authorize($actorUserId, 'policy.create', AuthorizationScope::branch($request->entityId, $request->branchId));
        if ($request->premiumMinor <= 0 || $request->installmentCount < 1) {
            throw new BusinessRuleViolation('INVALID_PREMIUM', 'A quote needs a positive premium and at least one installment.');
        }
        $this->assertPayers($request->payers);
        $version = $this->products->versionOn($request->productId, $request->inception);
        if (self::isRated($version)) {
            throw new BusinessRuleViolation('PRODUCT_RATED', 'This product is priced by its tariff, so its premium cannot be typed in. Quote it in Quotes; the policy is issued from the approved proposal.');
        }
        $premium = PremiumMath::splitTax($request->premiumMinor, $this->taxRate($version, $request->inception), (bool) $version->tax_profile['inclusive']);

        return DB::transaction(function () use ($request, $version, $premium, $actorUserId): Policy {
            $policy = Policy::query()->create([
                'entity_id' => $request->entityId, 'branch_id' => $request->branchId, 'product_id' => $request->productId, 'product_version_id' => $version->id,
                'policyholder_party_id' => $request->policyholderPartyId, 'agent_id' => $request->agentId, 'channel' => $request->agentId === null ? 'direct' : 'agent',
                'status' => PolicyStatus::Quote->value, 'inception' => $request->inception->toDateString(),
                'expiry' => $request->inception->addMonthsNoOverflow($version->term_months)->subDay()->toDateString(),
                'currency' => $request->currency, 'gross_premium_minor' => $premium['gross'], 'tax_minor' => $premium['tax'], 'net_premium_minor' => $premium['net'],
                'installment_count' => $request->installmentCount, 'version' => 1, 'created_by' => $actorUserId,
            ]);
            foreach ($request->payers as $payer) {
                DB::table('policy_payers')->insert(['id' => (string) \Illuminate\Support\Str::uuid7(), 'tenant_id' => $policy->tenant_id, 'policy_id' => $policy->id,
                    'party_id' => $payer->partyId, 'share_bp' => $payer->shareBp, 'created_at' => now()]);
            }
            $this->audit->record('policy.quoted', AuditSubject::of('policy', $policy->id), null, ['premium' => $premium, 'product_version' => $version->version,
                'payers' => array_map(fn (PayerShare $p): array => ['party_id' => $p->partyId, 'share_bp' => $p->shareBp], $request->payers)], null, 'policy.create', Actor::user($actorUserId));

            return $policy;
        });
    }

    public function issue(string $policyId, CarbonImmutable $on, string $actorUserId): Policy
    {
        $policy = Policy::query()->findOrFail($policyId);
        $this->authorize($actorUserId, 'policy.issue', $policy);
        $this->assertStatus($policy, [PolicyStatus::Quote], 'issue');
        if ($policy->agent_id !== null && $policy->renewal_of_policy_id === null) {
            // Distribution design note §3: new business needs a producer licensed for the product's class on the issue date; renewals are not new business.
            app(LicenceRegistry::class)->assertMayWriteNewBusiness($policy->agent_id, (string) DB::table('products')->where('id', $policy->product_id)->value('insurance_class'), $on);
        }
        $number = $this->numbers->reserve(new DocumentNumberScope($policy->entity_id, $policy->branch_id, 'policy', 'POL', $on), $actorUserId);

        return DB::transaction(function () use ($policyId, $on, $actorUserId, $number): Policy {
            $policy = $this->lock($policyId, [PolicyStatus::Quote], 'issue');
            $policy->forceFill(['status' => PolicyStatus::Issued->value, 'number' => $number->number, 'issued_at' => CarbonImmutable::now()])->save();
            $this->numbers->markUsed($number->id, 'policy', $policy->id);
            $transaction = $this->record($policy, PolicyTransactionType::New, $policy->inception, $policy->gross_premium_minor, $policy->net_premium_minor, $policy->tax_minor, null, null, $actorUserId, $on);
            $this->installments->planFor($policy);
            $this->accounting->issued($policy, $transaction, $on);
            $this->audit->record('policy.issued', AuditSubject::of('policy', $policy->id), ['status' => 'quote'], ['status' => 'issued', 'number' => $policy->number], null, 'policy.issue', Actor::user($actorUserId));
            Event::dispatch(new PolicyIssued($policy->id, $transaction->id));

            return $policy;
        });
    }

    /** @param int $premiumDeltaMinor change in the charged premium under the product's tax profile (negative decreases) */
    public function endorse(string $policyId, CarbonImmutable $effectiveDate, int $premiumDeltaMinor, string $reason, string $actorUserId): Policy
    {
        $policy = Policy::query()->findOrFail($policyId);
        $this->authorize($actorUserId, 'policy.endorse', $policy);

        return DB::transaction(function () use ($policyId, $effectiveDate, $premiumDeltaMinor, $reason, $actorUserId): Policy {
            $policy = $this->lock($policyId, [PolicyStatus::Issued, PolicyStatus::Active], 'endorse');
            if ($effectiveDate->lessThan($policy->inception) || $effectiveDate->greaterThan($policy->expiry)) {
                throw new BusinessRuleViolation('ENDORSEMENT_OUTSIDE_COVER', "An endorsement must take effect between {$policy->inception->toDateString()} and {$policy->expiry->toDateString()}.");
            }
            $version = ProductVersion::query()->findOrFail($policy->product_version_id);
            if ($policy->rating_result !== null || self::isRated($version)) {
                throw new BusinessRuleViolation('PRODUCT_RATED', 'This policy is priced by its tariff, so a premium change cannot be typed in. Endorse its risk details; the change is re-rated.');
            }
            $delta = PremiumMath::splitTax(abs($premiumDeltaMinor), $this->taxRate($version, $policy->inception), (bool) $version->tax_profile['inclusive']);
            $sign = $premiumDeltaMinor < 0 ? -1 : 1;
            [$gross, $net, $tax] = [$sign * $delta['gross'], $sign * $delta['net'], $sign * $delta['tax']];

            $policy->forceFill(['version' => $policy->version + 1, 'gross_premium_minor' => $policy->gross_premium_minor + $gross,
                'net_premium_minor' => $policy->net_premium_minor + $net, 'tax_minor' => $policy->tax_minor + $tax])->save();
            $transaction = $this->record($policy, PolicyTransactionType::Endorsement, $effectiveDate, $gross, $net, $tax, $reason, null, $actorUserId);
            $gross >= 0 ? $this->installments->addIncrease($policy, $effectiveDate, $gross) : $this->installments->credit($policy, -$gross);
            $this->accounting->endorsed($policy, $transaction);
            $this->audit->record('policy.endorsed', AuditSubject::of('policy', $policy->id), null,
                ['version' => $policy->version, 'gross_delta' => $gross, 'net_delta' => $net, 'tax_delta' => $tax], $reason, 'policy.endorse', Actor::user($actorUserId));
            Event::dispatch(new PolicyEndorsed($policy->id, $transaction->id));

            return $policy;
        });
    }

    /**
     * Phase 3 design §2 step 4 (slice R7): issues the policy of an approved proposal (approved automatically or after referral) on its quotation's frozen rating.
     *
     * - The quotation basis must hold: the quotation was accepted (converted) and, unless configured otherwise, the issue date is within its validity (A-116).
     * - Credit issuance (design OPEN 4, A-117): the product version must allow credit issue, or the officer confirms the premium was received with a reference
     *   (recorded on the policy and audited).
     * - A producer must be licensed for the product's class on the issue date (Distribution design note §3), as for `issue`.
     * - One transaction: the policy (premium from the rating result: net, VAT and levies as tax, stamp duty on its own — D-37), its number, the new-business
     *   transaction, installments, POLICY_ISSUED, the proposal marked issued, its cover notes superseded, the audit row. Any failure leaves nothing behind.
     *
     * @throws BusinessRuleViolation PROPOSAL_NOT_APPROVED, QUOTATION_BASIS_INVALID, QUOTATION_EXPIRED, PREMIUM_NOT_RECEIVED, INSTALLMENTS_INVALID, RATING_RESULT_INVALID
     */
    public function issueFromProposal(string $proposalId, CarbonImmutable $on, string $actorUserId, int $installmentCount = 1, ?string $premiumReceivedReference = null): Policy
    {
        $approved = $this->proposals->approvedForIssue($proposalId);
        $this->permissions->authorize($actorUserId, 'policy.issue', AuthorizationScope::branch($approved->entityId, $approved->branchId));
        if ($installmentCount < 1 || $installmentCount > 12) {
            throw new BusinessRuleViolation('INSTALLMENTS_INVALID', 'A policy has between 1 and 12 installments.');
        }
        $quotation = DB::table('quotations')->where('id', $approved->quotationId)->first(['number', 'status', 'valid_until', 'renewal_of_policy_id']);
        if ($quotation === null || $quotation->status !== 'converted') {
            throw new BusinessRuleViolation('QUOTATION_BASIS_INVALID', "Proposal {$approved->number} has no accepted quotation to issue on.");
        }
        // ASSUMPTION: A-116 — the premium is guaranteed for the quotation's validity only.
        if ((bool) config('erp.policies.issue_within_quotation_validity', true) && $on->toDateString() > (string) $quotation->valid_until) {
            throw new BusinessRuleViolation('QUOTATION_EXPIRED', "Quotation {$quotation->number} was valid until ".CarbonImmutable::parse((string) $quotation->valid_until)->format('j M Y')
                .'. Quote the customer again before issuing.');
        }
        $version = ProductVersion::query()->findOrFail($approved->productVersionId);
        $premium = RatedPremium::of($approved->ratingResult);
        // ASSUMPTION: A-117 — design OPEN 4: without credit issue on the product version, the premium must be confirmed as received (reference recorded).
        $reference = trim((string) $premiumReceivedReference);
        if (! $version->allow_credit_issue && $reference === '') {
            throw new BusinessRuleViolation('PREMIUM_NOT_RECEIVED', 'This product is not issued on credit. Confirm the premium was received and give its reference (receipt, bank or cheque).');
        }
        if (mb_strlen($reference) > 128) {
            throw new BusinessRuleViolation('PREMIUM_REFERENCE_INVALID', 'The premium reference is at most 128 characters.');
        }
        // Slice R9: a renewal quotation's policy renews the policy it names (design previous_policy_id = renewal_of_policy_id, D-38).
        $renews = $quotation->renewal_of_policy_id === null ? null : (string) $quotation->renewal_of_policy_id;
        if ($renews !== null) {
            $this->assertRenewable($renews);
        }
        if ($approved->producerId !== null && $renews === null) {
            // Renewals are not new business (Distribution design note §3, as `issue`).
            app(LicenceRegistry::class)->assertMayWriteNewBusiness($approved->producerId, (string) DB::table('products')->where('id', $approved->productId)->value('insurance_class'), $on);
        }
        $number = $this->numbers->reserve(new DocumentNumberScope($approved->entityId, $approved->branchId, 'policy', 'POL', $on), $actorUserId);

        return DB::transaction(function () use ($approved, $version, $premium, $reference, $installmentCount, $on, $actorUserId, $number, $renews): Policy {
            $result = $approved->ratingResult;
            $previous = $renews === null ? null : $this->lock($renews, [PolicyStatus::Active, PolicyStatus::Expired], 'renew');
            $coverNoteId = DB::table('cover_notes')->where('proposal_id', $approved->proposalId)->whereIn('status', ['active', 'expired'])->orderByDesc('issued_at')->value('id');
            $policy = new Policy(['id' => (string) \Illuminate\Support\Str::uuid7()]);
            $policy->forceFill([
                'entity_id' => $approved->entityId, 'branch_id' => $approved->branchId, 'product_id' => $approved->productId, 'product_version_id' => $version->id,
                'policyholder_party_id' => $approved->customerPartyId, 'agent_id' => $approved->producerId, 'channel' => $approved->producerId === null ? 'direct' : 'agent',
                'status' => PolicyStatus::Issued->value, 'number' => $number->number, 'issued_at' => CarbonImmutable::now(),
                'inception' => $approved->inception->toDateString(), 'expiry' => $approved->inception->addMonthsNoOverflow($version->term_months)->subDay()->toDateString(),
                'currency' => $approved->currency, 'gross_premium_minor' => $premium->grossMinor(), 'net_premium_minor' => $premium->netMinor, 'tax_minor' => $premium->taxMinor,
                'stamp_duty_minor' => $premium->stampDutyMinor, 'installment_count' => $installmentCount, 'version' => 1, 'created_by' => $actorUserId,
                'quotation_id' => $approved->quotationId, 'proposal_id' => $approved->proposalId, 'cover_note_id' => $coverNoteId === null ? null : (string) $coverNoteId,
                'risk_inputs' => $result->riskInputs, 'risk_keys' => RiskKeys::for($approved->classCode, $result->riskInputs), 'rating_result' => $result->toArray(),
                'rating_plan_code' => $result->plan['code'], 'rating_plan_version' => $result->plan['version'],
                'special_terms' => $approved->manualLoadingBp === null ? [] : [['code' => 'manual_loading', 'loading_bp' => $approved->manualLoadingBp, 'reason' => (string) $approved->specialTerms,
                    'text' => (new ManualLoading($approved->manualLoadingBp, (string) $approved->specialTerms))->labelEn().': '.$approved->specialTerms]],
                'issue_basis' => $reference !== '' ? 'premium_received' : 'credit', 'premium_received_reference' => $reference === '' ? null : $reference,
                'renewal_of_policy_id' => $previous?->id,
            ])->save();
            $this->numbers->markUsed($number->id, 'policy', $policy->id);
            $transaction = $this->record($policy, PolicyTransactionType::New, $policy->inception, $policy->gross_premium_minor, $policy->net_premium_minor, $policy->tax_minor,
                null, null, $actorUserId, $on, $policy->stamp_duty_minor);
            $this->installments->planFor($policy);
            $this->accounting->issued($policy, $transaction, $on);
            $this->proposals->markIssued($approved->proposalId, $policy->id, $actorUserId);
            $superseded = $this->coverNotes->supersedeForProposal($approved->proposalId, $policy->id, $actorUserId);
            $this->audit->record('policy.issued', AuditSubject::of('policy', $policy->id), null, [
                'status' => 'issued', 'number' => $policy->number, 'proposal' => $approved->number, 'rating_plan' => "{$result->plan['code']} v{$result->plan['version']}",
                'gross_premium_minor' => $policy->gross_premium_minor, 'net_premium_minor' => $policy->net_premium_minor, 'tax_minor' => $policy->tax_minor,
                'stamp_duty_minor' => $policy->stamp_duty_minor, 'installments' => $installmentCount, 'issue_basis' => $policy->issue_basis,
                'premium_received_reference' => $policy->premium_received_reference, 'cover_notes_superseded' => $superseded,
                'renewal_of' => $previous?->number,
            ], null, 'policy.issue', Actor::user($actorUserId));
            Event::dispatch(new PolicyIssued($policy->id, $transaction->id));
            if ($previous !== null) {
                $before = $previous->status->value;
                $previous->forceFill(['status' => PolicyStatus::Renewed->value])->save();
                $this->audit->record('policy.renewed', AuditSubject::of('policy', $previous->id), ['status' => $before], ['status' => 'renewed', 'renewal_policy_id' => $policy->id,
                    'renewal_number' => $policy->number], null, 'policy.issue', Actor::user($actorUserId));
                Event::dispatch(new PolicyRenewed($previous->id, $policy->id));
            }

            return $policy;
        });
    }

    /**
     * Slice R7 (design §2 step 5): what re-rating a policy's risk with new inputs gives, without writing anything — the rating in force, the new rating and
     * the premium change. On the policy's original plan version, product version and rating date (RatingEngine::rerateWith), unless the product version
     * sets `endorsement_uses_current_tariff` (rated on the tariff in force on the effective date). A manual loading in the policy's special terms applies again.
     * ASSUMPTION: A-120.
     *
     * @param array<mixed> $riskInputs
     * @param list<string>|null $coverages optional coverages; null keeps the ones in force
     *
     * @throws BusinessRuleViolation POLICY_NOT_RATED, ENDORSEMENT_OUTSIDE_COVER and every rating failure
     */
    public function rateEndorsement(string $policyId, CarbonImmutable $effectiveDate, array $riskInputs, string $actorUserId, ?array $coverages = null): EndorsementRating
    {
        $policy = Policy::query()->findOrFail($policyId);
        $this->authorize($actorUserId, 'policy.endorse', $policy);

        return $this->endorsementRating($policy, $effectiveDate, $riskInputs, $coverages);
    }

    /**
     * Slice R7 (design §2 step 5): an endorsement changing the risk of a rated policy. The change is re-rated (see `rateEndorsement`), recorded as an endorsement
     * transaction carrying the new rating result (frozen), the policy's premium totals, version and duplicate-risk keys move, installments follow, and
     * POLICY_ENDORSED posts the change. The policy's issue rating result never changes (INVARIANT). A change of details with no premium change posts nothing.
     *
     * @param array<mixed> $riskInputs
     * @param list<string>|null $coverages
     *
     * @throws BusinessRuleViolation POLICY_NOT_RATED, REASON_REQUIRED, ENDORSEMENT_NO_CHANGE, ENDORSEMENT_OUTSIDE_COVER, INVALID_POLICY_TRANSITION and every rating failure
     */
    public function endorseRisk(string $policyId, CarbonImmutable $effectiveDate, array $riskInputs, string $reason, string $actorUserId, ?array $coverages = null): Policy
    {
        $policy = Policy::query()->findOrFail($policyId);
        $this->authorize($actorUserId, 'policy.endorse', $policy);
        if (trim($reason) === '') {
            throw new BusinessRuleViolation('REASON_REQUIRED', 'An endorsement needs a reason.');
        }

        return DB::transaction(function () use ($policyId, $effectiveDate, $riskInputs, $coverages, $reason, $actorUserId): Policy {
            $policy = $this->lock($policyId, [PolicyStatus::Issued, PolicyStatus::Active], 'endorse');
            $rating = $this->endorsementRating($policy, $effectiveDate, $riskInputs, $coverages);
            if ($rating->after->inputsHash === $rating->before->inputsHash && $rating->after->plan === $rating->before->plan) {
                throw new BusinessRuleViolation('ENDORSEMENT_NO_CHANGE', 'The risk details are the same as the policy has now. Change a detail to endorse.');
            }
            $change = $rating->change;
            $policy->forceFill(['version' => $policy->version + 1, 'gross_premium_minor' => $policy->gross_premium_minor + $change->grossMinor(),
                'net_premium_minor' => $policy->net_premium_minor + $change->netMinor, 'tax_minor' => $policy->tax_minor + $change->taxMinor,
                'stamp_duty_minor' => $policy->stamp_duty_minor + $change->stampDutyMinor,
                'risk_keys' => RiskKeys::for($rating->after->plan['class_code'], $rating->after->riskInputs)])->save();
            $transaction = $this->record($policy, PolicyTransactionType::Endorsement, $effectiveDate, $change->grossMinor(), $change->netMinor, $change->taxMinor, trim($reason), null,
                $actorUserId, null, $change->stampDutyMinor, $rating);
            if ($change->grossMinor() > 0) {
                $this->installments->addIncrease($policy, $effectiveDate, $change->grossMinor());
            } elseif ($change->grossMinor() < 0) {
                $this->installments->credit($policy, -$change->grossMinor());
            }
            if (! $rating->isEmpty()) {
                $this->accounting->endorsed($policy, $transaction);
            }
            $this->audit->record('policy.endorsed', AuditSubject::of('policy', $policy->id), ['gross_premium_minor' => $rating->before->grossPremiumMinor],
                ['version' => $policy->version, 'rating_basis' => $rating->basis, 'rating_plan' => "{$rating->after->plan['code']} v{$rating->after->plan['version']}",
                    'gross_delta' => $change->grossMinor(), 'net_delta' => $change->netMinor, 'tax_delta' => $change->taxMinor, 'stamp_duty_delta' => $change->stampDutyMinor,
                    'pro_rata' => $rating->proRata], trim($reason), 'policy.endorse', Actor::user($actorUserId));
            Event::dispatch(new PolicyEndorsed($policy->id, $transaction->id));

            return $policy;
        });
    }

    /**
     * @param array<mixed> $riskInputs
     * @param list<string>|null $coverages
     */
    private function endorsementRating(Policy $policy, CarbonImmutable $effectiveDate, array $riskInputs, ?array $coverages): EndorsementRating
    {
        $frozen = $policy->ratingResult() ?? throw new BusinessRuleViolation('POLICY_NOT_RATED', 'This policy has no rating to re-rate. Endorse it with a premium change.');
        if ($effectiveDate->lessThan($policy->inception) || $effectiveDate->greaterThan($policy->expiry)) {
            throw new BusinessRuleViolation('ENDORSEMENT_OUTSIDE_COVER', "An endorsement must take effect between {$policy->inception->toDateString()} and {$policy->expiry->toDateString()}.");
        }
        $latest = PolicyTransaction::query()->where('policy_id', $policy->id)->whereNotNull('rating_result')->orderByDesc('created_at')->orderByDesc('id')->value('rating_result');
        $before = is_array($latest) ? RatingResult::fromArray($latest) : (is_string($latest) ? RatingResult::fromArray((array) json_decode($latest, true)) : $frozen);
        $version = ProductVersion::query()->findOrFail($policy->product_version_id);
        // Flow fix X7: an issued policy's risk carries every detail its proposal needed; an endorsement cannot empty one.
        $version->riskSchema()->validate($riskInputs, RiskStage::Proposal);
        $term = null;
        foreach ($policy->special_terms ?? [] as $special) {
            if ($special['code'] === 'manual_loading' && isset($special['loading_bp'])) {
                $term = new ManualLoading($special['loading_bp'], (string) ($special['reason'] ?? $special['text']));
            }
        }
        $chosen = $coverages ?? $before->coverages;
        $after = $version->endorsement_uses_current_tariff
            ? $this->rating->rate($version, $riskInputs, $effectiveDate, $chosen, $term)
            : $this->rating->rerateWith($frozen, $riskInputs, $term, $chosen);
        // ASSUMPTION: A-119 — the full annual difference unless erp.policies.endorsement_premium is pro_rata.
        $proRata = config('erp.policies.endorsement_premium', 'full') === 'pro_rata';
        $daysInTerm = (int) $policy->inception->diffInDays($policy->expiry) + 1;
        $daysCharged = (int) $effectiveDate->diffInDays($policy->expiry) + 1;

        return new EndorsementRating($before, $after, $version->endorsement_uses_current_tariff ? EndorsementRating::CURRENT_TARIFF : EndorsementRating::ORIGINAL_PLAN,
            RatedPremium::of($after)->minus(RatedPremium::of($before), $proRata ? $daysCharged : 1, $proRata ? $daysInTerm : 1), $daysCharged, $daysInTerm, $proRata);
    }

    /**
     * Design §4.4 pro-rata cancellation effective $cancelDate (cover ends the day before). Earning catch-up for the
     * premium earned to date is posted by the premium earning module when it handles PolicyCancelled.
     */
    public function cancel(string $policyId, CarbonImmutable $cancelDate, string $reason, string $actorUserId): Policy
    {
        $policy = Policy::query()->findOrFail($policyId);
        $this->authorize($actorUserId, 'policy.cancel', $policy);
        if (trim($reason) === '') {
            throw new BusinessRuleViolation('REASON_REQUIRED', 'Cancelling a policy requires a reason.');
        }

        return DB::transaction(function () use ($policyId, $cancelDate, $reason, $actorUserId): Policy {
            $policy = $this->lock($policyId, [PolicyStatus::Issued, PolicyStatus::Active], 'cancel');
            if ($cancelDate->lessThan($policy->inception) || $cancelDate->greaterThan($policy->expiry)) {
                throw new BusinessRuleViolation('CANCELLATION_OUTSIDE_COVER', 'A cancellation must take effect during the cover period.');
            }
            $amounts = $this->cancellationAmounts($policy, $cancelDate);
            $transaction = $this->record($policy, PolicyTransactionType::Cancellation, $cancelDate, 0, 0, 0, $reason, $amounts, $actorUserId);
            if ($amounts['receivable_outstanding'] > 0) {
                $this->installments->credit($policy, $amounts['receivable_outstanding']);
            }
            $policy->forceFill(['status' => PolicyStatus::Cancelled->value, 'cancelled_at' => CarbonImmutable::now(),
                'cancel_date' => $cancelDate->toDateString(), 'cancel_reason' => $reason])->save();
            $this->accounting->cancelled($policy, $transaction, $amounts);
            $this->audit->record('policy.cancelled', AuditSubject::of('policy', $policy->id), null, $amounts, $reason, 'policy.cancel', Actor::user($actorUserId));
            Event::dispatch(new PolicyCancelled($policy->id, $transaction->id, $amounts['unearned_remaining'], $policy->net_premium_minor));

            return $policy;
        });
    }

    public function lapse(string $policyId, string $reason, string $actorUserId): Policy
    {
        return $this->simpleTransition($policyId, [PolicyStatus::Active], PolicyStatus::Lapsed, 'lapse', 'policy.cancel', $reason, $actorUserId);
    }

    /** Reinstatement starts a fresh grace period for automatic lapse (dunning, A-10). */
    public function reinstate(string $policyId, string $reason, string $actorUserId): Policy
    {
        $policy = $this->simpleTransition($policyId, [PolicyStatus::Lapsed], PolicyStatus::Active, 'reinstate', 'policy.issue', $reason, $actorUserId);
        $policy->forceFill(['reinstated_on' => app(BusinessClock::class)->today()->toDateString()])->save();

        return $policy;
    }

    /**
     * Automatic lapse for non-payment by the dunning run (spec §4 auto-lapse): the system acts, so no user permission applies; audited with the
     * reason. Returns false when the policy is no longer active.
     */
    public function lapseForNonPayment(string $policyId, string $reason): bool
    {
        return DB::transaction(function () use ($policyId, $reason): bool {
            $policy = Policy::query()->whereKey($policyId)->lockForUpdate()->firstOrFail();
            if ($policy->status !== PolicyStatus::Active) {
                return false;
            }
            $policy->forceFill(['status' => PolicyStatus::Lapsed->value])->save();
            $this->audit->record('policy.lapsed', AuditSubject::of('policy', $policy->id), ['status' => 'active'], ['status' => 'lapsed'], $reason, null, Actor::system());

            return true;
        });
    }

    /**
     * Marks the policy renewed and creates the renewal as a new quote from the day after expiry (it is issued separately). Products without a rating plan only:
     * a rated policy is renewed through its renewal quotation (slice R9, A-130).
     */
    public function renew(string $policyId, string $actorUserId): Policy
    {
        $policy = Policy::query()->findOrFail($policyId);
        if ($policy->rating_result !== null || self::isRated(ProductVersion::query()->findOrFail($policy->product_version_id))) {
            throw new BusinessRuleViolation('RENEWAL_BY_QUOTATION', 'This policy is priced by its tariff. Renew it from its renewal quotation on Renewals: the customer accepts it and the proposal issues the renewal.');
        }

        return DB::transaction(function () use ($policy, $actorUserId): Policy {
            $this->simpleTransition($policy->id, [PolicyStatus::Active, PolicyStatus::Expired], PolicyStatus::Renewed, 'renew', 'policy.create', null, $actorUserId);
            $renewal = $this->quote(new QuoteRequest($policy->entity_id, $policy->branch_id, $policy->product_id, $policy->policyholder_party_id, $policy->agent_id,
                $policy->expiry->addDay(), $policy->gross_premium_minor, $policy->currency, $policy->installment_count,
                DB::table('policy_payers')->where('policy_id', $policy->id)->exists() ? $this->installments->payers($policy) : []), $actorUserId);
            $renewal->forceFill(['renewal_of_policy_id' => $policy->id])->save();

            return $renewal;
        });
    }

    /**
     * Slice R9: an expiring policy can be renewed only while it is active or expired and not already renewed (design §5.4 active | expired ─renew─▶ renewed).
     *
     * @throws BusinessRuleViolation RENEWAL_BASE_NOT_RENEWABLE
     */
    private function assertRenewable(string $policyId): void
    {
        $previous = Policy::query()->findOrFail($policyId);
        if (! in_array($previous->status, [PolicyStatus::Active, PolicyStatus::Expired], true)) {
            throw new BusinessRuleViolation('RENEWAL_BASE_NOT_RENEWABLE', "Policy {$previous->number} is {$previous->status->value}, so it cannot be renewed.");
        }
    }

    /** Design §5.4 "inception reached":issued policies whose cover has started become active. Returns how many. */
    public function activateDue(CarbonImmutable $today): int
    {
        return Policy::query()->where('status', PolicyStatus::Issued->value)->where('inception', '<=', $today->toDateString())
            ->update(['status' => PolicyStatus::Active->value, 'updated_at' => now()]);
    }

    /** Active policies whose cover ended before $today expire. Returns how many. */
    public function expireDue(CarbonImmutable $today): int
    {
        return Policy::query()->where('status', PolicyStatus::Active->value)->where('expiry', '<', $today->toDateString())
            ->update(['status' => PolicyStatus::Expired->value, 'updated_at' => now()]);
    }

    /**
     * @return array{earned_to_date: int, unearned_remaining: int, tax_reversal: int, receivable_outstanding: int, refund_due: int}
     */
    private function cancellationAmounts(Policy $policy, CarbonImmutable $cancelDate): array
    {
        $version = ProductVersion::query()->findOrFail($policy->product_version_id);
        $earned = EarningSchedule::earnedBefore($version->earning_method, EarningLayers::of($policy), $cancelDate);
        $unearned = $policy->net_premium_minor - $earned;
        // ASSUMPTION: A-1 / D-06 — see ProductVersion::refundsTaxOnCancellation (OPEN #2).
        $taxReversal = $version->refundsTaxOnCancellation() && $policy->net_premium_minor > 0
            ? PremiumMath::prorate($policy->tax_minor, $unearned, $policy->net_premium_minor) : 0;
        $receivableCredit = min($this->installments->outstanding($policy), $unearned + $taxReversal);

        return ['earned_to_date' => $earned, 'unearned_remaining' => $unearned, 'tax_reversal' => $taxReversal,
            'receivable_outstanding' => $receivableCredit, 'refund_due' => $unearned + $taxReversal - $receivableCredit];
    }

    /**
     * @param list<PayerShare> $payers
     *
     * @throws BusinessRuleViolation PAYER_SHARES_INVALID | UNKNOWN_PAYER
     */
    private function assertPayers(array $payers): void
    {
        if ($payers === []) {
            return;
        }
        $ids = array_map(fn (PayerShare $p): string => $p->partyId, $payers);
        $positive = array_filter($payers, fn (PayerShare $p): bool => $p->shareBp > 0);
        if (count($positive) !== count($payers) || count(array_unique($ids)) !== count($ids) || array_sum(array_map(fn (PayerShare $p): int => $p->shareBp, $payers)) !== 10_000) {
            throw new BusinessRuleViolation('PAYER_SHARES_INVALID', 'Payers must be distinct parties with positive shares totalling 10000 basis points.');
        }
        $valid = array_values(array_filter($ids, fn (string $id): bool => \Illuminate\Support\Str::isUuid($id)));
        if (count($valid) !== count($ids) || DB::table('parties')->whereIn('id', $valid)->count() !== count($ids)) {
            throw new BusinessRuleViolation('UNKNOWN_PAYER', 'Every payer must be a party of this tenant.');
        }
    }

    /** @param list<PolicyStatus> $from */
    private function simpleTransition(string $policyId, array $from, PolicyStatus $to, string $action, string $permission, ?string $reason, string $actorUserId): Policy
    {
        $policy = Policy::query()->findOrFail($policyId);
        $this->authorize($actorUserId, $permission, $policy);

        return DB::transaction(function () use ($policyId, $from, $to, $action, $permission, $reason, $actorUserId): Policy {
            $policy = $this->lock($policyId, $from, $action);
            $before = $policy->status->value;
            $policy->forceFill(['status' => $to->value])->save();
            $this->audit->record("policy.{$to->value}", AuditSubject::of('policy', $policy->id), ['status' => $before], ['status' => $to->value], $reason, $permission, Actor::user($actorUserId));

            return $policy;
        });
    }

    /**
     * @param array<string, int>|null $amounts
     * @param CarbonImmutable|null $accountingDate the date the transaction's accounting event posts on (default: effective date)
     */
    private function record(Policy $policy, PolicyTransactionType $type, CarbonImmutable $effectiveDate, int $gross, int $net, int $tax, ?string $reason, ?array $amounts,
        string $actorUserId, ?CarbonImmutable $accountingDate = null, int $stampDuty = 0, ?EndorsementRating $rating = null): PolicyTransaction
    {
        return PolicyTransaction::query()->create([
            'policy_id' => $policy->id, 'type' => $type->value, 'effective_date' => $effectiveDate->toDateString(),
            'accounting_date' => ($accountingDate ?? $effectiveDate)->toDateString(),
            'premium_delta_minor' => $gross, 'net_delta_minor' => $net, 'tax_delta_minor' => $tax, 'stamp_duty_delta_minor' => $stampDuty, 'policy_version' => $policy->version,
            'reason' => $reason, 'amounts' => $amounts, 'created_by' => $actorUserId,
            'rating_result' => $rating?->after->toArray(), 'rating_basis' => $rating?->basis,
        ]);
    }

    private function taxRate(ProductVersion $version, CarbonImmutable $on): int
    {
        $taxType = $version->tax_profile['tax_type'] ?? null;

        return $taxType === null ? 0 : $this->taxRates->rateOn((string) $version->tax_profile['jurisdiction'], $taxType, $on);
    }

    private function authorize(string $actorUserId, string $permission, Policy $policy): void
    {
        $this->permissions->authorize($actorUserId, $permission, AuthorizationScope::branch($policy->entity_id, $policy->branch_id));
    }

    /** @param list<PolicyStatus> $allowed */
    private function lock(string $policyId, array $allowed, string $action): Policy
    {
        $policy = Policy::query()->whereKey($policyId)->lockForUpdate()->firstOrFail();
        $this->assertStatus($policy, $allowed, $action);

        return $policy;
    }

    /** @param list<PolicyStatus> $allowed */
    private function assertStatus(Policy $policy, array $allowed, string $action): void
    {
        if (! in_array($policy->status, $allowed, true)) {
            throw new BusinessRuleViolation('INVALID_POLICY_TRANSITION', "A {$policy->status->value} policy cannot {$action}.");
        }
    }
}
