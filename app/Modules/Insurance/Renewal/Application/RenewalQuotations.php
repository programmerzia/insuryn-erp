<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Renewal\Application;

use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\RenewalQuotationTerms;
use App\Modules\Insurance\Quotation\Domain\Models\Quotation;
use App\Modules\Insurance\Renewal\Domain\ExpiryRegisterStatus;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design §4 "Renewal quotation auto-created at T-45 (config) by re-rating with the current tariff and prior-claims data (NCB); status renewal_offered" (slice R9).
 *
 * - `offerDue(today)`: for every open register row of a rated policy within erp.renewals.quote_days_before days of expiry that has not been offered, a renewal
 *   quotation through QuotationService::offerRenewal by the system. A policy that cannot be quoted (no tariff in force on the renewal date, risk details the
 *   current schema refuses…) keeps its row `upcoming` with the problem shown on the queue, and is tried again the next night.
 * - `offerNow(entry, actor)`: the same from the expiry register queue, by someone holding renewal.manage on the branch, at any time before expiry.
 *
 * ASSUMPTION: A-127 / A-129 — cover from the day after expiry; the risk in force (the latest re-rated endorsement, else the issue rating) with its optional
 * coverages; claim-free years moved by NoClaimBonus; the product version and tariff in force on the renewal's cover start; valid until the expiry date.
 * The expiring policy's manual loading (special terms) is not carried over: underwriting applies special terms again at referral.
 * ASSUMPTION: A-130 — products without a rating plan get no automatic quotation (renewed from the policy page with a typed premium, Phase 1).
 */
final class RenewalQuotations
{
    public function __construct(
        private readonly QuotationService $quotations,
        private readonly ProductCatalogue $products,
        private readonly PermissionChecker $permissions,
    ) {}

    public static function quoteDaysBefore(): int
    {
        return max(0, (int) config('erp.renewals.quote_days_before', 45));
    }

    /** Returns how many renewal quotations were offered. */
    public function offerDue(CarbonImmutable $today): int
    {
        $rows = DB::table('expiry_register')->where('status', ExpiryRegisterStatus::Upcoming->value)->where('rated', true)
            ->whereBetween('expiry', [$today->toDateString(), $today->addDays(self::quoteDaysBefore())->toDateString()])->orderBy('expiry')->pluck('id');
        $offered = 0;
        foreach ($rows as $entryId) {
            try {
                $this->offer((string) $entryId, $today, null);
                $offered++;
            } catch (BusinessRuleViolation $problem) {
                DB::table('expiry_register')->where('id', $entryId)->update(['quote_problem_code' => $problem->reasonCode, 'quote_problem' => mb_substr($problem->getMessage(), 0, 1000),
                    'updated_at' => CarbonImmutable::now()]);
            }
        }

        return $offered;
    }

    /**
     * @throws BusinessRuleViolation RENEWAL_UNKNOWN, RENEWAL_ALREADY_CLOSED, RENEWAL_NOT_RATED, RENEWAL_QUOTATION_OPEN and every quotation and rating refusal
     */
    public function offerNow(string $entryId, string $actorUserId): Quotation
    {
        $entry = DB::table('expiry_register')->where('id', $entryId)->first(['entity_id', 'branch_id']);
        if ($entry === null) {
            throw new BusinessRuleViolation('RENEWAL_UNKNOWN', 'That policy is not in the expiry register.');
        }
        $this->permissions->authorize($actorUserId, ExpiryRegister::PERMISSION, AuthorizationScope::branch((string) $entry->entity_id, (string) $entry->branch_id));

        return $this->offer($entryId, CarbonImmutable::today(), $actorUserId);
    }

    private function offer(string $entryId, CarbonImmutable $today, ?string $actorUserId): Quotation
    {
        $entry = DB::table('expiry_register')->where('id', $entryId)->first();
        if (! $entry instanceof \stdClass) {
            throw new BusinessRuleViolation('RENEWAL_UNKNOWN', 'That policy is not in the expiry register.');
        }
        if (! in_array($entry->status, ExpiryRegisterStatus::open(), true)) {
            throw new BusinessRuleViolation('RENEWAL_ALREADY_CLOSED', 'This policy was already renewed or closed in the expiry register.');
        }
        if (! (bool) $entry->rated) {
            throw new BusinessRuleViolation('RENEWAL_NOT_RATED', 'This product has no rating plan. Renew the policy from its page with a renewal quote.');
        }
        $policy = DB::table('policies')->where('id', $entry->policy_id)->first(['id', 'branch_id', 'product_id', 'policyholder_party_id', 'agent_id', 'inception', 'expiry', 'risk_inputs', 'rating_result']);
        if ($policy === null) {
            throw new BusinessRuleViolation('RENEWAL_UNKNOWN', 'That policy does not exist.');
        }
        $inception = CarbonImmutable::parse((string) $policy->expiry)->addDay();
        [$inputs, $coverages] = self::riskInForce($policy);
        $version = $this->products->versionOn((string) $policy->product_id, $inception);
        $ncb = NoClaimBonus::apply($version->riskSchema(), $inputs, (string) $policy->id, (string) $policy->inception, (string) $policy->expiry);

        $quotation = $this->quotations->offerRenewal(new RenewalQuotationTerms((string) $policy->id, (string) $policy->branch_id, (string) $policy->product_id,
            (string) $policy->policyholder_party_id, $policy->agent_id === null ? null : (string) $policy->agent_id, $inception, $ncb['inputs'], $coverages,
            CarbonImmutable::parse((string) $policy->expiry)), $today, $actorUserId);
        ExpiryRegister::offered($entryId, $quotation->id);

        return $quotation;
    }

    /**
     * The risk in force on a policy: the latest re-rated endorsement's rating, else the frozen issue rating, else the policy's risk inputs.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function riskInForce(\stdClass $policy): array
    {
        $latest = DB::table('policy_transactions')->where('policy_id', $policy->id)->whereNotNull('rating_result')->orderByDesc('created_at')->orderByDesc('id')->value('rating_result');
        foreach ([$latest, $policy->rating_result] as $json) {
            $rating = is_string($json) ? json_decode($json, true) : null;
            if (is_array($rating) && is_array($rating['risk_inputs'] ?? null)) {
                /** @var array<string, mixed> $inputs */
                $inputs = $rating['risk_inputs'];
                $coverages = array_values(array_filter(is_array($rating['coverages'] ?? null) ? $rating['coverages'] : [], 'is_string'));

                return [$inputs, $coverages];
            }
        }
        $inputs = is_string($policy->risk_inputs) ? json_decode($policy->risk_inputs, true) : null;

        return [is_array($inputs) ? $inputs : [], []];
    }
}
