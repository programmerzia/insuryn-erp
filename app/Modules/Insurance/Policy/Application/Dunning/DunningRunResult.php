<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application\Dunning;

final readonly class DunningRunResult
{
    public function __construct(
        public int $noticesIssued,
        public int $policiesLapsed,
    ) {}
}
