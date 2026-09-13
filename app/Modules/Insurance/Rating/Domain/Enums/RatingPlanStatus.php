<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Enums;

/**
 * Rating plan lifecycle (Phase 3 design §1, DECISION D-21): draft → approved (maker ≠ checker) → active → retired. Only a draft can be edited;
 * approved, active and retired plans are immutable (service guard and database trigger). An approved plan can also be retired unused.
 */
enum RatingPlanStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Active = 'active';
    case Retired = 'retired';
}
