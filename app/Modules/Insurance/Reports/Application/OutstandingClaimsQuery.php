<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Application;

use App\Modules\Accounting\Application\Queries\SourceJournalQuery;
use App\Modules\Insurance\Claims\Domain\Enums\ClaimPaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Outstanding claims (spec §4 Claims reports) as of a date: per claim, the open case reserve (reserve history to the date − amounts approved
 * by then) and approved-unpaid (approved by then − paid by then). Claims with neither are omitted. Rows drill to reserve and payment journals.
 */
final class OutstandingClaimsQuery
{
    public function __construct(private readonly SourceJournalQuery $journals) {}

    /**
     * @return array{entity_id: string, as_of: string, totals: array{outstanding_reserve_minor: int, approved_unpaid_minor: int, total_minor: int},
     *     rows: list<array{claim_id: string, claim_number: string, policy_id: string, policy_number: string|null, branch_id: string, loss_date: string, status: string,
     *     outstanding_reserve_minor: int, approved_unpaid_minor: int, journals: list<array{journal_id: string, journal_number: string|null, posting_date: string, status: string, url: string}>}>}
     */
    public function outstanding(string $entityId, CarbonImmutable $asOf): array
    {
        $day = $asOf->toDateString();
        $claims = DB::table('claims as c')->join('policies as p', 'p.id', '=', 'c.policy_id')->where('c.entity_id', $entityId)->where('c.reported_on', '<=', $day)
            ->selectRaw('c.id, c.number, c.policy_id, p.number as policy_number, c.branch_id, c.loss_date, c.status')
            ->selectSub(DB::table('claim_reserves')->whereColumn('claim_id', 'c.id')->where('recorded_on', '<=', $day)->selectRaw('coalesce(sum(delta_minor), 0)'), 'reserved')
            ->selectSub(DB::table('claim_payments')->whereColumn('claim_id', 'c.id')->where('approved_on', '<=', $day)->whereIn('status', ClaimPaymentStatus::approved())
                ->selectRaw('coalesce(sum(amount_minor), 0)'), 'approved')
            ->selectSub(DB::table('claim_payments')->whereColumn('claim_id', 'c.id')->where('status', 'paid')->where('paid_on', '<=', $day)
                ->selectRaw('coalesce(sum(amount_minor), 0)'), 'paid')
            ->orderBy('c.loss_date')->orderBy('c.number')->get();

        $rows = [];
        $totals = ['outstanding_reserve_minor' => 0, 'approved_unpaid_minor' => 0, 'total_minor' => 0];
        foreach ($claims as $claim) {
            $reserve = (int) $claim->reserved - (int) $claim->approved;
            $unpaid = (int) $claim->approved - (int) $claim->paid;
            if ($reserve === 0 && $unpaid === 0) {
                continue;
            }
            $rows[] = ['claim_id' => (string) $claim->id, 'claim_number' => (string) $claim->number, 'policy_id' => (string) $claim->policy_id,
                'policy_number' => $claim->policy_number === null ? null : (string) $claim->policy_number, 'branch_id' => (string) $claim->branch_id,
                'loss_date' => (string) $claim->loss_date, 'status' => (string) $claim->status, 'outstanding_reserve_minor' => $reserve, 'approved_unpaid_minor' => $unpaid,
                'journals' => $this->journalsFor((string) $claim->id)];
            $totals['outstanding_reserve_minor'] += $reserve;
            $totals['approved_unpaid_minor'] += $unpaid;
            $totals['total_minor'] += $reserve + $unpaid;
        }

        return ['entity_id' => $entityId, 'as_of' => $day, 'totals' => $totals, 'rows' => $rows];
    }

    /** @return list<array{journal_id: string, journal_number: string|null, posting_date: string, status: string, url: string}> */
    private function journalsFor(string $claimId): array
    {
        $reserveIds = DB::table('claim_reserves')->where('claim_id', $claimId)->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $paymentIds = DB::table('claim_payments')->where('claim_id', $claimId)->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $journals = array_merge(...array_values($this->journals->bySource('claim_reserve', $reserveIds)), ...array_values($this->journals->bySource('claim_payment', $paymentIds)));
        usort($journals, fn (array $a, array $b): int => [$a['posting_date'], $a['journal_id']] <=> [$b['posting_date'], $b['journal_id']]);

        return $journals;
    }
}
