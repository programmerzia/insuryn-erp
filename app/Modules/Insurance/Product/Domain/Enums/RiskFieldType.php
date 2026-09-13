<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Domain\Enums;

/** Phase 3 design §1 product_versions.risk_schema: the kinds of risk detail an officer captures. Money is integer minor units. */
enum RiskFieldType: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Money = 'money';
    case Date = 'date';
    case Select = 'select';
    case Boolean = 'boolean';

    public function isNumeric(): bool
    {
        return $this === self::Integer || $this === self::Money;
    }
}
