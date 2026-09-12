<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Application;

use App\Modules\Accounting\Application\Queries\SourceJournalQuery;
use App\Modules\Insurance\Commission\Application\CommissionStatementQuery;
use Carbon\CarbonImmutable;

/** Agent commission statement (1A.7) with each entry drilling to its COMMISSION_EARNED / COMMISSION_CLAWBACK journal. */
final class CommissionStatementReport
{
    public function __construct(
        private readonly CommissionStatementQuery $statements,
        private readonly SourceJournalQuery $journals,
    ) {}

    /** @return array<string, mixed> the statement, each entry with `journals` */
    public function statement(string $agentId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $statement = $this->statements->statement($agentId, $from, $to);
        $journals = $this->journals->bySource('commission_entry', array_column($statement['entries'], 'id'));
        $entries = [];
        foreach ($statement['entries'] as $entry) {
            $entries[] = $entry + ['journals' => $journals[$entry['id']] ?? []];
        }

        return ['entries' => $entries] + $statement;
    }
}
