<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\ManualJournals;

use App\Modules\Accounting\Domain\Enums\JournalKind;
use Carbon\CarbonImmutable;

/** A manual, adjustment or opening journal as entered by its maker (design §2.2 journals.kind). */
final readonly class ManualJournalRequest
{
    /** @param list<ManualJournalLine> $lines */
    public function __construct(
        public string $entityId,
        public CarbonImmutable $transactionDate,
        public string $description,
        public JournalKind $kind,
        public ?string $reason,
        public string $currency,
        public array $lines,
    ) {}
}
