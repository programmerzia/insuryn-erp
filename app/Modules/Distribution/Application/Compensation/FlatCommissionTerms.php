<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Compensation;

/**
 * A Phase 1 commission plan as compensation terms: one direct rate on premium received for every product, producer type and policy year, no
 * overrides or caps. ASSUMPTION A-20: plans predate compliance profiles; a tenant that attached a plan configured commission explicitly, so
 * non-life commission is allowed for plan terms. Producers still need to be active and licensed (design note §2 step 1).
 */
final readonly class FlatCommissionTerms
{
    public function __construct(
        public string $planId,
        public int $rateBp,
        public int $withholdingBp,
    ) {}
}
