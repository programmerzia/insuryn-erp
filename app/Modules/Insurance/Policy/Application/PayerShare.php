<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application;

/** A payer of a policy's premium and its share in basis points (spec §4 multi-payer; all shares of a policy sum to 10000). */
final readonly class PayerShare
{
    public function __construct(
        public string $partyId,
        public int $shareBp,
    ) {}
}
