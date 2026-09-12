<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Domain\Enums;

enum PartyKind: string
{
    case Individual = 'individual';
    case Organization = 'organization';
}
