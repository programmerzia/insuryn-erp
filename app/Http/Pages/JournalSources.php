<?php

declare(strict_types=1);

namespace App\Http\Pages;

use Illuminate\Support\Facades\DB;

/**
 * The business record behind a journal (UX brief §6.7 drill-down), composed at the app layer: the kernel knows a journal's source type and id, not the
 * policy, claim or receipt page they belong to. Used by the journal viewer and, flow fix X11, by account activity so a figure reaches its policy in one step.
 */
final class JournalSources
{
    /** The page of a journal's source record, or null when it has none. */
    public static function link(string $type, string $id): ?string
    {
        return self::resolve($type, $id)['url'];
    }

    /**
     * The source record of each journal: its page and a short label (policy, claim or receipt number).
     *
     * @param list<string> $journalIds
     * @return array<string, array{url: string, label: string}> journal id → source (journals without a linkable source are left out)
     */
    public static function forJournals(array $journalIds): array
    {
        if ($journalIds === []) {
            return [];
        }
        $sources = [];
        foreach (DB::table('journals')->whereIn('id', array_values(array_unique($journalIds)))->whereNotNull('source_type')->get(['id', 'source_type', 'source_id']) as $journal) {
            $source = self::resolve((string) $journal->source_type, (string) $journal->source_id);
            if ($source['url'] !== null) {
                $sources[(string) $journal->id] = ['url' => $source['url'], 'label' => $source['label']];
            }
        }

        return $sources;
    }

    /**
     * The page and label of a source record by its type and id (GA-08: an accounting event that did not post links the record it came from).
     *
     * @return array{url: string|null, label: string}
     */
    public static function source(string $type, string $id): array
    {
        return self::resolve($type, $id);
    }

    /** @return array{url: string|null, label: string} */
    private static function resolve(string $type, string $id): array
    {
        $parent = fn (string $table, string $column): ?string => ($value = DB::table($table)->where('id', $id)->value($column)) === null ? null : (string) $value;
        $record = fn (string $table, ?string $recordId, string $prefix, string $word): array => $recordId === null
            ? ['url' => null, 'label' => $word]
            : ['url' => "{$prefix}/{$recordId}", 'label' => (string) (DB::table($table)->where('id', $recordId)->value('number') ?? $word)];

        return match ($type) {
            'policy_transaction' => $record('policies', $parent('policy_transactions', 'policy_id'), '/policies', 'Policy'),
            // Gap audit GA-45: the ledger row's policy; journals posted before the fix carry the policy id itself as the source id.
            'premium_earning_ledger' => $record('policies', $parent('premium_earning_ledger', 'policy_id')
                ?? (DB::table('policies')->where('id', $id)->exists() ? $id : null), '/policies', 'Policy'),
            'receipt' => $record('receipts', $id, '/receipts', 'Receipt'),
            'receipt_allocation' => $record('receipts', $parent('receipt_allocations', 'receipt_id'), '/receipts', 'Receipt'),
            'claim' => $record('claims', $id, '/claims', 'Claim'),
            'claim_reserve' => $record('claims', $parent('claim_reserves', 'claim_id'), '/claims', 'Claim'),
            'claim_payment' => $record('claims', $parent('claim_payments', 'claim_id'), '/claims', 'Claim'),
            'claim_recovery' => $record('claims', $parent('claim_recoveries', 'claim_id'), '/claims', 'Claim'),
            'refund' => ['url' => '/refunds', 'label' => 'Refunds'],
            'agent_deposit' => ['url' => '/agent-cash', 'label' => 'Agent cash'],
            'commission_entry', 'commission_statement' => ['url' => '/commission', 'label' => 'Commission'],
            default => ['url' => null, 'label' => ''],
        };
    }
}
