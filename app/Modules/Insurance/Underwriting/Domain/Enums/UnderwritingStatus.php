<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Underwriting\Domain\Enums;

/** Phase 3 design §2 proposals.underwriting_status. */
enum UnderwritingStatus: string
{
    case AutoApproved = 'auto_approved';
    case Referred = 'referred';
    case Approved = 'approved';
    case Declined = 'declined';
}
