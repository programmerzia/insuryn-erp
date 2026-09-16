<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Integration;

use App\Modules\Accounting\Application\Queries\JournalQuery;

/** GET /api/v1/journals/{id} — journal detail for integration clients. */
final class LedgerJournalQuery
{
    public function __construct(private readonly JournalQuery $journals) {}

    /** @return array<string, mixed>|null */
    public function find(string $journalId): ?array
    {
        $detail = $this->journals->detail($journalId);
        if ($detail === null) {
            return null;
        }

        $debit = array_sum(array_map(fn (array $l): int => $l['side'] === 'debit' ? $l['amount_minor'] : 0, $detail['lines']));
        $credit = array_sum(array_map(fn (array $l): int => $l['side'] === 'credit' ? $l['amount_minor'] : 0, $detail['lines']));

        return [
            'journal' => $detail['journal'],
            'lines' => $detail['lines'],
            'event' => $detail['event'],
            'totals' => ['debit_minor' => $debit, 'credit_minor' => $credit, 'balanced' => $debit === $credit],
        ];
    }
}
