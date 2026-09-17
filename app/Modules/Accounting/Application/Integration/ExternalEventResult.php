<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Integration;

use App\Modules\Accounting\Domain\Models\AccountingEvent;
use App\Modules\Accounting\Domain\Models\Journal;

/** Outcome of submitting one external accounting event through the ledger API. */
final readonly class ExternalEventResult
{
    /**
     * @param list<Journal> $journals populated only after a synchronous post
     * @param bool $resubmitted a failed event under the same idempotency_key was replaced by this body and queued again
     */
    private function __construct(
        public string $intakeId,
        public AccountingEvent $event,
        public bool $created,
        public array $journals = [],
        public bool $resubmitted = false,
    ) {}

    /** @param list<Journal> $journals */
    public static function of(string $intakeId, AccountingEvent $event, bool $created, array $journals = [], bool $resubmitted = false): self
    {
        return new self($intakeId, $event, $created, $journals, $resubmitted);
    }
}
