<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Application;

use App\Modules\Accounting\Application\Queries\SourceJournalQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Premium receivable ageing by installment: outstanding premium bucketed by days past due at $asOf. ASSUMPTION: A-9 — outstanding amounts
 * are the installments' current state (payments and credits have no per-installment date history), so an earlier $asOf changes the ageing,
 * not the amounts. Each row drills to the journals that billed its policy (issue and endorsements).
 */
final class ReceivableAgeingQuery
{
    private const BUCKETS = ['not_due' => 0, '1-30' => 30, '31-60' => 60, '61-90' => 90, '90+' => PHP_INT_MAX];

    public function __construct(private readonly SourceJournalQuery $journals) {}

    /**
     * @return array{entity_id: string, as_of: string, buckets: array<string, int>, total_minor: int,
     *     rows: list<array{policy_id: string, policy_number: string|null, installment_id: string, installment_no: int, due_date: string, outstanding_minor: int,
     *     days_past_due: int, bucket: string, journals: list<array{journal_id: string, journal_number: string|null, posting_date: string, status: string, url: string}>}>}
     */
    public function ageing(string $entityId, CarbonImmutable $asOf): array
    {
        $installments = DB::table('installments as i')->join('policies as p', 'p.id', '=', 'i.policy_id')->where('p.entity_id', $entityId)
            ->whereRaw('i.amount_minor - i.paid_minor - i.cancelled_minor > 0')
            ->get(['i.id', 'i.policy_id', 'p.number', 'i.no', 'i.due_date', DB::raw('i.amount_minor - i.paid_minor - i.cancelled_minor as outstanding')]);
        $billing = DB::table('policy_transactions')->whereIn('policy_id', $installments->pluck('policy_id')->unique()->values()->all())
            ->whereIn('type', ['new', 'endorsement'])->get(['id', 'policy_id']);
        $journalsBySource = $this->journals->bySource('policy_transaction', $billing->pluck('id')->map(fn ($id): string => (string) $id)->all());
        $journalsByPolicy = [];
        foreach ($billing as $transaction) {
            $journalsByPolicy[(string) $transaction->policy_id] = [...($journalsByPolicy[(string) $transaction->policy_id] ?? []), ...($journalsBySource[(string) $transaction->id] ?? [])];
        }

        $buckets = array_fill_keys(array_keys(self::BUCKETS), 0);
        $rows = [];
        foreach ($installments as $installment) {
            $due = CarbonImmutable::parse((string) $installment->due_date);
            $days = $due->lessThan($asOf) ? (int) $due->diffInDays($asOf) : 0;
            $bucket = self::bucketFor($days);
            $outstanding = (int) $installment->outstanding;
            $buckets[$bucket] += $outstanding;
            $rows[] = ['policy_id' => (string) $installment->policy_id, 'policy_number' => $installment->number === null ? null : (string) $installment->number,
                'installment_id' => (string) $installment->id, 'installment_no' => (int) $installment->no, 'due_date' => $due->toDateString(),
                'outstanding_minor' => $outstanding, 'days_past_due' => $days, 'bucket' => $bucket, 'journals' => $journalsByPolicy[(string) $installment->policy_id] ?? []];
        }
        usort($rows, fn (array $x, array $y): int => [$y['days_past_due'], $x['due_date'], (string) $x['policy_number'], $x['installment_no']]
            <=> [$x['days_past_due'], $y['due_date'], (string) $y['policy_number'], $y['installment_no']]);

        return ['entity_id' => $entityId, 'as_of' => $asOf->toDateString(), 'buckets' => $buckets, 'total_minor' => array_sum($buckets), 'rows' => $rows];
    }

    private static function bucketFor(int $days): string
    {
        foreach (self::BUCKETS as $bucket => $maxDays) {
            if ($days <= $maxDays) {
                return $bucket;
            }
        }

        return '90+';
    }
}
