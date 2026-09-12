<?php

declare(strict_types=1);

namespace App\Modules\Platform\Approvals;

enum Decision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
