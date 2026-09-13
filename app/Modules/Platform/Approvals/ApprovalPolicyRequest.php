<?php

declare(strict_types=1);

namespace App\Modules\Platform\Approvals;

use Carbon\CarbonImmutable;

/**
 * An approval limit as an administrator sets it (design §2.1 approval_policies, §7.3 "approval policies (amount thresholds) are data"): what it
 * approves, the amount band [at least, below) in minor units of the base currency (null = open), the roles that approve in order, and when
 * it is in force [from, to) — the same half-open dates the approval engine matches on.
 */
final readonly class ApprovalPolicyRequest
{
    /** @param list<string> $roles role codes, one per sequential step */
    public function __construct(
        public string $objectType,
        public ?int $minAmountMinor,
        public ?int $maxAmountMinor,
        public array $roles,
        public CarbonImmutable $effectiveFrom,
        public ?CarbonImmutable $effectiveTo = null,
    ) {}
}
