<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

/**
 * Reason codes: TOO_FEW_LINES, UNKNOWN_ACCOUNT, ACCOUNT_NOT_POSTABLE, AMOUNT_NOT_POSITIVE, INVALID_KIND,
 * CONTROL_ACCOUNT, INVALID_STATUS, NOT_MAKER, REASON_REQUIRED.
 */
final class ManualJournalException extends AccountingException {}
