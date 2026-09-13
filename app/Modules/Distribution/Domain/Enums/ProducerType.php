<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Enums;

/** Distribution design note §1 producers.type. */
enum ProducerType: string
{
    case Agent = 'agent';
    case AgencyOrg = 'agency_org';
    case Bdo = 'bdo';
    case Broker = 'broker';
    case Partner = 'partner';

    /** The channel a producer of this type joins when none is chosen. */
    public function defaultChannel(): ChannelType
    {
        return match ($this) {
            self::Agent, self::AgencyOrg => ChannelType::Agency,
            self::Bdo => ChannelType::Bdo,
            self::Broker => ChannelType::Broker,
            self::Partner => ChannelType::Partner,
        };
    }
}
