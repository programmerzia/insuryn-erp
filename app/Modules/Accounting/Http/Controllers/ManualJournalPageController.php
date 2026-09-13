<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Application\Reversals\ReversalRequestService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Manual and adjustment journals entered on screen (design §5.2): create and submit, approve or reject by someone else; reversal requests. */
final class ManualJournalPageController
{
    public function create(): Response
    {
        $entity = PageSupport::entity();

        return Inertia::render('accounting/journals/Create', [
            'entity' => $entity,
            'kinds' => [JournalKind::Manual->value, JournalKind::Adjustment->value],
            'accounts' => DB::table('accounts')->where('entity_id', $entity['id'])->where('is_postable', true)->where('status', 'active')->orderBy('code')
                ->get(['id', 'code', 'name', 'is_control'])->map(fn (object $a): array => ['id' => (string) $a->id, 'code' => (string) $a->code, 'name' => (string) $a->name, 'is_control' => (bool) $a->is_control])->values()->all(),
            'branches' => DB::table('branches')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $b): array => (array) $b)->values()->all(),
        ]);
    }

    public function store(Request $request, ManualJournalService $journals): RedirectResponse
    {
        /** @var array{transaction_date: string, description: string, kind: string, reason?: string|null, lines: list<array{account_id: string, side: string, amount: string, branch_id?: string|null, memo?: string|null}>} $data */
        $data = $request->validate(['transaction_date' => ['required', 'date_format:Y-m-d'], 'description' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in([JournalKind::Manual->value, JournalKind::Adjustment->value])], 'reason' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:2'], 'lines.*.account_id' => ['required', 'uuid'], 'lines.*.side' => ['required', Rule::enum(Side::class)],
            'lines.*.amount' => ['required', 'string'], 'lines.*.branch_id' => ['nullable', 'uuid'], 'lines.*.memo' => ['nullable', 'string', 'max:255']]);
        $entity = PageSupport::entity();
        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            $lines[] = new ManualJournalLine($line['account_id'], Side::from($line['side']), PageSupport::minor("lines.{$index}.amount", $line['amount'], $entity['currency']),
                ($line['branch_id'] ?? '') === '' ? [] : ['branch' => (string) $line['branch_id']], $line['memo'] ?? null);
        }
        $actor = PageSupport::actor($request);
        $journal = $journals->create(new ManualJournalRequest($entity['id'], CarbonImmutable::parse($data['transaction_date']), $data['description'], JournalKind::from($data['kind']),
            $data['reason'] ?? null, $entity['currency'], $lines), $actor);
        $approvalId = $journals->submit($journal->id, $actor);

        return redirect("/accounting/journals/{$journal->id}")->with('status', $approvalId === null ? 'Journal submitted; another user must approve it.' : 'Journal submitted for approval under the approval policy.');
    }

    public function approve(Request $request, string $journal, ManualJournalService $journals): RedirectResponse
    {
        $journals->approve($journal, PageSupport::actor($request));

        return redirect("/accounting/journals/{$journal}")->with('status', 'Journal approved.');
    }

    public function reject(Request $request, string $journal, ManualJournalService $journals): RedirectResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $journals->reject($journal, PageSupport::actor($request), $data['reason']);

        return redirect("/accounting/journals/{$journal}")->with('status', 'Journal rejected.');
    }

    public function requestReversal(Request $request, string $journal, ReversalRequestService $reversals): RedirectResponse
    {
        /** @var array{on: string, reason: string} $data */
        $data = $request->validate(['on' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:1000']]);
        $reversals->request($journal, CarbonImmutable::parse($data['on']), $data['reason'], PageSupport::actor($request));

        return redirect("/accounting/journals/{$journal}")->with('status', 'Reversal requested; another user must approve it.');
    }

    public function decideReversal(Request $request, string $reversalRequest, string $decision, ReversalRequestService $reversals): RedirectResponse
    {
        $journalId = (string) DB::table('journal_reversal_requests')->where('id', $reversalRequest)->value('journal_id');
        if ($decision === 'approve') {
            $reversal = $reversals->approve($reversalRequest, PageSupport::actor($request));
            $message = $reversal === null ? 'Approval recorded; further approval steps remain.' : 'Journal reversed.';
        } else {
            /** @var array{reason: string} $data */
            $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
            $reversals->reject($reversalRequest, PageSupport::actor($request), $data['reason']);
            $message = 'Reversal rejected.';
        }

        return redirect("/accounting/journals/{$journalId}")->with('status', $message);
    }
}
