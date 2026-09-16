<?php

declare(strict_types=1);

namespace App\Http\Ledger\OpenApi;

use Attribute;

/** OpenAPI description of a ledger API endpoint (docs/plan/api-accounting-v1.md). */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class LedgerOperation
{
    /**
     * @param array<string, array{0: string, 1: string}> $query
     * @param list<int> $errors
     */
    public function __construct(
        public string $summary,
        public string $response,
        public ?string $request = null,
        public array $query = [],
        public int $status = 200,
        public ?string $ability = 'integration:events:read',
        public array $errors = [],
        public bool $public = false,
    ) {}
}
