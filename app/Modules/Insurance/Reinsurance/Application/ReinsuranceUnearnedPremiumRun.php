<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reinsurance\Application;

use App\Modules\Accounting\Application\Contracts\CloseCheckResult;
use App\Modules\Accounting\Application\Contracts\CloseTaskCheck;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;
use App\Modules\Insurance\Reinsurance\Domain\RiMath;
use App\Modules\Insurance\Reports\Application\UnearnedPremiumQuery;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Close task `ri_unearned_premium` (after premium earning): the reinsurers' share of unearned premium at the period end, as an asset. Per policy, each
 * reinsurer's ceded premium × the policy's unearned premium ÷ its written net premium (the unearned premium register); summed per branch and reinsurer, and
 * the change since the last period posted as RI_UPR_ADJUSTED on the period end. A rerun of a period changes nothing (one row per period, branch, reinsurer).
 */
final class ReinsuranceUnearnedPremiumRun implements CloseTaskCheck
{
    public function __construct(
        private readonly UnearnedPremiumQuery $register,
        private readonly ReinsuranceAccountingEvents $accounting,
    ) {}

    public function taskCode(): string
    {
        return 'ri_unearned_premium';
    }

    public function check(FiscalPeriodView $period, string $actorUserId): CloseCheckResult
    {
        $posted = $this->run($period);

        return CloseCheckResult::passed($posted === 0 ? 'Reinsurers\' share of unearned premium is up to date.' : "Reinsurers' share of unearned premium adjusted for {$posted} reinsurer(s).",
            ['adjustments' => $posted]);
    }

    /** @return int adjustments posted */
    public function run(FiscalPeriodView $period): int
    {
        if (DB::table('ri_upr_adjustments')->where('period_id', $period->id)->exists()) {
            return 0;
        }
        $asOf = $period->ends;
        $unearned = [];
        foreach ($this->register->unearned($period->entityId, $asOf)['rows'] as $row) {
            $unearned[$row['policy_id']] = [$row['unearned_minor'], $row['net_premium_minor']];
        }
        $targets = [];
        $ceded = DB::table('ri_cessions as c')->join('policies as p', 'p.id', '=', 'c.policy_id')->where('c.entity_id', $period->entityId)->where('c.accounting_date', '<=', $asOf->toDateString())
            ->groupBy('c.policy_id', 'p.branch_id', 'c.reinsurer_id')->selectRaw('c.policy_id, p.branch_id, c.reinsurer_id, sum(c.premium_minor) as premium')->get();
        foreach ($ceded as $row) {
            [$policyUnearned, $written] = $unearned[(string) $row->policy_id] ?? [0, 0];
            $key = "{$row->branch_id}|{$row->reinsurer_id}";
            $targets[$key] = ($targets[$key] ?? 0) + RiMath::ratio((int) $row->premium, $policyUnearned, $written);
        }
        $previous = DB::table('ri_upr_adjustments')->where('entity_id', $period->entityId)->where('as_of', '<', $asOf->toDateString())->groupBy('branch_id', 'reinsurer_id')
            ->selectRaw('branch_id, reinsurer_id, sum(delta_minor) as posted')->get()
            ->mapWithKeys(fn (object $r): array => ["{$r->branch_id}|{$r->reinsurer_id}" => (int) $r->posted])->all();
        $currency = (string) DB::table('legal_entities')->where('id', $period->entityId)->value('base_currency');
        $count = 0;
        foreach (array_unique([...array_keys($targets), ...array_keys($previous)]) as $key) {
            [$branchId, $reinsurerId] = explode('|', (string) $key);
            $target = $targets[$key] ?? 0;
            $delta = $target - ($previous[$key] ?? 0);
            if ($delta === 0) {
                continue;
            }
            DB::transaction(function () use ($period, $branchId, $reinsurerId, $target, $delta, $currency, $asOf): void {
                $id = (string) Str::uuid7();
                DB::table('ri_upr_adjustments')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $period->entityId, 'period_id' => $period->id,
                    'branch_id' => $branchId, 'reinsurer_id' => $reinsurerId, 'as_of' => $asOf->toDateString(), 'unearned_minor' => $target, 'delta_minor' => $delta,
                    'currency' => $currency, 'created_at' => now()]);
                $this->accounting->unearnedAdjusted($period->entityId, $id, $branchId, CessionEngine::partyOf($reinsurerId), $delta, $currency, $asOf);
            });
            $count++;
        }

        return $count;
    }
}
