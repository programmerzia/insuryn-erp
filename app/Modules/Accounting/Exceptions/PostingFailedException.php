<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

/** Reason codes: NO_RULE, AMBIGUOUS_RULE, PERIOD_MISSING, PERIOD_CLOSED, PERIOD_SOFT_LOCKED, UNMAPPED_ROLE, DIMENSION_MISSING, ... */
final class PostingFailedException extends AccountingException {}
