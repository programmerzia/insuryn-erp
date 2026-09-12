<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Enums;

enum Side: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
