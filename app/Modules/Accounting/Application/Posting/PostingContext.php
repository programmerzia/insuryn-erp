<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Posting;

use App\Modules\Accounting\Domain\Models\FiscalPeriod;

/** Design §3.1 "Posting Context": what one book needs resolved before a draft can be built and written. */
final readonly class PostingContext
{
    /** @param array<string, string> $accountsByRole role code → account id, effective on the posting date */
    public function __construct(
        public FiscalPeriod $period,
        public array $accountsByRole,
    ) {}
}
