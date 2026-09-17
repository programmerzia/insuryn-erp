<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Integration;

use Illuminate\Validation\ValidationException;

/**
 * A ledger API request refused before anything is written (422). Carries the machine-readable `reason` next to the per-field
 * message, so the JSON body is `{message, reason, errors: {field: [message]}}` (bootstrap/app.php).
 *
 * Reasons: UNKNOWN_EVENT_TYPE, NO_RULE, AMBIGUOUS_RULE, DIMENSION_MISSING, UNKNOWN_BRANCH, UNKNOWN_PRODUCT, PAYLOAD_INVALID,
 * PERIOD_MISSING, PERIOD_CLOSED, PERIOD_SOFT_LOCKED, CURRENCY_MISMATCH.
 */
final class LedgerRequestRejected extends ValidationException
{
    public string $reason = 'VALIDATION_FAILED';

    public static function field(string $field, string $message, string $reason): self
    {
        /** @var self $e */
        $e = self::withMessages([$field => [$message]]);
        $e->reason = $reason;

        return $e;
    }
}
