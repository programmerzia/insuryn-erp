<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\ManualJournals;

use App\Modules\Accounting\Domain\Enums\Side;

/** One line of a manual journal: an account (not a role), a side and a positive minor-unit amount. */
final readonly class ManualJournalLine
{
    /** @param array<string, string> $dimensions dimension code → value (design §2.2 dim_* / dims_ext) */
    public function __construct(
        public string $accountId,
        public Side $side,
        public int $amountMinor,
        public array $dimensions = [],
        public ?string $memo = null,
    ) {}
}
