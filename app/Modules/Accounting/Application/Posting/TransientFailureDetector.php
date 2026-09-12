<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Posting;

use Illuminate\Contracts\Database\LostConnectionDetector;
use PDOException;
use Throwable;

/**
 * Decides whether a posting error is worth retrying (design §8.4): a retry can only help when the
 * database refused to cooperate, never when the event or the configuration is wrong.
 */
final class TransientFailureDetector
{
    /** Postgres SQLSTATEs: serialization_failure, deadlock_detected, lock_not_available. */
    private const RETRYABLE_SQLSTATES = ['40001', '40P01', '55P03'];

    public function __construct(private readonly LostConnectionDetector $lostConnections) {}

    public function isTransient(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($this->hasRetryableSqlState($cause) || $this->lostConnections->causedByLostConnection($cause)) {
                return true;
            }
        }

        return false;
    }

    /** Laravel's QueryException extends PDOException and copies the driver's errorInfo. */
    private function hasRetryableSqlState(Throwable $e): bool
    {
        if (! $e instanceof PDOException) {
            return false;
        }
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());

        return in_array($sqlState, self::RETRYABLE_SQLSTATES, true);
    }
}
