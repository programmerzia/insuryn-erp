<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use RuntimeException;

final class PeriodClosedException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reasonCode);
    }
}
