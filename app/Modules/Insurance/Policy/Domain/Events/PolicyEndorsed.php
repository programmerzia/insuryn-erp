<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Domain\Events;

/** A policy's cover or premium changed (design §1 emits PolicyEndorsed). Dispatched inside the policy transaction. */
final readonly class PolicyEndorsed
{
    public function __construct(
        public string $policyId,
        public string $policyTransactionId,
    ) {}
}
