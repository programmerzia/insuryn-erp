<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Compensation;

use App\Modules\Distribution\Domain\ComplianceProfile;

/** The scheme a calculation runs under: its mode, compliance profile and withholding rate on the day (basis points). */
final readonly class SchemeTerms
{
    public function __construct(
        public string $mode,
        public ComplianceProfile $profile,
        public int $withholdingBp,
    ) {}

    public function paysCommission(): bool
    {
        return in_array($this->mode, ['commission', 'hybrid'], true);
    }
}
