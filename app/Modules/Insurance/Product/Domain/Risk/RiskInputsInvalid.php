<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Domain\Risk;

use App\Modules\Platform\Exceptions\BusinessRuleViolation;

/**
 * Risk inputs that do not satisfy the product version's risk schema (reason RISK_INPUTS_INVALID). `errors` maps each field key to its
 * problem: REQUIRED, UNKNOWN_FIELD, NOT_TEXT, TOO_LONG, NOT_INTEGER, BELOW_MIN, ABOVE_MAX, NOT_A_DATE, NOT_AN_OPTION, NOT_BOOLEAN.
 */
final class RiskInputsInvalid extends BusinessRuleViolation
{
    /** @param array<string, string> $errors field key → problem code */
    public function __construct(public readonly array $errors)
    {
        $described = implode(', ', array_map(fn (string $field, string $problem): string => "{$field}: {$problem}", array_keys($errors), $errors));
        parent::__construct('RISK_INPUTS_INVALID', "The risk details are not valid ({$described}).");
    }
}
