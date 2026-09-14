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
        'dim_branch' => ['Branch', 'branches', 'code'], 'dim_product' => ['Product', 'products', 'code'], 'dim_agent' => ['Producer', 'producers', 'code'],
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
            // GA-07: the source link shows only when the reader may open that screen.
            'sourceLink' => $source === null || $source->source_type === null ? null
                : JournalSources::linkFor((string) $source->source_type, (string) $source->source_id, $permissions->permissionsOf((string) $request->user()?->getAuthIdentifier())),
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
}
