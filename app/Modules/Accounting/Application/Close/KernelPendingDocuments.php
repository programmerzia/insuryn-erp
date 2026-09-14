<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Close;

use App\Modules\Accounting\Application\Contracts\PendingCloseDocument;
use App\Modules\Accounting\Application\Contracts\PendingCloseDocuments;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;
use Illuminate\Support\Facades\DB;

/**
 * Slice 2.1b (D-55): the kernel's own documents that hold a period's lock — manual journals (manual, adjustment, opening) dated in the period and
 * not yet posted (waiting for approval, or approved and not posted), reversal requests dated in the period waiting for approval, and accounting
 * events dated in the period that are not posted (received, queued, posting or failed). ASSUMPTION A-153.
 */
final class KernelPendingDocuments implements PendingCloseDocuments
{
    private const JOURNAL_KINDS = ['manual', 'adjustment', 'opening'];

    private const JOURNAL_WAITING = ['pending_approval', 'approved', 'queued', 'posting', 'failed'];

    private const EVENT_WAITING = ['received', 'queued', 'posting', 'failed'];

    public function pendingIn(FiscalPeriodView $period): array
    {
        [$from, $to] = [$period->starts->toDateString(), $period->ends->toDateString()];
        $documents = [];

        $journals = DB::table('journals as j')->where('j.entity_id', $period->entityId)->where('j.book_id', $period->bookId)
            ->whereIn('j.kind', self::JOURNAL_KINDS)->whereIn('j.status', self::JOURNAL_WAITING)->whereBetween('j.posting_date', [$from, $to])
            ->orderBy('j.posting_date')->orderBy('j.id')
            ->get(['j.id', 'j.posting_date', 'j.status', 'j.description', 'j.currency',
                DB::raw("(select coalesce(sum(l.amount_minor), 0) from journal_lines l where l.journal_id = j.id and l.side = 'debit') as debit_minor")]);
        foreach ($journals as $j) {
            $pending = $j->status === 'pending_approval';
            $documents[] = new PendingCloseDocument('manual_journal', (string) $j->id, 'Manual journal: '.(string) $j->description, substr((string) $j->posting_date, 0, 10),
                (string) $j->status, $pending ? 'Approve or reject it, or its approver moves it to the next open period.' : 'Post it or reject it.',
                (int) $j->debit_minor, (string) $j->currency, "/accounting/journals/{$j->id}", $pending);
        }

        $reversals = DB::table('journal_reversal_requests as r')->join('journals as j', 'j.id', '=', 'r.journal_id')
            ->where('j.entity_id', $period->entityId)->where('j.book_id', $period->bookId)->where('r.status', 'pending')->whereBetween('r.on_date', [$from, $to])
            ->orderBy('r.on_date')->orderBy('r.id')
            ->get(['r.id', 'r.journal_id', 'r.on_date', 'r.reason', 'j.number', 'j.currency',
                DB::raw("(select coalesce(sum(l.amount_minor), 0) from journal_lines l where l.journal_id = j.id and l.side = 'debit') as debit_minor")]);
        foreach ($reversals as $r) {
            $documents[] = new PendingCloseDocument('journal_reversal', (string) $r->id, 'Reversal of journal '.((string) ($r->number ?? '') !== '' ? (string) $r->number : '(unnumbered)').': '.(string) $r->reason,
                substr((string) $r->on_date, 0, 10), 'pending_approval', 'Approve or reject the reversal.', (int) $r->debit_minor, (string) $r->currency,
                "/accounting/journals/{$r->journal_id}");
        }

        $events = DB::table('accounting_events')->where('entity_id', $period->entityId)->whereIn('status', self::EVENT_WAITING)
            ->whereBetween('transaction_date', [$from, $to])->orderBy('transaction_date')->orderBy('created_at')
            ->get(['id', 'event_type', 'transaction_date', 'status', 'failure_reason', 'currency']);
        foreach ($events as $e) {
            $failed = $e->status === 'failed';
            $documents[] = new PendingCloseDocument('accounting_event', (string) $e->id, 'Accounting event '.(string) $e->event_type.($failed && $e->failure_reason !== null ? ' ('.(string) $e->failure_reason.')' : ''),
                substr((string) $e->transaction_date, 0, 10), (string) $e->status, $failed ? 'Fix the cause and post it again.' : 'Let the posting worker post it.',
                null, (string) $e->currency);
        }

        return $documents;
    }
}
