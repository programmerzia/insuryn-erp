<?php

declare(strict_types=1);

namespace App\Modules\Platform\Numbering\Exceptions;

use RuntimeException;

final class NumberingException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
