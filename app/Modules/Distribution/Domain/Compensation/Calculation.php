<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Compensation;

final readonly class Calculation
{
    /**
     * @param list<CommissionLine> $lines
     * @param list<ComplianceIssue> $issues
     */
    public function __construct(
        public array $lines,
        public array $issues,
    ) {}
}
