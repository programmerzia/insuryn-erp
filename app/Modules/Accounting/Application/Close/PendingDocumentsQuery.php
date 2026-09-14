<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Close;

use App\Modules\Accounting\Application\Contracts\PendingCloseDocument;
use App\Modules\Accounting\Application\Contracts\PendingCloseDocuments;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;

/**
 * Slice 2.1b (DECISION D-55, CQ-C4): every document dated in a period that still waits for approval, release or posting, from every tagged
 * PendingCloseDocuments source, oldest first. The close checklist shows it, a soft lock warns about it and the period lock refuses while it is not empty.
 */
final class PendingDocumentsQuery
{
    /** @param iterable<PendingCloseDocuments> $sources */
    public function __construct(private readonly iterable $sources) {}

    /** @return list<PendingCloseDocument> */
    public function forPeriod(FiscalPeriodView $period): array
    {
        $documents = [];
        foreach ($this->sources as $source) {
            array_push($documents, ...$source->pendingIn($period));
        }
        usort($documents, fn (PendingCloseDocument $a, PendingCloseDocument $b): int => [$a->date, $a->type, $a->id] <=> [$b->date, $b->type, $b->id]);

        return $documents;
    }

    /**
     * How many documents wait, by kind ("1 manual journal, 2 claim payments"), for a refusal or a warning.
     *
     * @param list<PendingCloseDocument> $documents
     */
    public static function summary(array $documents): string
    {
        $labels = ['manual_journal' => 'manual journal', 'journal_reversal' => 'journal reversal', 'claim_payment' => 'claim payment', 'refund' => 'refund',
            'accounting_event' => 'unposted accounting event'];
        $counts = [];
        foreach ($documents as $document) {
            $counts[$document->type] = ($counts[$document->type] ?? 0) + 1;
        }
        $parts = [];
        foreach ($counts as $type => $count) {
            $label = $labels[$type] ?? str_replace('_', ' ', $type);
            $parts[] = $count.' '.$label.($count === 1 ? '' : 's');
        }

        return implode(', ', $parts);
    }
}
