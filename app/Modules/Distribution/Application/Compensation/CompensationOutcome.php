<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Compensation;

/** The engine's answer: awards, the scheme and withholding rate used, and the hierarchy snapshot to store on every entry. */
final readonly class CompensationOutcome
{
    /**
     * @param list<CommissionAward> $awards
     * @param list<array{producer_id: string, code: string, level_code: string|null, depth: int}> $hierarchySnapshot
     */
    public function __construct(
        public array $awards,
        public ?string $schemeId,
        public ?string $planId,
        public int $withholdingBp,
        public array $hierarchySnapshot,
    ) {}

    public static function none(): self
    {
        return new self([], null, null, 0, []);
    }
}
