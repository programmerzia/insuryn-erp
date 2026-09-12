<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Application;

use App\Modules\Accounting\Application\Queries\SourceJournalQuery;
use App\Modules\Insurance\Collections\Application\SuspenseQuery;
use Carbon\CarbonImmutable;

/** Suspense ageing (design §4.9) with each item drilling to the journal that put its receipt into suspense. */
final class SuspenseAgeingReport
{
    public function __construct(
        private readonly SuspenseQuery $suspense,
        private readonly SourceJournalQuery $journals,
    ) {}

    /**
     * @return array{as_of: string, buckets: array<string, int>, total_minor: int,
     *     items: list<array{id: string, receipt_id: string, receipt_number: string, reference: string|null, branch_id: string, aged_since: string, open_minor: int, days: int,
     *     journals: list<array{journal_id: string, journal_number: string|null, posting_date: string, status: string, url: string}>}>}
     */
    public function ageing(string $entityId, CarbonImmutable $asOf): array
    {
        $ageing = $this->suspense->ageing($entityId, $asOf);
        $journals = $this->journals->bySource('receipt', array_column($ageing['items'], 'receipt_id'));
        $items = [];
        foreach ($ageing['items'] as $item) {
            $items[] = $item + ['journals' => $journals[$item['receipt_id']] ?? []];
        }

        return ['as_of' => $ageing['as_of'], 'buckets' => $ageing['buckets'], 'total_minor' => $ageing['total_minor'], 'items' => $items];
    }
}
