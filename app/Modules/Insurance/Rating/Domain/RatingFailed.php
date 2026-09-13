<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain;

use App\Modules\Platform\Exceptions\BusinessRuleViolation;

/**
 * Rating could not produce a premium, with a clear reason: RATE_NOT_FOUND (a lookup found no row — names the table and keys), RATE_AMBIGUOUS,
 * BAND_NOT_FOUND, RATE_TABLE_UNKNOWN, RATE_TABLE_TYPE (a function used on the wrong kind of table), RATING_EXPRESSION_INVALID (syntax, a forbidden
 * operator such as `/`, a float, an unknown function or variable), RATING_EXPRESSION_NOT_INTEGER, RATING_CONDITION_NOT_BOOLEAN, RISK_INPUT_MISSING,
 * RATING_DIVISION_BY_ZERO, RATING_OVERFLOW, DUTY_NOT_FOUND, RATING_PLAN_NOT_FOUND, RATING_PLAN_INVALID, COVERAGE_UNKNOWN.
 */
final class RatingFailed extends BusinessRuleViolation {}
