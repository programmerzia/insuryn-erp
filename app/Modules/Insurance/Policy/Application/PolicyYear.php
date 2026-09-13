<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application;

use App\Modules\Insurance\Policy\Domain\Models\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The policy year a premium belongs to (Distribution design note §1 "policy_year_from 1..1 = first year, 2..99 = renewal"): 1 for the first
 * year of the original policy, plus one for every renewal in the chain, plus whole years from the policy's inception to the premium's date
 * (installment due date, or inception for written premium) for multi-year terms.
 */
final class PolicyYear
{
    public function of(Policy $policy, CarbonImmutable $premiumDate): int
    {
        $renewals = 0;
        $previous = $policy->renewal_of_policy_id;
        while ($previous !== null && $renewals < 99) {
            $renewals++;
            $previous = DB::table('policies')->where('id', $previous)->value('renewal_of_policy_id');
        }
        $years = $premiumDate->lessThanOrEqualTo($policy->inception) ? 0 : intdiv((int) $policy->inception->diffInMonths($premiumDate), 12);

        return min(99, 1 + $renewals + $years);
    }
}
