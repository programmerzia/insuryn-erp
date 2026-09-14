<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reinsurance\Application;

use App\Modules\Insurance\Policy\Domain\Enums\PolicyStatus;
use App\Modules\Insurance\Policy\Domain\Events\PolicyCancelled;
use App\Modules\Insurance\Policy\Domain\Events\PolicyEndorsed;
use App\Modules\Insurance\Policy\Domain\Events\PolicyIssued;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Reinsurance\Domain\RiMath;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The cession engine. It listens to the policy lifecycle (PolicyIssued, PolicyEndorsed, PolicyCancelled, all inside the policy's transaction) and writes
 * append-only cession movements per reinsurer, each posted as RI_PREMIUM_CEDED.
 *
 * On issue and endorsement it computes the target split of the policy as it now stands — sum insured (proposal, else the risk's sum insured) and net
 * premium (without VAT and stamp duty) — under the treaty for the policy's class whose period covers the inception:
 * 1. the SBC compulsory share of the gross (ASSUMPTION A-251, A-252) to the state reinsurer;
 * 2. of the rest, quota share: the cession %; surplus: the retention is kept, up to `lines` × retention is ceded, anything beyond is above capacity (left for
 *    a facultative placement, shown on the policy);
 * 3. the treaty's cession is split between its participants by share; commission is the treaty's % of each reinsurer's ceded premium (the SBC share too, A-255);
 * 4. premium follows the share of sum insured (proportional reinsurance); a policy without a sum insured cedes on premium only (quota share and SBC).
 * Each movement is the target less what was ceded before, so an endorsement cedes only its change. Facultative placements are not recomputed.
 * On cancellation every cession (facultative too) is reduced by the unearned share returned to the policyholder, and its ceded sum insured by all of it.
 */
final class CessionEngine
{
    public function __construct(private readonly ReinsuranceAccountingEvents $accounting) {}

    public function onIssued(PolicyIssued $event): void
    {
        $this->cede($event->policyId, $event->policyTransactionId, 'issue');
    }

    public function onEndorsed(PolicyEndorsed $event): void
    {
        $this->cede($event->policyId, $event->policyTransactionId, 'endorsement');
    }

    public function onCancelled(PolicyCancelled $event): void
    {
        $policy = Policy::query()->findOrFail($event->policyId);
        $on = $this->transactionDate($event->policyTransactionId);
        $groups = DB::table('ri_cessions')->where('policy_id', $policy->id)->groupBy('kind', 'reinsurer_id')
            ->selectRaw('kind, reinsurer_id, max(treaty_id::text) as treaty_id, max(facultative_placement_id::text) as placement_id, sum(ceded_sum_insured_minor) as si, sum(premium_minor) as premium, sum(commission_minor) as commission')
            ->orderBy('kind')->orderBy('reinsurer_id')->get();
        foreach ($groups as $g) {
            $premium = -RiMath::ratio((int) $g->premium, $event->unearnedRemainingMinor, $event->netPremiumMinor);
            $commission = -RiMath::ratio((int) $g->commission, $event->unearnedRemainingMinor, $event->netPremiumMinor);
            if ((int) $g->si === 0 && $premium === 0 && $commission === 0) {
                continue;
            }
            $this->write($policy, ['kind' => (string) $g->kind, 'reinsurer_id' => (string) $g->reinsurer_id, 'treaty_id' => $g->treaty_id === null ? null : (string) $g->treaty_id,
                'placement_id' => $g->placement_id === null ? null : (string) $g->placement_id, 'share_bp' => 0,
                'si' => -(int) $g->si, 'premium' => $premium, 'commission' => $commission], $event->policyTransactionId, 'cancellation', $on);
        }
        DB::table('ri_policy_positions')->where('policy_id', $policy->id)->update(['above_capacity_minor' => 0, 'note' => 'Policy cancelled: cessions reduced by the unearned premium.', 'updated_at' => now()]);
    }

    /**
     * Cedes every issued policy of the entity that has no cession movement yet (policies issued before the treaties were set up), dated $on. Returns the
     * number of policies ceded. Rerunning changes nothing for policies already ceded.
     */
    public function backfill(string $entityId, CarbonImmutable $on): int
    {
        $count = 0;
        $policies = DB::table('policies as p')->where('p.entity_id', $entityId)->whereIn('p.status', [PolicyStatus::Issued->value, PolicyStatus::Active->value])
            ->whereNotExists(fn ($q) => $q->from('ri_cessions as c')->whereColumn('c.policy_id', 'p.id'))->orderBy('p.number')->pluck('p.id');
        foreach ($policies as $policyId) {
            $transactionId = (string) DB::table('policy_transactions')->where('policy_id', $policyId)->orderByDesc('created_at')->orderByDesc('id')->value('id');
            $count += DB::transaction(fn (): int => $this->cede((string) $policyId, $transactionId, 'backfill', $on) > 0 ? 1 : 0);
        }

        return $count;
    }

    /**
     * Brings the policy's treaty and SBC cessions to the target for its current sum insured and premium. Returns the movements written.
     */
    public function cede(string $policyId, string $transactionId, string $movement, ?CarbonImmutable $on = null): int
    {
        $policy = Policy::query()->findOrFail($policyId);
        if ($policy->status === PolicyStatus::Quote) {
            return 0;
        }
        $on ??= $this->transactionDate($transactionId);
        $classCode = self::classOf($policy);
        $sumInsured = self::sumInsuredOf($policy);
        $premium = $policy->net_premium_minor;
        $treaty = $classCode === null ? null : DB::table('ri_treaties')->where('entity_id', $policy->entity_id)->where('class_code', $classCode)->where('status', 'active')
            ->where('period_from', '<=', $policy->inception->toDateString())->where('period_to', '>=', $policy->inception->toDateString())->orderByDesc('underwriting_year')->first();
        if ($treaty === null) {
            $this->position($policy, null, $sumInsured, $premium, 0, 0, $sumInsured, 0, 'No treaty in force for this class on the inception date: the whole risk is retained.');

            return 0;
        }
        [$targets, $split, $note] = $this->targets($treaty, $sumInsured, $premium);
        $facultative = (int) DB::table('ri_cessions')->where('policy_id', $policy->id)->where('kind', 'facultative')->sum('ceded_sum_insured_minor');
        $this->position($policy, (string) $treaty->id, $sumInsured, $premium, $split['sbc'], $split['treaty'], $split['retained'], max(0, $split['above'] - $facultative), $note);

        $existing = [];
        foreach (DB::table('ri_cessions')->where('policy_id', $policy->id)->where('kind', '<>', 'facultative')->groupBy('kind', 'reinsurer_id')
            ->selectRaw('kind, reinsurer_id, sum(ceded_sum_insured_minor) as si, sum(premium_minor) as premium, sum(commission_minor) as commission')->get() as $row) {
            $existing["{$row->kind}|{$row->reinsurer_id}"] = ['si' => (int) $row->si, 'premium' => (int) $row->premium, 'commission' => (int) $row->commission];
        }
        $written = 0;
        foreach (array_unique([...array_keys($targets), ...array_keys($existing)]) as $key) {
            [$kind, $reinsurerId] = explode('|', $key);
            $target = $targets[$key] ?? ['si' => 0, 'premium' => 0, 'commission' => 0, 'share_bp' => 0];
            $before = $existing[$key] ?? ['si' => 0, 'premium' => 0, 'commission' => 0];
            $delta = ['si' => $target['si'] - $before['si'], 'premium' => $target['premium'] - $before['premium'], 'commission' => $target['commission'] - $before['commission']];
            if ($delta['si'] === 0 && $delta['premium'] === 0 && $delta['commission'] === 0) {
                continue;
            }
            $written += $this->write($policy, ['kind' => $kind, 'reinsurer_id' => $reinsurerId, 'treaty_id' => (string) $treaty->id, 'placement_id' => null,
                'share_bp' => $target['share_bp']] + $delta, $transactionId, $movement, $on);
        }

        return $written;
    }

    /**
     * The target cession per "kind|reinsurer" and the split of the sum insured.

     * @return array{0: array<string, array{si: int, premium: int, commission: int, share_bp: int}>, 1: array{sbc: int, treaty: int, retained: int, above: int}, 2: string|null}
     */
    private function targets(\stdClass $treaty, int $sumInsured, int $premium): array
    {
        $commissionBp = (int) $treaty->commission_bp;
        $sbc = DB::table('reinsurers')->where('is_state_reinsurer', true)->where('status', 'active')->value('id');
        $sbcBp = $sbc === null ? 0 : (int) $treaty->sbc_share_bp;
        $notes = [];
        if ($sbc === null && (int) $treaty->sbc_share_bp > 0) {
            $notes[] = 'No state reinsurer (SBC) is set up, so no compulsory share was ceded.';
        }
        $targets = [];
        $sbcSi = RiMath::bp($sumInsured, $sbcBp);
        $sbcPremium = RiMath::bp($premium, $sbcBp);
        if ($sbc !== null && ($sbcSi !== 0 || $sbcPremium !== 0)) {
            $targets["sbc|{$sbc}"] = ['si' => $sbcSi, 'premium' => $sbcPremium, 'commission' => RiMath::bp($sbcPremium, $commissionBp), 'share_bp' => $sbcBp];
        }
        $restSi = $sumInsured - $sbcSi;
        $restPremium = $premium - $sbcPremium;
        $above = 0;
        if ($treaty->type === 'quota_share') {
            $treatySi = RiMath::bp($restSi, (int) $treaty->cession_bp);
            $treatyPremium = RiMath::bp($restPremium, (int) $treaty->cession_bp);
            $retained = $restSi - $treatySi;
        } elseif ($sumInsured <= 0) {
            [$treatySi, $treatyPremium, $retained] = [0, 0, 0];
            $notes[] = 'The policy has no sum insured, so the surplus treaty cannot apply.';
        } else {
            $retention = min($restSi, (int) $treaty->retention_minor);
            $treatySi = min($restSi - $retention, (int) $treaty->retention_minor * (int) $treaty->lines);
            $above = $restSi - $retention - $treatySi;
            $treatyPremium = RiMath::ratio($restPremium, $treatySi, $restSi);
            $retained = $retention;
            if ($above > 0) {
                $notes[] = 'The risk is above the surplus treaty\'s capacity: place the rest facultatively.';
            }
        }
        $participants = DB::table('ri_treaty_participants')->where('treaty_id', $treaty->id)->orderBy('reinsurer_id')->pluck('share_bp', 'reinsurer_id')
            ->mapWithKeys(fn (mixed $bp, mixed $id): array => [(string) $id => (int) $bp])->all();
        $siParts = RiMath::split($treatySi, $participants);
        $premiumParts = RiMath::split($treatyPremium, $participants);
        $kind = (string) $treaty->type;
        foreach ($participants as $reinsurerId => $shareBp) {
            if ($siParts[$reinsurerId] === 0 && $premiumParts[$reinsurerId] === 0) {
                continue;
            }
            $targets["{$kind}|{$reinsurerId}"] = ['si' => $siParts[$reinsurerId], 'premium' => $premiumParts[$reinsurerId],
                'commission' => RiMath::bp($premiumParts[$reinsurerId], $commissionBp),
                'share_bp' => $sumInsured > 0 ? RiMath::shareBp($siParts[$reinsurerId], $sumInsured) : RiMath::shareBp($premiumParts[$reinsurerId], $premium)];
        }

        return [$targets, ['sbc' => $sbcSi, 'treaty' => $treatySi, 'retained' => $retained, 'above' => $above], $notes === [] ? null : implode(' ', $notes)];
    }

    /** @param array{kind: string, reinsurer_id: string, treaty_id: string|null, placement_id: string|null, share_bp: int, si: int, premium: int, commission: int} $m */
    private function write(Policy $policy, array $m, ?string $transactionId, string $movement, CarbonImmutable $on): int
    {
        $id = (string) Str::uuid7();
        $inserted = DB::table('ri_cessions')->insertOrIgnore(['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $policy->entity_id, 'policy_id' => $policy->id,
            'policy_transaction_id' => $transactionId, 'facultative_placement_id' => $m['placement_id'], 'treaty_id' => $m['treaty_id'], 'reinsurer_id' => $m['reinsurer_id'],
            'kind' => $m['kind'], 'movement' => $movement, 'share_bp' => $m['share_bp'], 'ceded_sum_insured_minor' => $m['si'], 'premium_minor' => $m['premium'],
            'commission_minor' => $m['commission'], 'accounting_date' => $on->toDateString(), 'currency' => $policy->currency, 'created_at' => now()]);
        if ($inserted === 0) {
            return 0;
        }
        if ($m['premium'] !== 0 || $m['commission'] !== 0) {
            $this->accounting->premiumCeded($policy, $id, self::partyOf($m['reinsurer_id']), $m['premium'], $m['commission'], $on);
        }

        return 1;
    }

    /** Records a facultative placement's cession movement (FacultativeService). */
    public function writePlacement(Policy $policy, string $placementId, string $reinsurerId, int $shareBp, int $sumInsuredMinor, int $premiumMinor, int $commissionMinor, CarbonImmutable $on): void
    {
        $this->write($policy, ['kind' => 'facultative', 'reinsurer_id' => $reinsurerId, 'treaty_id' => null, 'placement_id' => $placementId, 'share_bp' => $shareBp,
            'si' => $sumInsuredMinor, 'premium' => $premiumMinor, 'commission' => $commissionMinor], null, 'placement', $on);
    }

    public static function partyOf(string $reinsurerId): string
    {
        return (string) DB::table('reinsurers')->where('id', $reinsurerId)->value('party_id');
    }

    /** The treaty class of a policy: its product version's class, else its line of business (A-253). */
    public static function classOf(Policy $policy): ?string
    {
        $class = DB::table('product_versions')->where('id', $policy->product_version_id)->value('class_code');
        if ($class !== null) {
            return (string) $class;
        }
        $lob = (string) DB::table('products')->where('id', $policy->product_id)->value('lob');
        $map = (array) config('erp.reinsurance.lob_classes', []);

        return isset($map[$lob]) ? (string) $map[$lob] : null;
    }

    /** The policy's sum insured now: the latest endorsement re-rating's risk, else the policy's frozen risk, else its proposal (A-254); 0 when none is known. */
    public static function sumInsuredOf(Policy $policy): int
    {
        $field = (string) config('erp.reinsurance.sum_insured_field', 'sum_insured');
        $latest = DB::table('policy_transactions')->where('policy_id', $policy->id)->whereNotNull('rating_result')->orderByDesc('created_at')->orderByDesc('id')
            ->selectRaw("rating_result->'risk_inputs'->>? as si", [$field])->first()?->si;
        if (is_numeric($latest) && (int) $latest > 0) {
            return (int) $latest;
        }
        $risk = $policy->risk_inputs[$field] ?? null;
        if (is_numeric($risk) && (int) $risk > 0) {
            return (int) $risk;
        }

        return $policy->proposal_id === null ? 0 : (int) DB::table('proposals')->where('id', $policy->proposal_id)->value('sum_insured_minor');
    }

    private function position(Policy $policy, ?string $treatyId, int $sumInsured, int $premium, int $sbc, int $treaty, int $retained, int $above, ?string $note): void
    {
        DB::table('ri_policy_positions')->upsert([['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'policy_id' => $policy->id, 'treaty_id' => $treatyId,
            'sum_insured_minor' => $sumInsured, 'net_premium_minor' => $premium, 'sbc_sum_insured_minor' => $sbc, 'treaty_sum_insured_minor' => $treaty,
            'retained_sum_insured_minor' => $retained, 'above_capacity_minor' => $above, 'note' => $note, 'created_at' => now(), 'updated_at' => now()]],
            ['tenant_id', 'policy_id'], ['treaty_id', 'sum_insured_minor', 'net_premium_minor', 'sbc_sum_insured_minor', 'treaty_sum_insured_minor', 'retained_sum_insured_minor',
                'above_capacity_minor', 'note', 'updated_at']);
    }

    private function transactionDate(string $transactionId): CarbonImmutable
    {
        return CarbonImmutable::parse((string) DB::table('policy_transactions')->where('id', $transactionId)->value('accounting_date'));
    }
}
