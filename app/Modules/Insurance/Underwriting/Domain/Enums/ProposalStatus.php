<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Underwriting\Domain\Enums;

/** Proposal status (slice R5): draft → submitted (referred to underwriting) → approved | declined; approved → issued when the policy is issued (R7). An auto-approved proposal goes straight to approved. */
enum ProposalStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Declined = 'declined';
    case Issued = 'issued';
}
