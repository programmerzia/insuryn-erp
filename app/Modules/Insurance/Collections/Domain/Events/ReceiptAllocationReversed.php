<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Domain\Events;

use Carbon\CarbonImmutable;

/** An allocation's money did not arrive after all (e.g. a bounced cheque); commission earned on it is clawed back. Dispatched inside the transaction. */
final readonly class ReceiptAllocationReversed
{
    public function __construct(
        public string $receiptAllocationId,
        public string $policyId,
        public CarbonImmutable $reversedOn,
    ) {}
}
