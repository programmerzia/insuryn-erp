<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Modules\Accounting\Application\Queries\JournalQuery;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Platform\Authorization\PermissionChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Response;

/**
 * Journal viewer (UX brief §6.7) composed at the app layer: the kernel's journal page, plus dimensions as readable labels ("Branch HO",
 * "Policy POL-2026-000001") and a link to the source document. The kernel knows dimension ids, not the business records behind them.
 */
final class JournalPageController
{
    private const DIMENSIONS = [
        'dim_branch' => ['Branch', 'branches', 'code'], 'dim_product' => ['Product', 'products', 'code'], 'dim_agent' => ['Agent', 'agents', 'code'],
        'dim_policy' => ['Policy', 'policies', 'number'], 'dim_claim' => ['Claim', 'claims', 'number'], 'dim_customer' => ['Customer', 'parties', 'display_name'],
        'dim_lob' => ['Line of business', null, null], 'dim_channel' => ['Channel', null, null], 'dim_cost_centre' => ['Cost centre', null, null],
        'dim_employee' => ['Employee', null, null], 'dim_reinsurer' => ['Reinsurer', 'parties', 'display_name'],
    ];

    public function __invoke(Request $request, string $journal, JournalQuery $journals, PermissionChecker $permissions): Response
    {
        $response = app(JournalController::class)->show($request, $journal, $journals, $permissions);
        $source = DB::table('journals')->where('id', $journal)->first(['source_type', 'source_id']);

        return $response->with([
            'dimensions' => $this->dimensions($journal),
            'sourceLink' => $source === null || $source->source_type === null ? null : $this->sourceLink((string) $source->source_type, (string) $source->source_id),
        ]);
    }

    /** @return array<int, list<array{name: string, value: string}>> line number → labelled dimensions */
    private function dimensions(string $journalId): array
    {
        $lines = DB::table('journal_lines')->where('journal_id', $journalId)->orderBy('line_no')->get(['line_no', ...array_keys(self::DIMENSIONS)]);
        $cache = [];
        $result = [];
        foreach ($lines as $line) {
            $dims = [];
            foreach (self::DIMENSIONS as $column => [$name, $table, $field]) {
                $value = $line->{$column};
                if ($value === null || $value === '') {
                    continue;
                }
                if ($table !== null && $field !== null) {
                    $cache[$table][(string) $value] ??= (string) (DB::table($table)->where('id', $value)->value($field) ?? $value);
                    $value = $cache[$table][(string) $value];
                }
                $dims[] = ['name' => $name, 'value' => (string) $value];
            }
            $result[(int) $line->line_no] = $dims;
        }

        return $result;
    }

    private function sourceLink(string $type, string $id): ?string
    {
        $via = fn (string $table, string $column, string $prefix): ?string => ($parent = DB::table($table)->where('id', $id)->value($column)) === null ? null : "{$prefix}/{$parent}";

        return match ($type) {
            'policy_transaction' => $via('policy_transactions', 'policy_id', '/policies'),
            'premium_earning_ledger' => $via('premium_earning_ledger', 'policy_id', '/policies'),
            'receipt' => "/receipts/{$id}",
            'receipt_allocation' => $via('receipt_allocations', 'receipt_id', '/receipts'),
            'claim' => "/claims/{$id}",
            'claim_reserve' => $via('claim_reserves', 'claim_id', '/claims'),
            'claim_payment' => $via('claim_payments', 'claim_id', '/claims'),
            'claim_recovery' => $via('claim_recoveries', 'claim_id', '/claims'),
            'refund' => '/refunds',
            'agent_deposit' => '/agent-cash',
            'commission_entry', 'commission_statement' => '/commission',
            default => null,
        };
    }
}
