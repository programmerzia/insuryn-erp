<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Posting;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * D-10: the kernel's triggers (migration 2026_09_12_000003) reject invalid postings with
 * `RAISE EXCEPTION '<CODE>: …' USING ERRCODE = 'check_violation'`. Such a rejection is a business
 * failure of the event with that reason code, not an unexpected error.
 */
final class DatabaseRuleViolation
{
    private const CHECK_VIOLATION = '23514';

    private const REASON_CODES = ['PERIOD_CLOSED', 'PERIOD_MISSING', 'UNBALANCED_JOURNAL', 'EMPTY_JOURNAL', 'IMMUTABLE_JOURNAL'];

    /** The kernel reason code carried by $error, or null when it is not a kernel rule violation. */
    public static function reasonCode(Throwable $error): ?string
    {
        if (! $error instanceof QueryException || ($error->errorInfo[0] ?? null) !== self::CHECK_VIOLATION) {
            return null;
        }
        foreach (self::REASON_CODES as $code) {
            if (str_contains($error->getMessage(), $code.':')) {
                return $code;
            }
        }

        return null;
    }
}
