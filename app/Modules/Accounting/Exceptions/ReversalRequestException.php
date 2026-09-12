<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

/** Reason codes: NOT_POSTED, REASON_REQUIRED, REQUEST_PENDING, NOT_PENDING. */
final class ReversalRequestException extends AccountingException {}
