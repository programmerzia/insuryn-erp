<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Domain\Events;

/** Reinsurance MVP: a claim payment was paid (CLAIM_PAID). Dispatched inside the payment transaction, after its accounting event. */
final readonly class ClaimPaid
{
    public function __construct(
        public string $claimId,
        public string $paymentId,
        public int $amountMinor,
        public string $paidOn,
    ) {}
}
