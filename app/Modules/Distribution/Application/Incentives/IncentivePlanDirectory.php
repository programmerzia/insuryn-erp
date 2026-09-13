<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Incentives;

use App\Modules\Distribution\Application\Hierarchy\HierarchyQuery;
use App\Modules\Distribution\Domain\Incentives\IncentivePeriod;
use App\Modules\Distribution\Domain\Incentives\IncentiveTiers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Which incentive plans end a period on a day, and which active producers each applies to (by type, channel and level on that day). */
final class IncentivePlanDirectory
{
    public function __construct(private readonly HierarchyQuery $hierarchy) {}

    /** @return list<IncentiveTerms> */
    public function endingOn(CarbonImmutable $day): array
    {
        $terms = [];
        $plans = DB::table('incentive_plans')->where('effective_from', '<=', $day->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $day->toDateString()))->orderBy('code')->get();
        foreach ($plans as $plan) {
            $period = IncentivePeriod::endingOn((string) $plan->period_type, $day);
            if ($period === null) {
                continue;
            }
            $appliesTo = (array) json_decode((string) $plan->applies_to, true);
            $type = isset($appliesTo['producer_type']) ? (string) $appliesTo['producer_type'] : null;
            $channel = isset($appliesTo['channel_id']) ? (string) $appliesTo['channel_id'] : null;
            $level = isset($appliesTo['level_code']) ? (string) $appliesTo['level_code'] : null;
            $producers = array_values(DB::table('producers')->where('status', 'active')->when($type !== null, fn ($q) => $q->where('type', $type))
                ->when($channel !== null, fn ($q) => $q->where('channel_id', $channel))->orderBy('code')->pluck('id')->map(fn ($id): string => (string) $id)->all());
            if ($level !== null) {
                $producers = array_values(array_filter($producers, fn (string $id): bool => $this->hierarchy->positionAt($id, $day)?->level_code === $level));
            }
            $terms[] = new IncentiveTerms((string) $plan->id, (string) $plan->code, (string) $plan->metric, (string) $plan->period_type, $period->start, $period->end, $producers,
                $plan->withholding_jurisdiction === null ? null : (string) $plan->withholding_jurisdiction, $plan->withholding_tax_type === null ? null : (string) $plan->withholding_tax_type,
                IncentiveTiers::fromArray((string) $plan->metric, (array) json_decode((string) $plan->tiers, true)));
        }

        return $terms;
    }
}
