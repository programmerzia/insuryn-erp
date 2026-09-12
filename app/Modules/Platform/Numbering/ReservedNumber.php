<?php

declare(strict_types=1);

namespace App\Modules\Platform\Numbering;

final readonly class ReservedNumber
{
    public function __construct(
        public string $id,
        public string $sequenceId,
        public string $number,
    ) {}
}
