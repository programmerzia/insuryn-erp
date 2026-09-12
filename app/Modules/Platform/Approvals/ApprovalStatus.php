<?php

declare(strict_types=1);

namespace App\Modules\Platform\Approvals;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
