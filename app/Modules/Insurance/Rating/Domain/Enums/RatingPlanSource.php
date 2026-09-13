<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Enums;

/** Where a rating plan's rates come from (Phase 3 design §1): the regulator's tariff or the company's own. */
enum RatingPlanSource: string
{
    case IdraTariff = 'idra_tariff';
    case Company = 'company';
}
