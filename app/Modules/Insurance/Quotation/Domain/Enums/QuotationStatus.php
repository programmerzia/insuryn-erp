<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Quotation\Domain\Enums;

/** Phase 3 design §2 quotation status: draft → issued → converted | expired | declined; a draft may be declined too. */
enum QuotationStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Expired = 'expired';
    case Converted = 'converted';
    case Declined = 'declined';
}
