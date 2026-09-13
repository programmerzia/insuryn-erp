<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Enums;

/** Distribution design note §3 lifecycle: applicant → active → suspended → terminated. */
enum ProducerStatus: string
{
    case Applicant = 'applicant';
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';
}
