<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Domain\Events;

/** A policy was cancelled; carries the unearned share for commission clawback (design §1, §4.4 event C). Dispatched inside the policy transaction. */
final readonly class PolicyCancelled
{
    public function __construct(
        public string $policyId,
        public string $policyTransactionId,
        public int $unearnedRemainingMinor,
        public int $netPremiumMinor,
    ) {}
}
