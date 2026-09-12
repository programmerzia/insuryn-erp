<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Domain\Enums;

/** Design §2.4 policy_transactions.type — the source of policy accounting events. */
enum PolicyTransactionType: string
{
    case New = 'new';
    case Endorsement = 'endorsement';
    case Cancellation = 'cancellation';
    case Renewal = 'renewal';
}
