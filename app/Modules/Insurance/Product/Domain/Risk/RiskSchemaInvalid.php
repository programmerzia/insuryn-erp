<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Domain\Risk;

use App\Modules\Platform\Exceptions\BusinessRuleViolation;

/** A risk schema definition that is not well formed (reason RISK_SCHEMA_INVALID); the message names the first problem. */
final class RiskSchemaInvalid extends BusinessRuleViolation
{
    public function __construct(string $message)
    {
        parent::__construct('RISK_SCHEMA_INVALID', $message);
    }
}
