<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Modules\Accounting\Application\Queries\JournalQuery;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Journal viewer (UX brief §6.7) composed at the app layer: the kernel's journal page, plus dimensions as readable labels ("Branch HO",
 * "Policy POL-2026-000001") and a link to the source document. The kernel knows dimension ids, not the business records behind them.
 * GA-31: a manual journal's supporting documents (the scanned voucher), attached by whoever may make manual journals and read by whoever may see journals.
 */
final class JournalPageController
{
    /** ASSUMPTION: A-198 (GA-31) — who attaches a voucher to a manual journal — holders of accounting.create_manual_journal in the journal's entity, on manual and adjustment journals (not system postings). */
    public const ATTACH = 'accounting.create_manual_journal';

    private const DIMENSIONS = [
        'dim_branch' => ['Branch', 'branches', 'code'], 'dim_product' => ['Product', 'products', 'code'], 'dim_agent' => ['Producer', 'producers', 'code'],
        'dim_policy' => ['Policy', 'policies', 'number'], 'dim_claim' => ['Claim', 'claims', 'number'], 'dim_customer' => ['Customer', 'parties', 'display_name'],
        'dim_lob' => ['Line of business', null, null], 'dim_channel' => ['Channel', null, null], 'dim_cost_centre' => ['Cost centre', null, null],
        'dim_employee' => ['Employee', null, null], 'dim_reinsurer' => ['Reinsurer', 'parties', 'display_name'],
    ];

    public function __invoke(Request $request, string $journal, JournalQuery $journals, PermissionChecker $permissions): Response
    {
        $response = app(JournalController::class)->show($request, $journal, $journals, $permissions);
        $source = DB::table('journals')->where('id', $journal)->first(['source_type', 'source_id', 'kind', 'entity_id']);
        $manual = $source !== null && in_array((string) $source->kind, ['manual', 'adjustment'], true) && in_array($source->source_type, [null, 'manual_journal'], true);

        return $response->with([
            'dimensions' => $this->dimensions($journal),
            // GA-07: the source link shows only when the reader may open that screen.
            'sourceLink' => $source === null || $source->source_type === null ? null
                : JournalSources::linkFor((string) $source->source_type, (string) $source->source_id, $permissions->permissionsOf((string) $request->user()?->getAuthIdentifier())),
            'documents' => $manual ? app(ObjectDocuments::class)->forPage('journal', $journal, "/accounting/journals/{$journal}") : null,
            'documentUpload' => $manual && $permissions->has(PageSupport::actor($request), self::ATTACH, AuthorizationScope::entity((string) $source->entity_id))
                ? "/accounting/journals/{$journal}/documents" : null,
        ]);
    }

    public function attachDocument(Request $request, string $journal, ObjectDocuments $documents, PermissionChecker $permissions): RedirectResponse
    {
        $row = DB::table('journals')->where('id', $journal)->first(['id', 'entity_id', 'kind', 'source_type']) ?? abort(404);
        $permissions->authorize(PageSupport::actor($request), self::ATTACH, AuthorizationScope::entity((string) $row->entity_id));
        abort_unless(in_array((string) $row->kind, ['manual', 'adjustment'], true) && in_array($row->source_type, [null, 'manual_journal'], true), 404);

        return $documents->attach($request, 'journal', (string) $row->id, "/accounting/journals/{$journal}");
    }

    public function downloadDocument(Request $request, string $journal, string $document, ObjectDocuments $documents): StreamedResponse
    {
        abort_unless(DB::table('journals')->where('id', $journal)->exists(), 404);

        return $documents->download($request, 'journal', $journal, $document);
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
