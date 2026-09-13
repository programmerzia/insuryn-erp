<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Enums;

/** Distribution design note §1 channels.type: every way business reaches the insurer. */
enum ChannelType: string
{
    case Agency = 'agency';
    case Bdo = 'bdo';
    case Broker = 'broker';
    case Bancassurance = 'bancassurance';
    case Partner = 'partner';
    case Direct = 'direct';

    /** Code and name of the tenant's standard channel of this type, created on first use. */
    public function standardCode(): string
    {
        return strtoupper($this->value);
    }

    public function standardName(): string
    {
        return match ($this) {
            self::Agency => 'Agency',
            self::Bdo => 'Business development officers',
            self::Broker => 'Brokers',
            self::Bancassurance => 'Bancassurance',
            self::Partner => 'Partners',
            self::Direct => 'Direct',
        };
    }
}
