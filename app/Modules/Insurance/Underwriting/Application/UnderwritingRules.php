<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Underwriting\Application;

use App\Modules\Insurance\Quotation\Application\ProducerEligibility;
use App\Modules\Insurance\Underwriting\Domain\Enums\KycStatus;
use App\Modules\Insurance\Underwriting\Domain\Models\Proposal;
use App\Modules\Insurance\Underwriting\Domain\ReferralReason;
use App\Modules\Platform\Money\MinorUnits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 design §2 step 2 "underwriting rules: auto_approve unless referral condition (sum insured > limit, risk flags, producer ineligible)" and §5
 * (duplicate risk check), slice R5. A proposal is approved automatically only when none of these holds; otherwise every reason that holds is listed:
 *
 * 1. SUM_INSURED_ABOVE_LIMIT — the sum insured is above the submitter's underwriting limit for the class (the highest limit of their roles), or they have none (A-85).
 * 2. PRODUCER_INELIGIBLE — the producer may not write the product's class on the day (licence, status; A-81).
 * 3. RISK_FLAG — a configured risk flag holds (erp.underwriting.risk_flags, A-89): `above` / `below` a number, `age_above` years since a year field, `in` a list.
 * 4. DUPLICATE_RISK — the same risk (A-88 keys) is on another issued quotation, an open or approved proposal, or a proposal whose policy is in force.
 * 5. KYC_NOT_VERIFIED — KYC is pending (verified or waived with a reason passes, A-92).
 */
final class UnderwritingRules
{
    public function __construct(private readonly UnderwritingLimits $limits) {}

    /** @return list<ReferralReason> */
    public function evaluate(Proposal $proposal, string $submitterId, CarbonImmutable $on): array
    {
        $reasons = [];
        $limit = $this->limits->limitFor($submitterId, $proposal->class_code, $on);
        $sumInsured = MinorUnits::format($proposal->sum_insured_minor, $proposal->currency);
        if ($limit === null) {
            $reasons[] = new ReferralReason(ReferralReason::SUM_INSURED_ABOVE_LIMIT, "The submitter has no underwriting limit for {$proposal->class_code}; sum insured {$sumInsured}.");
        } elseif ($proposal->sum_insured_minor > $limit) {
            $reasons[] = new ReferralReason(ReferralReason::SUM_INSURED_ABOVE_LIMIT,
                "Sum insured {$sumInsured} is above the submitter's limit of ".MinorUnits::format($limit, $proposal->currency)." for {$proposal->class_code}.");
        }
        $eligibility = ProducerEligibility::check($proposal->producer_id, $proposal->product_id, $on);
        if ($eligibility->eligible === false) {
            $reasons[] = new ReferralReason(ReferralReason::PRODUCER_INELIGIBLE, (string) $eligibility->note);
        }
        foreach (self::riskFlags($proposal->class_code, $proposal->risk_inputs, $on) as $flag) {
            $reasons[] = new ReferralReason(ReferralReason::RISK_FLAG, $flag);
        }
        $duplicates = self::duplicates($proposal->risk_keys, $proposal->quotation_id, $proposal->id, $on);
        if ($duplicates !== []) {
            $reasons[] = new ReferralReason(ReferralReason::DUPLICATE_RISK, 'The same risk is on '.implode(', ', $duplicates).'.');
        }
        if ($proposal->kyc_status === KycStatus::Pending) {
            $reasons[] = new ReferralReason(ReferralReason::KYC_NOT_VERIFIED, "The customer's identity has not been verified.");
        }

        return $reasons;
    }

    /**
     * The configured risk flags that hold for the inputs, as sentences.
     *
     * @param array<string, mixed> $inputs
     * @return list<string>
     */
    public static function riskFlags(string $classCode, array $inputs, CarbonImmutable $on): array
    {
        /** @var array<string, list<array{field: string, rule: string, value: int|list<string>, label: string}>> $config */
        $config = (array) config('erp.underwriting.risk_flags', []);
        $flags = [];
        foreach ($config[$classCode] ?? [] as $flag) {
            $value = $inputs[$flag['field']] ?? null;
            $holds = match ($flag['rule']) {
                'above' => is_int($value) && is_int($flag['value']) && $value > $flag['value'],
                'below' => is_int($value) && is_int($flag['value']) && $value < $flag['value'],
                'age_above' => is_int($value) && is_int($flag['value']) && $on->year - $value > $flag['value'],
                'in' => (is_string($value) || is_int($value)) && is_array($flag['value']) && in_array((string) $value, $flag['value'], true),
                default => false,
            };
            if ($holds) {
                $flags[] = $flag['label'].'.';
            }
        }

        return $flags;
    }

    /**
     * Numbers of the other documents holding one of the risk keys: issued quotations (not this proposal's), draft, submitted or approved proposals, and policies
     * issued or active and not yet expired (slice R7: a policy carries the keys of its current risk — set at issue, moved by re-rated endorsements — whether it
     * came from a proposal or not; its own proposal is not a duplicate of it).
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public static function duplicates(array $keys, ?string $quotationId, ?string $proposalId, CarbonImmutable $on): array
    {
        if ($keys === []) {
            return [];
        }
        $literal = '{'.implode(',', array_map(fn (string $k): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $k).'"', $keys)).'}';
        $quotations = DB::table('quotations')->where('status', 'issued')->when($quotationId !== null, fn ($q) => $q->where('id', '<>', $quotationId))
            ->whereRaw('jsonb_exists_any(risk_keys, ?::text[])', [$literal])->orderBy('number')->pluck('number');
        $proposals = DB::table('proposals')->when($proposalId !== null, fn ($q) => $q->where('id', '<>', $proposalId))->whereIn('status', ['draft', 'submitted', 'approved'])
            ->whereRaw('jsonb_exists_any(risk_keys, ?::text[])', [$literal])->orderBy('number')->pluck('number');
        $policies = DB::table('policies')->whereIn('status', ['issued', 'active'])->where('expiry', '>=', $on->toDateString())->whereNotNull('number')
            ->when($proposalId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('proposal_id')->orWhere('proposal_id', '<>', $proposalId)))
            ->whereRaw('jsonb_exists_any(risk_keys, ?::text[])', [$literal])->orderBy('number')->pluck('number');

        return array_values(array_unique(array_map(fn (mixed $n): string => (string) $n, [...$quotations->all(), ...$proposals->all(), ...$policies->all()])));
    }
}
