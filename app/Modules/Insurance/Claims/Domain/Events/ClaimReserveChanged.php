<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Domain\Events;

/** Reinsurance MVP: a claim's reserve changed by $deltaMinor (a reserve, an adjustment or a release). Dispatched inside the claim transaction, after its accounting event. */
final readonly class ClaimReserveChanged
{
    public function __construct(
        public string $claimId,
        public string $reserveId,
        public int $deltaMinor,
        public string $recordedOn,
    ) {}
}
