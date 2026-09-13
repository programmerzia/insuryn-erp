<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Generation;

use App\Modules\Platform\Authorization\AuthorizationScope;

/**
 * Where a generated document belongs. `attachToType`/`attachToId` is the object page whose Documents tab lists the PDF (an endorsement's PDF
 * belongs to its policy); `number` is the business number printed and stored with it.
 */
final readonly class DocumentSubject
{
    public function __construct(
        public string $attachToType,
        public string $attachToId,
        public ?string $number,
        public ?string $productClass,
        public AuthorizationScope $scope,
    ) {}
}
