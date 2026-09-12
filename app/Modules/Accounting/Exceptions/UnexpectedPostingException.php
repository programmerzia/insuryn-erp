<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Posting hit an error that is neither a business failure nor transient (a bug, bad data, a
 * violated DB constraint). The event has been marked failed; the job must dead-letter without
 * retrying (design §8.4). The cause is the previous exception.
 */
final class UnexpectedPostingException extends RuntimeException
{
    private function __construct(public readonly string $eventId, Throwable $cause)
    {
        parent::__construct("Posting event {$eventId} failed unexpectedly: ".$cause->getMessage(), 0, $cause);
    }

    public static function forEvent(string $eventId, Throwable $cause): self
    {
        return new self($eventId, $cause);
    }
}
