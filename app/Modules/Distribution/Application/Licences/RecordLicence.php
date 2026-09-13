<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Licences;

use Carbon\CarbonImmutable;

final readonly class RecordLicence
{
    public function __construct(
        public string $producerId,
        public string $licenceNo,
        public string $class,
        public CarbonImmutable $issuedOn,
        public CarbonImmutable $expiresOn,
        public string $authority = 'IDRA',
        public ?string $documentId = null,
    ) {}
}
