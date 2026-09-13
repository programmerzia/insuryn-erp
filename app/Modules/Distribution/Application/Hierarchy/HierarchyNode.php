<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Hierarchy;

/** One producer in a hierarchy snapshot: depth 0 is the producer asked about, 1 its parent, and so on up to the root. */
final readonly class HierarchyNode
{
    public function __construct(
        public string $producerId,
        public string $code,
        public ?string $levelCode,
        public int $depth,
    ) {}

    /** @return array{producer_id: string, code: string, level_code: string|null, depth: int} the stored form (commission entries' hierarchy_snapshot) */
    public function toArray(): array
    {
        return ['producer_id' => $this->producerId, 'code' => $this->code, 'level_code' => $this->levelCode, 'depth' => $this->depth];
    }
}
