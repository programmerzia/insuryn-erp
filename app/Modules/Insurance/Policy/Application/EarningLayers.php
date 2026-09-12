<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application;

use App\Modules\Insurance\Policy\Domain\EarningLayer;
use App\Modules\Insurance\Policy\Domain\Enums\PolicyTransactionType;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Policy\Domain\Models\PolicyTransaction;

/** The earning layers of a policy: new business over the whole term, each endorsement delta from its effective date. */
final class EarningLayers
{
    /** @return list<EarningLayer> */
    public static function of(Policy $policy): array
    {
        return array_values(PolicyTransaction::query()->where('policy_id', $policy->id)
            ->whereIn('type', [PolicyTransactionType::New->value, PolicyTransactionType::Endorsement->value])
            ->orderBy('created_at')->orderBy('id')->get()
            ->filter(fn (PolicyTransaction $tx): bool => $tx->net_delta_minor !== 0)
            ->map(fn (PolicyTransaction $tx): EarningLayer => new EarningLayer($tx->net_delta_minor, $tx->effective_date, $policy->expiry))
            ->all());
    }
}
