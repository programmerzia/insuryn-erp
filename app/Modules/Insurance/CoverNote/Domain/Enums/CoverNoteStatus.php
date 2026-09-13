<?php

declare(strict_types=1);

namespace App\Modules\Insurance\CoverNote\Domain\Enums;

/** Phase 3 design §2 cover note status: active → superseded (the policy is issued) | cancelled | expired; an expired note is still superseded by its policy. */
enum CoverNoteStatus: string
{
    case Active = 'active';
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
