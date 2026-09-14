<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Quotation\Application;

use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
use App\Modules\Insurance\Product\Domain\Risk\RiskInputsInvalid;
use App\Modules\Insurance\Quotation\Domain\Enums\QuotationStatus;
use App\Modules\Insurance\Quotation\Domain\Models\Quotation;
use App\Modules\Insurance\Rating\Application\RatingEngine;
use App\Modules\Insurance\Rating\Domain\RatingFailed;
use App\Modules\Insurance\Rating\Domain\RatingResult;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 3 design §2 step 1 "Quote: pick product → capture risk_schema fields → live rating → save/print quotation (valid 15 days, config)" (slice R4).
 *
 * - `rate` rates without saving anything (the workbench's live premium).
 * - `saveDraft` creates or changes a draft and re-rates it when the inputs are complete (an incomplete draft keeps no rating).
 * - `issue` re-rates, allocates the number (document type `quotation`, branch-coded like policies), freezes the rating result and sets the validity
 *   (erp.quotations.valid_days, ASSUMPTION A-80). The database refuses any later change to an issued quotation's terms (`QUOTATION_FROZEN`).
 * - `decline` (draft or issued, reason required), `expireDue` (nightly job and on read), `accept` (R5: an issued, unexpired quotation becomes a proposal).
 * - The producer's eligibility (licence for the product's class, distribution design §3) is recorded, never blocking (A-81).
 *
 * Permission quotation.create on the quotation's branch (A-83).
 */
final class QuotationService
{
    public const PERMISSION = 'quotation.create';
    /** Slice R9: offering a renewal quotation from the expiry register (A-126). */
    public const RENEWAL_PERMISSION = 'renewal.manage';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ProductCatalogue $products,
        private readonly RatingEngine $engine,
        private readonly DocumentNumberer $numbers,
        private readonly Audit $audit,
    ) {}

    /**
     * Live rating: the premium for the terms on the proposed cover start, nothing written.
     *
     * @throws RiskInputsInvalid per field
     * @throws RatingFailed
     * @throws BusinessRuleViolation PRODUCT_VERSION_NOT_EFFECTIVE, BRANCH_UNKNOWN, PRODUCT_UNKNOWN
     */
    public function rate(QuotationTerms $terms, string $actorUserId): RatingResult
    {
        $this->authorizeBranch($actorUserId, $terms->branchId);
        $version = $this->version($terms);

        return $this->engine->rate($version, $terms->riskInputs, $terms->inception, $terms->coverages);
    }

    /** @throws BusinessRuleViolation QUOTATION_NOT_DRAFT, PRODUCT_NOT_RATED, CUSTOMER_UNKNOWN, PRODUCER_UNKNOWN */
    public function saveDraft(QuotationTerms $terms, ?string $quotationId, string $actorUserId): Quotation
    {
        $entityId = $this->authorizeBranch($actorUserId, $terms->branchId);
        $version = $this->version($terms);
        $this->assertParties($terms);
        $inputs = self::scalarInputs($version, $terms->riskInputs);
        $result = null;
        try {
            $result = $this->engine->rate($version, $inputs, $terms->inception, $terms->coverages);
        } catch (RiskInputsInvalid|RatingFailed) {
            // An incomplete draft is saved without a rating; the workbench shows why it cannot be rated yet.
        }
        $eligibility = ProducerEligibility::check($terms->producerId, $terms->productId, app(BusinessClock::class)->today());
        $currency = (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency');

        return DB::transaction(function () use ($terms, $quotationId, $actorUserId, $entityId, $version, $inputs, $result, $eligibility, $currency): Quotation {
            $before = null;
            if ($quotationId !== null) {
                $quotation = $this->lock($quotationId, [QuotationStatus::Draft]);
                $this->authorizeBranch($actorUserId, $quotation->branch_id);
                $before = self::snapshot($quotation);
            } else {
                $quotation = new Quotation(['id' => (string) Str::uuid7(), 'status' => QuotationStatus::Draft->value, 'created_by' => $actorUserId]);
            }
            $quotation->forceFill([
                'entity_id' => $entityId, 'branch_id' => $terms->branchId, 'product_id' => $terms->productId, 'product_version_id' => $version->id, 'class_code' => $version->class_code,
                'customer_party_id' => $terms->customerPartyId, 'producer_id' => $terms->producerId, 'inception' => $terms->inception->toDateString(),
                'risk_inputs' => $inputs, 'coverages' => array_values(array_unique($terms->coverages)), 'risk_keys' => RiskKeys::for($version->class_code, $result->riskInputs ?? $inputs),
                'currency' => $currency, 'updated_by' => $actorUserId, ...self::ratingColumns($result, $inputs),
                'producer_eligible' => $eligibility->eligible, 'producer_eligibility_reason' => $eligibility->reason, 'producer_eligibility_note' => $eligibility->note,
            ])->save();
            $this->audit->record($before === null ? 'quotation.created' : 'quotation.saved', AuditSubject::of('quotation', $quotation->id), $before, self::snapshot($quotation),
                null, self::PERMISSION, Actor::user($actorUserId));

            return $quotation;
        });
    }

    /**
     * Issues a draft: re-rated on the day, numbered, rating frozen, valid for erp.quotations.valid_days days including the issue day.
     *
     * @throws BusinessRuleViolation QUOTATION_NOT_DRAFT, QUOTATION_CUSTOMER_REQUIRED, QUOTATION_INCEPTION_IN_PAST
     * @throws RiskInputsInvalid
     * @throws RatingFailed
     */
    public function issue(string $quotationId, CarbonImmutable $on, string $actorUserId): Quotation
    {
        $quotation = Quotation::query()->findOrFail($quotationId);
        $this->authorizeBranch($actorUserId, $quotation->branch_id);
        self::assertStatus($quotation, [QuotationStatus::Draft], 'issue');
        if ($quotation->customer_party_id === null) {
            // ASSUMPTION: A-82 — an issued quotation names its customer (it is printed and becomes a proposal); a price for a walk-in stays a draft.
            throw new BusinessRuleViolation('QUOTATION_CUSTOMER_REQUIRED', 'Choose the customer before issuing the quotation.');
        }
        if ($quotation->inception->lessThan($on->startOfDay())) {
            throw new BusinessRuleViolation('QUOTATION_INCEPTION_IN_PAST', "Cover cannot start before the quotation is issued ({$on->toDateString()}).");
        }
        $version = $this->products->versionOn($quotation->product_id, $quotation->inception);
        $result = $this->engine->rate($version, $quotation->risk_inputs, $quotation->inception, $quotation->coverages);
        $eligibility = ProducerEligibility::check($quotation->producer_id, $quotation->product_id, $on);
        $number = $this->numbers->reserve(new DocumentNumberScope($quotation->entity_id, $quotation->branch_id, 'quotation', 'QUO', $on), $actorUserId);

        return DB::transaction(function () use ($quotationId, $on, $actorUserId, $version, $result, $eligibility, $number): Quotation {
            $quotation = $this->lock($quotationId, [QuotationStatus::Draft]);
            $before = self::snapshot($quotation);
            $quotation->forceFill([
                'status' => QuotationStatus::Issued->value, 'number' => $number->number, 'product_version_id' => $version->id, 'class_code' => $version->class_code,
                'risk_inputs' => $result->riskInputs, 'risk_keys' => RiskKeys::for($version->class_code, $result->riskInputs), ...self::ratingColumns($result, $result->riskInputs),
                'valid_until' => $on->addDays(self::validDays() - 1)->toDateString(), 'issued_by' => $actorUserId, 'issued_at' => CarbonImmutable::now(),
                'producer_eligible' => $eligibility->eligible, 'producer_eligibility_reason' => $eligibility->reason, 'producer_eligibility_note' => $eligibility->note,
                'updated_by' => $actorUserId,
            ])->save();
            $this->numbers->markUsed($number->id, 'quotation', $quotation->id);
            $this->audit->record('quotation.issued', AuditSubject::of('quotation', $quotation->id), $before, self::snapshot($quotation), null, self::PERMISSION, Actor::user($actorUserId));

            return $quotation;
        });
    }

    /** @throws BusinessRuleViolation REASON_REQUIRED, QUOTATION_NOT_OPEN */
    public function decline(string $quotationId, string $reason, string $actorUserId): Quotation
    {
        $quotation = Quotation::query()->findOrFail($quotationId);
        $this->authorizeBranch($actorUserId, $quotation->branch_id);
        if (trim($reason) === '') {
            throw new BusinessRuleViolation('REASON_REQUIRED', 'Declining a quotation needs a reason.');
        }

        return DB::transaction(function () use ($quotationId, $reason, $actorUserId): Quotation {
            $quotation = $this->lock($quotationId, [QuotationStatus::Draft, QuotationStatus::Issued]);
            $before = $quotation->status->value;
            $quotation->forceFill(['status' => QuotationStatus::Declined->value, 'decline_reason' => trim($reason), 'declined_by' => $actorUserId,
                'declined_at' => CarbonImmutable::now(), 'updated_by' => $actorUserId])->save();
            $this->audit->record('quotation.declined', AuditSubject::of('quotation', $quotation->id), ['status' => $before], ['status' => 'declined'], trim($reason),
                self::PERMISSION, Actor::user($actorUserId));

            return $quotation;
        });
    }

    /** Issued quotations whose last valid day is before $today expire (nightly job, and before quotations are read). Returns how many. */
    public function expireDue(CarbonImmutable $today): int
    {
        $due = Quotation::query()->where('status', QuotationStatus::Issued->value)->where('valid_until', '<', $today->toDateString())->pluck('id');
        $expired = 0;
        foreach ($due as $id) {
            $expired += DB::transaction(function () use ($id, $today): int {
                $quotation = Quotation::query()->whereKey($id)->lockForUpdate()->first();
                $validUntil = $quotation?->valid_until;
                if ($quotation === null || $quotation->status !== QuotationStatus::Issued || $validUntil === null || ! $validUntil->lessThan($today)) {
                    return 0;
                }
                $quotation->forceFill(['status' => QuotationStatus::Expired->value, 'expired_at' => CarbonImmutable::now()])->save();
                $this->audit->record('quotation.expired', AuditSubject::of('quotation', $quotation->id), ['status' => 'issued'], ['status' => 'expired', 'valid_until' => $validUntil->toDateString()],
                    null, null, Actor::system());

                return 1;
            });
        }

        return $expired;
    }

    /**
     * Slice R5: the customer accepts an issued quotation within its validity; it becomes `converted`. Must run inside the transaction that creates
     * the proposal. Returns the locked quotation.
     *
     * @throws BusinessRuleViolation QUOTATION_NOT_ISSUED, QUOTATION_EXPIRED
     */
    public function accept(string $quotationId, CarbonImmutable $on, string $actorUserId): Quotation
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('QuotationService::accept must run inside the proposal transaction.');
        }
        $quotation = Quotation::query()->whereKey($quotationId)->lockForUpdate()->firstOrFail();
        $this->authorizeBranch($actorUserId, $quotation->branch_id);
        $validUntil = $quotation->valid_until;
        if ($quotation->status === QuotationStatus::Issued && $validUntil !== null && $validUntil->lessThan($on->startOfDay())) {
            throw new BusinessRuleViolation('QUOTATION_EXPIRED', "Quotation {$quotation->number} was valid until {$validUntil->toDateString()}; issue a new quotation.");
        }
        if ($quotation->status !== QuotationStatus::Issued) {
            throw new BusinessRuleViolation('QUOTATION_NOT_ISSUED', "A {$quotation->status->value} quotation cannot become a proposal; only an issued quotation can.");
        }
        $quotation->forceFill(['status' => QuotationStatus::Converted->value, 'converted_at' => CarbonImmutable::now(), 'updated_by' => $actorUserId])->save();
        $this->audit->record('quotation.converted', AuditSubject::of('quotation', $quotation->id), ['status' => 'issued'], ['status' => 'converted'], null, self::PERMISSION, Actor::user($actorUserId));

        return $quotation;
    }

    /**
     * Slice R9 (design §4 "renewal quotation auto-created at T-45 by re-rating with the current tariff"): an issued quotation for an expiring policy, rated on the
     * product version and tariff in force on the renewal's cover start, linked to the policy it renews (`renewal_of_policy_id`, D-40) and valid until the
     * renew-by date (A-127). Offered by the nightly renewal run (no actor: created by the system) or by someone holding renewal.manage on the policy's branch.
     * The number, freeze and audit trail are those of `issue`; at most one open renewal quotation per policy.
     *
     * @throws BusinessRuleViolation PRODUCT_NOT_RATED, QUOTATION_INCEPTION_IN_PAST, RENEWAL_QUOTATION_OPEN
     * @throws RiskInputsInvalid
     * @throws RatingFailed
     */
    public function offerRenewal(RenewalQuotationTerms $terms, CarbonImmutable $on, ?string $actorUserId): Quotation
    {
        $entityId = Str::isUuid($terms->branchId) ? DB::table('branches')->where('id', $terms->branchId)->value('entity_id') : null;
        if (! is_string($entityId)) {
            throw new BusinessRuleViolation('BRANCH_UNKNOWN', 'Choose a branch of this company.');
        }
        if ($actorUserId !== null) {
            $this->permissions->authorize($actorUserId, self::RENEWAL_PERMISSION, AuthorizationScope::branch($entityId, $terms->branchId));
        }
        if ($terms->inception->lessThan($on->startOfDay())) {
            throw new BusinessRuleViolation('QUOTATION_INCEPTION_IN_PAST', "The policy expired before {$on->toDateString()}; a renewal quotation cannot start cover in the past.");
        }
        $policyStatus = DB::table('policies')->where('id', $terms->renewalOfPolicyId)->value('status');
        if (! in_array($policyStatus, ['issued', 'active', 'expired'], true)) {
            throw new BusinessRuleViolation('RENEWAL_BASE_NOT_RENEWABLE', 'Only an issued, active or expired policy that is not yet renewed can be offered a renewal.');
        }
        if (self::openRenewal($terms->renewalOfPolicyId) !== null) {
            throw new BusinessRuleViolation('RENEWAL_QUOTATION_OPEN', 'This policy already has an open renewal quotation.');
        }
        $version = $this->products->versionOn($terms->productId, $terms->inception);
        if ($version->class_code === null) {
            throw new BusinessRuleViolation('PRODUCT_NOT_RATED', 'This product has no product class and rating plan, so its renewal cannot be quoted.');
        }
        $inputs = self::scalarInputs($version, $terms->riskInputs);
        $result = $this->engine->rate($version, $inputs, $terms->inception, $terms->coverages);
        $currency = (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency');
        $number = $this->numbers->reserve(new DocumentNumberScope($entityId, $terms->branchId, 'quotation', 'QUO', $on), $actorUserId);
        $actor = $actorUserId === null ? Actor::system() : Actor::user($actorUserId);
        $permission = $actorUserId === null ? null : self::RENEWAL_PERMISSION;

        return DB::transaction(function () use ($terms, $on, $actorUserId, $entityId, $version, $result, $currency, $number, $actor, $permission): Quotation {
            $quotation = new Quotation(['id' => (string) Str::uuid7()]);
            $quotation->forceFill([
                'entity_id' => $entityId, 'branch_id' => $terms->branchId, 'product_id' => $terms->productId, 'product_version_id' => $version->id, 'class_code' => $version->class_code,
                'customer_party_id' => $terms->customerPartyId, 'producer_id' => $terms->producerId, 'inception' => $terms->inception->toDateString(),
                'risk_inputs' => $result->riskInputs, 'coverages' => array_values(array_unique($terms->coverages)), 'risk_keys' => RiskKeys::for($version->class_code, $result->riskInputs),
                'currency' => $currency, ...self::ratingColumns($result, $result->riskInputs), 'status' => QuotationStatus::Issued->value, 'number' => $number->number,
                'valid_until' => ($terms->validUntil->lessThan($on) ? $on : $terms->validUntil)->toDateString(), 'issued_by' => $actorUserId, 'issued_at' => CarbonImmutable::now(),
                // ASSUMPTION: A-133 — a renewal is not new business, so the producer's licence is not checked for it.
                'producer_eligible' => null, 'producer_eligibility_reason' => 'RENEWAL', 'producer_eligibility_note' => 'A renewal is not new business; the licence check for new business does not apply.',
                'renewal_of_policy_id' => $terms->renewalOfPolicyId, 'created_by' => $actorUserId, 'updated_by' => $actorUserId,
            ])->save();
            $this->numbers->markUsed($number->id, 'quotation', $quotation->id);
            $policyNumber = DB::table('policies')->where('id', $terms->renewalOfPolicyId)->value('number');
            $this->audit->record('quotation.created', AuditSubject::of('quotation', $quotation->id), null, [...self::snapshot($quotation), 'renewal_of' => $policyNumber], null, $permission, $actor);
            $this->audit->record('quotation.issued', AuditSubject::of('quotation', $quotation->id), null, [...self::snapshot($quotation), 'renewal_of' => $policyNumber], null, $permission, $actor);

            return $quotation;
        });
    }

    /**
     * Slice R9: declines the open renewal quotation of a policy that will not be renewed (a person recorded why, or the policy was cancelled). The caller has
     * checked renewal.manage on the policy's branch; null actor = the system. Does nothing when the quotation is no longer open.
     */
    public function declineRenewal(string $quotationId, string $reason, ?string $actorUserId): void
    {
        DB::transaction(function () use ($quotationId, $reason, $actorUserId): void {
            $quotation = Quotation::query()->whereKey($quotationId)->lockForUpdate()->first();
            if ($quotation === null || $quotation->renewal_of_policy_id === null || ! in_array($quotation->status, [QuotationStatus::Draft, QuotationStatus::Issued], true)) {
                return;
            }
            $before = $quotation->status->value;
            $quotation->forceFill(['status' => QuotationStatus::Declined->value, 'decline_reason' => trim($reason), 'declined_by' => $actorUserId,
                'declined_at' => CarbonImmutable::now(), 'updated_by' => $actorUserId])->save();
            $this->audit->record('quotation.declined', AuditSubject::of('quotation', $quotation->id), ['status' => $before], ['status' => 'declined'], trim($reason),
                $actorUserId === null ? null : self::RENEWAL_PERMISSION, $actorUserId === null ? Actor::system() : Actor::user($actorUserId));
        });
    }

    /** Slice R9: the open (draft or issued) renewal quotation of a policy, if any. */
    public static function openRenewal(string $policyId): ?string
    {
        $id = DB::table('quotations')->where('renewal_of_policy_id', $policyId)->whereIn('status', [QuotationStatus::Draft->value, QuotationStatus::Issued->value])->value('id');

        return $id === null ? null : (string) $id;
    }

    public static function validDays(): int
    {
        return max(1, (int) config('erp.quotations.valid_days', 15));
    }

    /** @return string the branch's entity */
    private function authorizeBranch(string $actorUserId, string $branchId): string
    {
        $entityId = Str::isUuid($branchId) ? DB::table('branches')->where('id', $branchId)->value('entity_id') : null;
        if (! is_string($entityId)) {
            throw new BusinessRuleViolation('BRANCH_UNKNOWN', 'Choose a branch of this company.');
        }
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::branch($entityId, $branchId));

        return $entityId;
    }

    private function version(QuotationTerms $terms): ProductVersion
    {
        if (! Str::isUuid($terms->productId) || ! DB::table('products')->where('id', $terms->productId)->exists()) {
            throw new BusinessRuleViolation('PRODUCT_UNKNOWN', 'Choose a product of this company.');
        }
        $version = $this->products->versionOn($terms->productId, $terms->inception);
        if ($version->class_code === null) {
            throw new BusinessRuleViolation('PRODUCT_NOT_RATED', 'This product has no product class and rating plan, so it cannot be quoted here.');
        }

        return $version;
    }

    private function assertParties(QuotationTerms $terms): void
    {
        if ($terms->customerPartyId !== null && (! Str::isUuid($terms->customerPartyId) || ! DB::table('parties')->where('id', $terms->customerPartyId)->exists())) {
            throw new BusinessRuleViolation('CUSTOMER_UNKNOWN', 'Choose a customer of this company.');
        }
        if ($terms->producerId !== null && (! Str::isUuid($terms->producerId) || ! DB::table('producers')->where('id', $terms->producerId)->exists())) {
            throw new BusinessRuleViolation('PRODUCER_UNKNOWN', 'Choose a producer of this company.');
        }
    }

    /**
     * The inputs kept on a draft: only the version's risk fields, scalar values only.
     *
     * @param array<string, mixed> $inputs
     * @return array<string, int|string|bool|null>
     */
    private static function scalarInputs(ProductVersion $version, array $inputs): array
    {
        $kept = [];
        foreach ($version->riskSchema()->fields as $field) {
            $value = $inputs[$field->key] ?? null;
            $kept[$field->key] = is_int($value) || is_string($value) || is_bool($value) ? $value : null;
        }

        return $kept;
    }

    /**
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    private static function ratingColumns(?RatingResult $result, array $inputs): array
    {
        $sumInsured = $result?->riskInputs['sum_insured'] ?? $inputs['sum_insured'] ?? null;
        $sumInsured = is_int($sumInsured) ? $sumInsured : (is_string($sumInsured) && preg_match('/^\d{1,18}$/', $sumInsured) === 1 ? (int) $sumInsured : null);

        return [
            'rating_result' => $result?->toArray(), 'rating_plan_code' => $result?->plan['code'], 'rating_plan_version' => $result?->plan['version'],
            'sum_insured_minor' => $sumInsured, 'net_premium_minor' => $result?->netPremiumMinor, 'duties_minor' => $result?->dutiesTotalMinor, 'gross_premium_minor' => $result?->grossPremiumMinor,
        ];
    }

    /** @return array<string, mixed> */
    private static function snapshot(Quotation $quotation): array
    {
        return ['status' => $quotation->status->value, 'number' => $quotation->number,
            'product_version_id' => $quotation->product_version_id, 'customer_party_id' => $quotation->customer_party_id, 'producer_id' => $quotation->producer_id,
            'inception' => $quotation->inception->toDateString(),
            'sum_insured_minor' => $quotation->sum_insured_minor, 'gross_premium_minor' => $quotation->gross_premium_minor,
            'rating_plan' => $quotation->rating_plan_code === null ? null : "{$quotation->rating_plan_code} v{$quotation->rating_plan_version}"];
    }

    /** @param list<QuotationStatus> $allowed */
    private function lock(string $quotationId, array $allowed): Quotation
    {
        $quotation = Quotation::query()->whereKey($quotationId)->lockForUpdate()->firstOrFail();
        self::assertStatus($quotation, $allowed, 'change');

        return $quotation;
    }

    /** @param list<QuotationStatus> $allowed */
    private static function assertStatus(Quotation $quotation, array $allowed, string $action): void
    {
        if (! in_array($quotation->status, $allowed, true)) {
            $code = $allowed === [QuotationStatus::Draft] ? 'QUOTATION_NOT_DRAFT' : 'QUOTATION_NOT_OPEN';
            throw new BusinessRuleViolation($code, "A {$quotation->status->value} quotation cannot {$action}.");
        }
    }
}
