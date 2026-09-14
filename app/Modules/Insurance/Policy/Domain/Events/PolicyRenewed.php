<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Domain\Events;

/**
 * Slice R9: an expiring policy was renewed by issuing its renewal policy from the proposal of its renewal quotation (design §4 "renewing creates a new
 * policy with previous_policy_id"). The previous policy is now `renewed`. Dispatched inside the policy issue transaction, after PolicyIssued.
 */
final readonly class PolicyRenewed
{
    public function __construct(
        public string $previousPolicyId,
        public string $renewalPolicyId,
    ) {}
}
