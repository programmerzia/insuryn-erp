<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reinsurance\Application;

use App\Modules\Insurance\Claims\Domain\Events\ClaimPaid;
use App\Modules\Insurance\Claims\Domain\Events\ClaimReserveChanged;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Reinsurance\Domain\RiMath;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reinsurers' share of claims, following the cession of the claim's policy: each reinsurer's share is its ceded sum insured over the policy's sum insured
 * (its ceded premium over the net premium for a policy without a sum insured), facultative placements included, as the policy stands when the claim moves.
 * - A reserve change → RI_CLAIM_RESERVE_CEDED for the reinsurer's share of the change (reinsurers' share of outstanding claims).
 * - A payment → RI_CLAIM_RECOVERABLE for the reinsurer's share of the payment: out of its reserve share while one is left, the rest straight from claims incurred.
 */
final class ClaimCessions
{
    public function __construct(private readonly ReinsuranceAccountingEvents $accounting) {}

    public function onReserveChanged(ClaimReserveChanged $event): void
    {
        $claim = DB::table('claims')->where('id', $event->claimId)->first(['id', 'entity_id', 'policy_id', 'currency']);
        if ($claim === null || $event->deltaMinor === 0) {
            return;
        }
        $policy = Policy::query()->findOrFail((string) $claim->policy_id);
        $on = CarbonImmutable::parse($event->recordedOn);
        foreach (self::shares($policy) as $reinsurerId => $shareBp) {
            $amount = RiMath::bp($event->deltaMinor, $shareBp);
            if ($amount === 0) {
                continue;
            }
            $id = $this->record($policy, (string) $claim->id, $reinsurerId, 'reserve', 'claim_reserve', $event->reserveId, $shareBp, $event->deltaMinor, $amount, 0, $on);
            if ($id !== null) {
                $this->accounting->claimReserveCeded($policy, (string) $claim->id, $id, CessionEngine::partyOf($reinsurerId), $amount, $on);
            }
        }
    }

    public function onPaid(ClaimPaid $event): void
    {
        $claim = DB::table('claims')->where('id', $event->claimId)->first(['id', 'entity_id', 'policy_id', 'currency']);
        if ($claim === null) {
            return;
        }
        $policy = Policy::query()->findOrFail((string) $claim->policy_id);
        $on = CarbonImmutable::parse($event->paidOn);
        foreach (self::shares($policy) as $reinsurerId => $shareBp) {
            $amount = RiMath::bp($event->amountMinor, $shareBp);
            if ($amount <= 0) {
                continue;
            }
            $reserveShare = self::outstandingShare((string) $claim->id, $reinsurerId);
            $fromReserve = max(0, min($reserveShare, $amount));
            $id = $this->record($policy, (string) $claim->id, $reinsurerId, 'recoverable', 'claim_payment', $event->paymentId, $shareBp, $event->amountMinor, $amount, $fromReserve, $on);
            if ($id !== null) {
                $this->accounting->claimRecoverable($policy, (string) $claim->id, $id, CessionEngine::partyOf($reinsurerId), $fromReserve, $amount - $fromReserve, $on);
            }
        }
    }

    /**
     * Each reinsurer's share of the policy in basis points.
     *
     * @return array<string, int> reinsurer id → basis points
     */
    public static function shares(Policy $policy): array
    {
        $sumInsured = CessionEngine::sumInsuredOf($policy);
        $rows = DB::table('ri_cessions')->where('policy_id', $policy->id)->groupBy('reinsurer_id')->orderBy('reinsurer_id')
            ->selectRaw('reinsurer_id, sum(ceded_sum_insured_minor) as si, sum(premium_minor) as premium')->get();
        $shares = [];
        foreach ($rows as $row) {
            $bp = $sumInsured > 0 ? RiMath::shareBp((int) $row->si, $sumInsured) : RiMath::shareBp((int) $row->premium, $policy->net_premium_minor);
            if ($bp > 0) {
                $shares[(string) $row->reinsurer_id] = $bp;
            }
        }

        return $shares;
    }

    /** The reinsurer's share of the claim's reserve not yet turned into a recoverable. */
    public static function outstandingShare(string $claimId, string $reinsurerId, ?string $asOf = null): int
    {
        return (int) DB::table('ri_claim_shares')->where('claim_id', $claimId)->where('reinsurer_id', $reinsurerId)
            ->when($asOf !== null, fn ($q) => $q->where('recorded_on', '<=', $asOf))
            ->selectRaw("coalesce(sum(case when kind = 'reserve' then amount_minor else -from_reserve_minor end), 0) as outstanding")->value('outstanding');
    }

    private function record(Policy $policy, string $claimId, string $reinsurerId, string $kind, string $sourceType, string $sourceId, int $shareBp, int $gross, int $amount, int $fromReserve, CarbonImmutable $on): ?string
    {
        $id = (string) Str::uuid7();
        $inserted = DB::table('ri_claim_shares')->insertOrIgnore(['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $policy->entity_id, 'claim_id' => $claimId,
            'policy_id' => $policy->id, 'reinsurer_id' => $reinsurerId, 'kind' => $kind, 'source_type' => $sourceType, 'source_id' => $sourceId, 'share_bp' => $shareBp,
            'gross_minor' => $gross, 'amount_minor' => $amount, 'from_reserve_minor' => $fromReserve, 'recorded_on' => $on->toDateString(), 'currency' => $policy->currency, 'created_at' => now()]);

        return $inserted === 0 ? null : $id;
    }
}
