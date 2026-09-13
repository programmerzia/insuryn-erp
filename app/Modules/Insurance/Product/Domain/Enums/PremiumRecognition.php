<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Domain\Enums;

/**
 * Phase 3 design §2 step 3, product flag `recognise_at`. OPEN 3 (is premium recognised when a cover note is issued?) is unanswered:
 * ASSUMPTION A-65 — default `policy`. Stored now; the quotation flow (R5–R7) reads it.
 */
enum PremiumRecognition: string
{
    case Policy = 'policy';
    case CoverNote = 'cover_note';
}
