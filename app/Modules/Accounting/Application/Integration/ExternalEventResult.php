<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Integration;

use App\Modules\Accounting\Domain\Models\AccountingEvent;
use App\Modules\Accounting\Domain\Models\Journal;

/** Outcome of submitting one external accounting event through the ledger API. */
final readonly class ExternalEventResult
{
    /** @param list<Journal> $journals populated only after a synchronous post */
    private function __construct(
        public string $intakeId,
        public AccountingEvent $event,
        public bool $created,
        public array $journals = [],
    ) {}

    /** @param list<Journal> $journals */
    public static function of(string $intakeId, AccountingEvent $event, bool $created, array $journals = []): self
    {
        return new self($intakeId, $event, $created, $journals);
    }
}
