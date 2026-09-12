<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

/** Debits and credits differ (design §3.3 step f). Reason code UNBALANCED; the journal is never persisted as posted. */
final class UnbalancedJournalException extends AccountingException {}
