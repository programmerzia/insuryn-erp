<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Compensation;

use Carbon\CarbonImmutable;

/**
 * What the policy side asks the compensation engine (design note §2): the seller, the premium (basis and amount), the policy year, the product and
 * its class, the day, and either the product version's scheme or Phase 1 flat plan terms. `source` identifies the trigger for compliance exceptions.
 */
final readonly class CompensationRequest
{
    public function __construct(
        public string $policyId,
        public string $productId,
        public string $productClass,
        public string $sellerProducerId,
        public string $basis,
        public int $baseMinor,
        public int $policyYear,
        public CarbonImmutable $on,
        public string $sourceType,
        public string $sourceId,
        public ?string $schemeId = null,
        public ?FlatCommissionTerms $flatTerms = null,
    ) {}
}
