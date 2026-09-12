<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A business failure of the accounting kernel (design §8.4): the event is marked failed with
 * `reasonCode` and is never retried. Anything that is not an AccountingException is either a
 * transient database error (retried) or an unexpected error (dead-lettered).
 */
abstract class AccountingException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message = '', ?Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : $reasonCode, 0, $previous);
    }
}
