<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Domain\Enums;

/** Phase 3 design §1 coverages.basis: what a coverage's premium is measured on. */
enum CoverageBasis: string
{
    case SumInsured = 'sum_insured';
    case Flat = 'flat';
    case PerUnit = 'per_unit';
    case PctOfBase = 'pct_of_base';
}
