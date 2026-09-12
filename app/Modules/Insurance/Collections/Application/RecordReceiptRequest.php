<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use Carbon\CarbonImmutable;

/** Money received (design §2.4 receipts). Whatever the allocations leave over goes to suspense (§4.9). */
final readonly class RecordReceiptRequest
{
    /** @param list<AllocationLine> $allocations */
    public function __construct(
        public string $entityId,
        public string $branchId,
        public ?string $partyId,
        public string $channel,
        public int $amountMinor,
        public string $currency,
        public CarbonImmutable $valueDate,
        public ?string $bankAccountId,
        public ?string $reference,
        public array $allocations,
    ) {}

    public function allocatedMinor(): int
    {
        return array_sum(array_map(fn (AllocationLine $line): int => $line->amountMinor, $this->allocations));
    }
}
