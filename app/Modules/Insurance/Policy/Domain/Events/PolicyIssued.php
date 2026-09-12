<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Domain\Events;

/** A quote became an issued policy (design §1 emits PolicyIssued). Dispatched inside the policy transaction. */
final readonly class PolicyIssued
{
    public function __construct(
        public string $policyId,
        public string $policyTransactionId,
    ) {}
}
