<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain;

use App\Modules\Insurance\Product\Domain\DutyProfile;
use App\Modules\Insurance\Product\Domain\Risk\RiskSchema;
use App\Modules\Insurance\Rating\Domain\Definition\DutyDefinition;
use App\Modules\Insurance\Rating\Domain\Definition\RatingPlanDefinition;

/**
 * Everything rating needs, already loaded (the calculator reads nothing else): the plan, the product version's risk schema, coverages, duty profile
 * and minimum premium, the duties on the books, the raw risk inputs, the optional coverages chosen and the rating date (Y-m-d).
 */
final readonly class RatingRequest
{
    /**
     * @param array<mixed> $riskInputs
     * @param list<array{code: string, name_en: string, name_bn: string, mandatory: bool}> $coverages the product version's coverages, in display order
     * @param list<string> $chosenCoverages optional coverages asked for (mandatory ones are always rated)
     * @param list<DutyDefinition> $duties duties on the books (the calculator keeps those in force for the class on the day)
     */
    public function __construct(
        public RatingPlanDefinition $plan,
        public RiskSchema $schema,
        public array $riskInputs,
        public string $asOf,
        public array $coverages = [],
        public array $chosenCoverages = [],
        public array $duties = [],
        public ?DutyProfile $dutyProfile = null,
        public ?int $productMinimumMinor = null,
        public ?string $productVersionId = null,
    ) {}
}
