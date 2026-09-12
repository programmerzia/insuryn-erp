<?php

declare(strict_types=1);

namespace App\Modules\Platform\Exceptions;

use RuntimeException;

/** A request that breaks a business rule of a non-accounting module; rendered as HTTP 422 with its reason code. */
class BusinessRuleViolation extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
