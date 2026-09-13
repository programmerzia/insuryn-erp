<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application;

use Carbon\CarbonImmutable;

final readonly class CreateProducer
{
    public function __construct(
        public string $partyId,
        public string $code,
        public string $type,
        public string $branchId,
        public ?string $channelId = null,
        public ?string $parentProducerId = null,
        public ?string $commissionPlanId = null,
        public ?string $employeeId = null,
        public ?CarbonImmutable $joinedOn = null,
        public string $status = 'active',
    ) {}
}
