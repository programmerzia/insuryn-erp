<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Modules\Accounting\Application\Queries\JournalQuery;
use App\Modules\Accounting\Domain\Enums\JournalStatus;
use App\Modules\Accounting\Http\Presenters\MinorUnits;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Read-only journal list and detail with drill-through along the correction chain (design §2.3). */
final class JournalController
{
    public function index(Request $request, JournalQuery $journals): Response
    {
        $scope = ReportingScope::fromRequest($request);
        $status = $request->query('status');
        $status = is_string($status) && JournalStatus::tryFrom($status) !== null ? $status : null;
        $page = $journals->list($scope->entityId, ['status' => $status], max(1, (int) $request->query('page', '1')));

        return Inertia::render('accounting/journals/Index', [
            'entity' => $scope->entityProps(),
            'filters' => ['status' => $status],
            'statuses' => array_map(fn (JournalStatus $s): string => $s->value, JournalStatus::cases()),
            'journals' => [
                'data' => array_map(fn (object $row): array => [
                    'id' => (string) $row->id, 'number' => $row->number, 'status' => (string) $row->status, 'kind' => (string) $row->kind,
                    'postingDate' => (string) $row->posting_date, 'description' => $row->description,
                    'total' => MinorUnits::format((int) $row->total_minor, (string) $row->currency), 'currency' => (string) $row->currency,
                ], $page->items()),
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(string $journal, JournalQuery $journals): Response
    {
        $detail = Str::isUuid($journal) ? $journals->detail($journal) : null;
        if ($detail === null) {
            throw new NotFoundHttpException('Journal not found.');
        }
        $j = $detail['journal'];

        return Inertia::render('accounting/journals/Show', [
            'journal' => [
                'id' => $j['id'], 'number' => $j['number'], 'status' => $j['status'], 'kind' => $j['kind'],
                'transactionDate' => $j['transaction_date'], 'postingDate' => $j['posting_date'], 'effectiveDate' => $j['effective_date'],
                'description' => $j['description'], 'reason' => $j['reason'], 'currency' => $j['currency'], 'postedAt' => $j['posted_at'],
                'postingRule' => $j['posting_rule_code'] === null ? null : ['code' => $j['posting_rule_code'], 'version' => $j['posting_rule_version']],
                'source' => $j['source_type'] === null ? null : ['type' => $j['source_type'], 'id' => $j['source_id']],
                'event' => $detail['event'] === null ? null : ['id' => $detail['event']['id'], 'type' => $detail['event']['event_type']],
                'reverses' => $detail['reverses'],
                'reversedBy' => $detail['reversed_by'],
                'corrects' => $detail['corrects'],
                'corrections' => $detail['corrections'],
                'lines' => array_map(fn (array $line): array => [
                    'lineNo' => $line['line_no'], 'account' => ['code' => $line['account_code'], 'name' => $line['account_name']],
                    'side' => $line['side'], 'amount' => MinorUnits::format($line['amount_minor'], $line['currency']),
                    'role' => $line['role_code'], 'memo' => $line['memo'],
                ], $detail['lines']),
            ],
        ]);
    }
}
