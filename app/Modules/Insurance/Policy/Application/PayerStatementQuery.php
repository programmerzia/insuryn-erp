<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application;

use App\Modules\Insurance\Policy\Domain\Models\Policy;
use Illuminate\Support\Facades\DB;

/**
 * What each payer of a policy has been billed, has paid, was credited and still owes (spec §4 multi-payer). Interpretation: the general ledger's
 * customer dimension stays the policyholder; per-payer balances come from the payer's installments.
 */
final class PayerStatementQuery
{
    public function __construct(private readonly InstallmentPlanner $installments) {}

    /**
     * @return array{policy_id: string, payers: list<array{party_id: string, name: string, share_bp: int, billed_minor: int, paid_minor: int, credited_minor: int, outstanding_minor: int}>}
     */
    public function forPolicy(string $policyId): array
    {
        $policy = Policy::query()->findOrFail($policyId);
        $totals = DB::table('installments')->where('policy_id', $policy->id)->groupBy('payer_party_id')
            ->selectRaw('payer_party_id, sum(amount_minor) as billed, sum(paid_minor) as paid, sum(cancelled_minor) as credited')->get()->keyBy('payer_party_id');
        $payers = $this->installments->payers($policy);
        $names = DB::table('parties')->whereIn('id', array_map(fn (PayerShare $p): string => $p->partyId, $payers))->pluck('display_name', 'id');

        $rows = [];
        foreach ($payers as $payer) {
            $row = $totals->get($payer->partyId);
            $billed = (int) ($row->billed ?? 0);
            $paid = (int) ($row->paid ?? 0);
            $credited = (int) ($row->credited ?? 0);
            $rows[] = ['party_id' => $payer->partyId, 'name' => (string) ($names[$payer->partyId] ?? ''), 'share_bp' => $payer->shareBp,
                'billed_minor' => $billed, 'paid_minor' => $paid, 'credited_minor' => $credited, 'outstanding_minor' => $billed - $paid - $credited];
        }

        return ['policy_id' => $policy->id, 'payers' => $rows];
    }
}
