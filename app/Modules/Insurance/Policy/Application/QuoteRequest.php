<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application;

use Carbon\CarbonImmutable;

/**
 * A new quote. `premiumMinor` is the premium as charged under the product's tax profile: including tax when the
 * profile is inclusive (design §4.1 "gross incl. VAT"), before tax otherwise.
 */
final readonly class QuoteRequest
{
    public function __construct(
        public string $entityId,
        public string $branchId,
        public string $productId,
        public string $policyholderPartyId,
        public ?string $agentId,
        public CarbonImmutable $inception,
        public int $premiumMinor,
        public string $currency,
        public int $installmentCount = 1,
        /** @var list<PayerShare> payers and shares (spec §4 multi-payer); empty = the policyholder pays 100% */
        public array $payers = [],
    ) {}
}
