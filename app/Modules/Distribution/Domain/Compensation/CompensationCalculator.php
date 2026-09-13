<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Compensation;

/**
 * Distribution design note §2, steps 1–4, as a pure function of in-memory terms (golden fixtures in tests/Fixtures/compensation):
 * 1. A scheme that pays no commission yields nothing. Non-life commission needs the compliance profile's permission. The seller must be
 *    eligible (active — or terminated with `pays_after_termination` in a renewal year —, licensed — or a renewal year of a rule without
 *    `renewal_requires_valid_licence` —, of a type the profile allows); an ineligible seller means no commission for anyone on the trigger.
 * 2. Direct commission: the most specific rule covering the seller (product > producer type > level); rate limited by the rule's cap.
 * 3. Overrides: each level above the seller in the snapshot is paid once, by its most specific override rule; an ineligible manager's override
 *    is not paid and is reported.
 * 4. Rules with a minimum persistency produce conditional lines (checked at statement time).
 * INVARIANT Σ commission rates on the trigger ≤ the compliance cap: a breach blocks the whole calculation, not the policy.
 * Two equally specific rules make the calculation ambiguous: nothing is paid and it is reported.
 */
final class CompensationCalculator
{
    private const WHOLE_BP = 10_000;

    /**
     * @param list<RuleTerms> $rules rules in force on the trigger date
     * @param list<Beneficiary> $chain the seller (depth 0) and everyone above it
     */
    public function calculate(SchemeTerms $scheme, array $rules, array $chain, Trigger $trigger): Calculation
    {
        $seller = $chain[0] ?? null;
        if ($seller === null || ! $scheme->paysCommission() || $trigger->baseMinor === 0) {
            return new Calculation([], []);
        }
        if ($trigger->productClass === 'non_life' && ! $scheme->profile->nonLifeCommissionAllowed) {
            return self::blocked($seller, 'NON_LIFE_COMMISSION_DISABLED', "Commission on non-life products is disabled for {$seller->code}'s scheme.");
        }
        $applicable = array_values(array_filter($rules, fn (RuleTerms $rule): bool => $rule->covers($trigger)));

        $direct = $this->mostSpecific(array_filter($applicable, fn (RuleTerms $r): bool => $r->rateBp > 0 && self::fits($r, $seller)), fn (RuleTerms $r): int => self::specificity($r, true));
        if ($direct === false) {
            return self::blocked($seller, 'COMPENSATION_RULE_AMBIGUOUS', "Two equally specific rules apply to {$seller->code}; end one of them.");
        }
        // The seller is checked even without a direct rule: an ineligible seller's business pays no overrides either.
        $sellerIssue = $this->ineligibility($scheme, $seller, $trigger, $direct === null || $direct->renewalRequiresValidLicence, $direct !== null && $direct->paysAfterTermination);
        if ($sellerIssue !== null) {
            return new Calculation([], [$sellerIssue]);
        }

        $lines = $direct === null ? [] : [$this->line($scheme, $direct, $seller, 'direct', $direct->capped($direct->rateBp), $trigger)];
        $issues = [];
        $paidLevels = [];
        foreach (array_slice($chain, 1) as $manager) {
            if ($manager->levelCode === null || isset($paidLevels[$manager->levelCode])) {
                continue;
            }
            $override = $this->mostSpecific(array_filter($applicable, fn (RuleTerms $r): bool => $r->overrideRateBp > 0 && $r->levelCode === $manager->levelCode
                && ($r->producerType === null || $r->producerType === $manager->type)), fn (RuleTerms $r): int => self::specificity($r, false));
            if ($override === false) {
                return self::blocked($seller, 'COMPENSATION_RULE_AMBIGUOUS', "Two equally specific override rules apply to level {$manager->levelCode}; end one of them.");
            }
            if ($override === null) {
                continue;
            }
            $paidLevels[$manager->levelCode] = true;
            $issue = $this->ineligibility($scheme, $manager, $trigger, $override->renewalRequiresValidLicence, $override->paysAfterTermination);
            if ($issue !== null) {
                $issues[] = $issue;
                continue;
            }
            $lines[] = $this->line($scheme, $override, $manager, 'override', $override->capped($override->overrideRateBp), $trigger);
        }

        $cap = $scheme->profile->capFor($trigger->productId, $trigger->policyYear);
        $totalBp = array_sum(array_map(fn (CommissionLine $l): int => $l->rateBp, $lines));
        if ($cap !== null && $totalBp > $cap) {
            return self::blocked($seller, 'COMPLIANCE_CAP_EXCEEDED', "Commission of {$totalBp} basis points on {$seller->code}'s business would exceed the cap of {$cap} in policy year {$trigger->policyYear}.");
        }

        return new Calculation($lines, $issues);
    }

    private function ineligibility(SchemeTerms $scheme, Beneficiary $producer, Trigger $trigger, bool $renewalRequiresValidLicence, bool $paysAfterTermination): ?ComplianceIssue
    {
        $renewal = $trigger->policyYear >= 2;
        if ($producer->status !== 'active' && ! ($producer->status === 'terminated' && $renewal && $paysAfterTermination)) {
            return new ComplianceIssue($producer->producerId, 'PRODUCER_NOT_ACTIVE', "{$producer->code} is {$producer->status} and earns no commission on this premium.");
        }
        if (! $producer->licensed && ! ($renewal && ! $renewalRequiresValidLicence)) {
            return new ComplianceIssue($producer->producerId, 'LICENCE_INVALID', "{$producer->code} has no valid licence for this product class on the day, so it earns no commission on it.");
        }
        if (! $scheme->profile->allows($producer->type)) {
            return new ComplianceIssue($producer->producerId, 'PRODUCER_TYPE_NOT_ALLOWED', "The scheme's compliance profile does not allow commission to {$producer->type} producers such as {$producer->code}.");
        }

        return null;
    }

    private function line(SchemeTerms $scheme, RuleTerms $rule, Beneficiary $beneficiary, string $role, int $rateBp, Trigger $trigger): CommissionLine
    {
        $amount = HalfEven::prorate($trigger->baseMinor, $rateBp, self::WHOLE_BP);

        return new CommissionLine($beneficiary, $role, $beneficiary->levelCode, $rule->id, $rateBp, $amount, HalfEven::prorate($amount, $scheme->withholdingBp, self::WHOLE_BP),
            $rule->minPersistencyBp !== null);
    }

    private static function fits(RuleTerms $rule, Beneficiary $seller): bool
    {
        return ($rule->producerType === null || $rule->producerType === $seller->type) && ($rule->levelCode === null || $rule->levelCode === $seller->levelCode);
    }

    private static function specificity(RuleTerms $rule, bool $withLevel): int
    {
        return ($rule->productId !== null ? 4 : 0) + ($rule->producerType !== null ? 2 : 0) + ($withLevel && $rule->levelCode !== null ? 1 : 0);
    }

    /**
     * @param array<int, RuleTerms> $candidates
     * @param callable(RuleTerms): int $score
     * @return RuleTerms|false|null null = none, false = ambiguous
     */
    private function mostSpecific(array $candidates, callable $score): RuleTerms|false|null
    {
        if ($candidates === []) {
            return null;
        }
        usort($candidates, fn (RuleTerms $a, RuleTerms $b): int => $score($b) <=> $score($a));

        return count($candidates) > 1 && $score($candidates[0]) === $score($candidates[1]) ? false : $candidates[0];
    }

    private static function blocked(Beneficiary $seller, string $reason, string $message): Calculation
    {
        return new Calculation([], [new ComplianceIssue($seller->producerId, $reason, $message)]);
    }
}
