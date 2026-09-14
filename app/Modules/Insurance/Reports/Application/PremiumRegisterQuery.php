<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Application;

use App\Modules\Accounting\Application\Queries\SourceJournalQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Premium register: written premium per policy transaction by accounting date — new business and endorsements as billed, cancellations
 * as return premium (−unearned remaining, −tax reversal). Each row drills to the journals posted for its transaction. Totals are also given by class
 * (ASSUMPTION A-60: the product's line of business) and by branch.
 */
final class PremiumRegisterQuery
{
    public function __construct(private readonly SourceJournalQuery $journals) {}

    /**
     * @return array{entity_id: string, from: string, to: string, totals: array{gross_minor: int, net_minor: int, tax_minor: int, stamp_duty_minor: int},
     *     by_class: list<array{group: string, gross_minor: int, net_minor: int, tax_minor: int, stamp_duty_minor: int}>, by_branch: list<array{group: string, gross_minor: int, net_minor: int, tax_minor: int, stamp_duty_minor: int}>,
     *     rows: list<array{policy_transaction_id: string, accounting_date: string, type: string, policy_id: string, policy_number: string|null, product_code: string, class: string,
     *     branch_id: string, branch_code: string, agent_id: string|null, customer_id: string, currency: string, gross_minor: int, net_minor: int, tax_minor: int, stamp_duty_minor: int,
     *     journals: list<array{journal_id: string, journal_number: string|null, posting_date: string, status: string, url: string}>}>}
     */
    public function register(string $entityId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $transactions = DB::table('policy_transactions as t')->join('policies as p', 'p.id', '=', 't.policy_id')->join('products as pr', 'pr.id', '=', 'p.product_id')
            ->join('branches as b', 'b.id', '=', 'p.branch_id')->where('p.entity_id', $entityId)->whereBetween('t.accounting_date', [$from->toDateString(), $to->toDateString()])->where('t.type', '<>', 'renewal')
            ->orderBy('t.accounting_date')->orderBy('p.number')->orderBy('t.created_at')
            ->get(['t.id', 't.accounting_date', 't.type', 't.premium_delta_minor', 't.net_delta_minor', 't.tax_delta_minor', 't.stamp_duty_delta_minor', 't.amounts',
                'p.id as policy_id', 'p.number', 'pr.code', 'pr.lob', 'p.branch_id', 'b.code as branch_code', 'p.agent_id', 'p.policyholder_party_id', 'p.currency']);
        $journals = $this->journals->bySource('policy_transaction', $transactions->pluck('id')->map(fn ($id): string => (string) $id)->all());

        $rows = [];
        $totals = ['gross_minor' => 0, 'net_minor' => 0, 'tax_minor' => 0, 'stamp_duty_minor' => 0];
        $byClass = [];
        $byBranch = [];
        foreach ($transactions as $t) {
            /** @var object{id: string, accounting_date: string, type: string, premium_delta_minor: int|string, net_delta_minor: int|string, tax_delta_minor: int|string, stamp_duty_delta_minor: int|string|null, amounts: string|null,
             *     policy_id: string, number: string|null, code: string, lob: string, branch_id: string, branch_code: string, agent_id: string|null, policyholder_party_id: string, currency: string} $t */
            [$gross, $net, $tax, $stampDuty] = self::writtenPremium($t);
            $rows[] = ['policy_transaction_id' => (string) $t->id, 'accounting_date' => (string) $t->accounting_date, 'type' => (string) $t->type,
                'policy_id' => (string) $t->policy_id, 'policy_number' => $t->number === null ? null : (string) $t->number, 'product_code' => (string) $t->code, 'class' => (string) $t->lob,
                'branch_id' => (string) $t->branch_id, 'branch_code' => (string) $t->branch_code, 'agent_id' => $t->agent_id === null ? null : (string) $t->agent_id, 'customer_id' => (string) $t->policyholder_party_id,
                'currency' => (string) $t->currency, 'gross_minor' => $gross, 'net_minor' => $net, 'tax_minor' => $tax, 'stamp_duty_minor' => $stampDuty, 'journals' => $journals[(string) $t->id] ?? []];
            $totals['gross_minor'] += $gross;
            $totals['net_minor'] += $net;
            $totals['tax_minor'] += $tax;
            $totals['stamp_duty_minor'] += $stampDuty;
            $byClass = self::accumulate($byClass, (string) $t->lob, $gross, $net, $tax, $stampDuty);
            $byBranch = self::accumulate($byBranch, (string) $t->branch_code, $gross, $net, $tax, $stampDuty);
        }
        ksort($byClass);
        ksort($byBranch);

        return ['entity_id' => $entityId, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'totals' => $totals,
            'by_class' => array_values($byClass), 'by_branch' => array_values($byBranch), 'rows' => $rows];
    }

    /**
     * @param array<string, array{group: string, gross_minor: int, net_minor: int, tax_minor: int, stamp_duty_minor: int}> $groups
     * @return array<string, array{group: string, gross_minor: int, net_minor: int, tax_minor: int, stamp_duty_minor: int}>
     */
    private static function accumulate(array $groups, string $group, int $gross, int $net, int $tax, int $stampDuty): array
    {
        $current = $groups[$group] ?? ['group' => $group, 'gross_minor' => 0, 'net_minor' => 0, 'tax_minor' => 0, 'stamp_duty_minor' => 0];
        $groups[$group] = ['group' => $group, 'gross_minor' => $current['gross_minor'] + $gross, 'net_minor' => $current['net_minor'] + $net, 'tax_minor' => $current['tax_minor'] + $tax,
            'stamp_duty_minor' => $current['stamp_duty_minor'] + $stampDuty];

        return $groups;
    }

    /**
     * Gap audit GA-34: stamp duty is its own figure (slice R7 D-37: gross = net + VAT + stamp duty); a cancellation returns none (A-118).
     *
     * @param object{type: string, premium_delta_minor: int|string, net_delta_minor: int|string, tax_delta_minor: int|string, stamp_duty_delta_minor: int|string|null, amounts: string|null} $transaction
     * @return array{0: int, 1: int, 2: int, 3: int} gross, net, tax, stamp duty
     */
    private static function writtenPremium(object $transaction): array
    {
        if ($transaction->type !== 'cancellation') {
            return [(int) $transaction->premium_delta_minor, (int) $transaction->net_delta_minor, (int) $transaction->tax_delta_minor, (int) ($transaction->stamp_duty_delta_minor ?? 0)];
        }
        /** @var array<string, int> $amounts */
        $amounts = json_decode((string) $transaction->amounts, true) ?? [];
        $net = -(int) ($amounts['unearned_remaining'] ?? 0);
        $tax = -(int) ($amounts['tax_reversal'] ?? 0);

        return [$net + $tax, $net, $tax, 0];
    }
}
