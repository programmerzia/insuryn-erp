<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Quotation\Application;

use Carbon\CarbonImmutable;

/**
 * What an officer captures on the quote workbench (Phase 3 design §2 step 1): branch, product, optional customer and producer, the proposed
 * cover start, the risk inputs (field key → value as typed: integers and money in minor units as ints or digit strings) and the optional coverages.
 */
final readonly class QuotationTerms
{
    /**
     * @param array<string, mixed> $riskInputs
     * @param list<string> $coverages
     */
    public function __construct(
        public string $branchId,
        public string $productId,
        public ?string $customerPartyId,
        public ?string $producerId,
        public CarbonImmutable $inception,
        public array $riskInputs,
        public array $coverages = [],
    ) {}
}
