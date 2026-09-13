<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Underwriting\Application;

use App\Modules\Insurance\Rating\Domain\RatingResult;
use Carbon\CarbonImmutable;

/**
 * What R7 needs to issue a policy from an approved proposal (Phase 3 design §2 step 4): the parties, product version, cover start, risk and the rating result
 * to freeze on the policy (including any manual loading, shown on the schedule as "special terms" with its reason).
 */
final readonly class ApprovedProposal
{
    /** @param array<string, int|string|bool|null> $riskInputs */
    public function __construct(
        public string $proposalId,
        public string $number,
        public string $quotationId,
        public string $entityId,
        public string $branchId,
        public string $productId,
        public string $productVersionId,
        public string $classCode,
        public string $customerPartyId,
        public ?string $producerId,
        public CarbonImmutable $inception,
        public string $currency,
        public array $riskInputs,
        public int $sumInsuredMinor,
        public RatingResult $ratingResult,
        public ?int $manualLoadingBp,
        public ?string $specialTerms,
        public ?string $activeCoverNoteId,
    ) {}
}
