<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Domain\Events;

use Carbon\CarbonImmutable;

/** Premium was allocated to a policy installment, directly or out of suspense (design §1, commission is earned on receipt). Dispatched inside the allocation transaction. */
final readonly class ReceiptAllocated
{
    public function __construct(
        public string $receiptAllocationId,
        public string $receiptId,
        public string $policyId,
        public int $amountMinor,
        public CarbonImmutable $allocatedOn,
    ) {}
}
