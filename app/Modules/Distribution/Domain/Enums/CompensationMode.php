<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Enums;

/** Distribution design note §0: how producers under a scheme are paid. Life agencies earn commission; salaried BDOs earn incentives. */
enum CompensationMode: string
{
    case Commission = 'commission';
    case SalaryIncentive = 'salary_incentive';
    case Hybrid = 'hybrid';
    case None = 'none';

    public function paysCommission(): bool
    {
        return $this === self::Commission || $this === self::Hybrid;
    }
}
