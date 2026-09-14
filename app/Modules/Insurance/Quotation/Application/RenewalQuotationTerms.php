<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Quotation\Application;

use Carbon\CarbonImmutable;

/**
 * A renewal quotation for an expiring policy (Phase 3 design §4, slice R9): the policy's branch, product, customer and producer, cover from the day after
 * expiry, the risk in force (claim-free years moved by the claims record) and its optional coverages, valid until `validUntil` (the renew-by date, A-127).
 */
final readonly class RenewalQuotationTerms
{
    /**
     * @param array<string, mixed> $riskInputs
     * @param list<string> $coverages
     */
    public function __construct(
        public string $renewalOfPolicyId,
        public string $branchId,
        public string $productId,
        public string $customerPartyId,
        public ?string $producerId,
        public CarbonImmutable $inception,
        public array $riskInputs,
        public array $coverages,
        public CarbonImmutable $validUntil,
    ) {}
}
