<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Queries;

use Carbon\CarbonImmutable;

final readonly class FiscalPeriodView
{
    public function __construct(
        public string $id,
        public string $entityId,
        public string $bookId,
        public int $year,
        public int $period,
        public CarbonImmutable $starts,
        public CarbonImmutable $ends,
        public string $status,
    ) {}

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
