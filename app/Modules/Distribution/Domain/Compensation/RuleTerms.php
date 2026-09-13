<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Compensation;

/** A compensation rule in force on the trigger date (design note §1 compensation_rules). Null product, type or level = any. */
final readonly class RuleTerms
{
    public function __construct(
        public ?string $id,
        public ?string $productId,
        public ?string $producerType,
        public ?string $levelCode,
        public string $basis,
        public int $policyYearFrom,
        public int $policyYearTo,
        public int $rateBp,
        public int $overrideRateBp,
        public ?int $capBp,
        public ?int $minPersistencyBp,
        public bool $renewalRequiresValidLicence,
        public bool $paysAfterTermination,
    ) {}

    public function covers(Trigger $trigger): bool
    {
        return $this->basis === $trigger->basis && $trigger->policyYear >= $this->policyYearFrom && $trigger->policyYear <= $this->policyYearTo
            && ($this->productId === null || $this->productId === $trigger->productId);
    }

    /** @param int $rateBp the direct or override rate this rule pays, limited by its own cap */
    public function capped(int $rateBp): int
    {
        return $this->capBp === null ? $rateBp : min($rateBp, $this->capBp);
    }
}
