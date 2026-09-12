<?php

declare(strict_types=1);

namespace App\Modules\Platform\Numbering;

use Carbon\CarbonImmutable;

/**
 * Which sequence a document number comes from (design §2.1): entity, optional branch (null = entity-level),
 * document type and the fiscal year of the business date. The prefix is used when the sequence is first created.
 */
final readonly class DocumentNumberScope
{
    public function __construct(
        public string $entityId,
        public ?string $branchId,
        public string $docType,
        public string $prefix,
        public CarbonImmutable $businessDate,
    ) {}
}
