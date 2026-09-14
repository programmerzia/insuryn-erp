<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use App\Modules\Platform\Authorization\AreaReach;
use Illuminate\Support\Facades\DB;

/** Cancelled policies with refund still due: Σ cancellation refund_due − refunds requested or released (the RefundService cap). */
final class RefundableQuery
{
    /** @return list<array{policy_id: string, policy_number: string|null, policyholder: string, currency: string, available_minor: int}> */
    public function refundable(string $entityId, ?AreaReach $reach = null): array
    {
        // Follow-up H1: limited to the policies within the user's reach when a screen passes it.
        $rows = ($reach ?? AreaReach::everywhere())->constrain(DB::table('policies as p'), 'p.entity_id', 'p.branch_id')->join('parties as h', 'h.id', '=', 'p.policyholder_party_id')->where('p.entity_id', $entityId)->where('p.status', 'cancelled')
            ->selectRaw("p.id, p.number, h.display_name, p.currency,
                coalesce((select sum((t.amounts->>'refund_due')::bigint) from policy_transactions t where t.policy_id = p.id and t.type = 'cancellation'), 0)
              - coalesce((select sum(r.amount_minor) from refunds r where r.policy_id = p.id and r.status in ('requested', 'released')), 0) as available")
            ->orderBy('p.number')->get();
        $result = [];
        foreach ($rows as $row) {
            if ((int) $row->available > 0) {
                $result[] = ['policy_id' => (string) $row->id, 'policy_number' => $row->number === null ? null : (string) $row->number, 'policyholder' => (string) $row->display_name,
                    'currency' => (string) $row->currency, 'available_minor' => (int) $row->available];
            }
        }

        return $result;
    }
}
