<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Templates;

/** A template version is edited while draft, used while active, and kept for the record once retired (a newer version was activated). */
enum DocumentTemplateStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Retired = 'retired';
}
