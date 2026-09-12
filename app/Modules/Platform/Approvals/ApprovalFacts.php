<?php

declare(strict_types=1);

namespace App\Modules\Platform\Approvals;

/** What an approval policy condition is evaluated against (design §2.1 approval_policies.condition). */
final readonly class ApprovalFacts
{
    /** @param array<string, string> $attributes e.g. ['kind' => 'manual'] */
    public function __construct(
        public int $amountMinor,
        public array $attributes = [],
    ) {}
}
