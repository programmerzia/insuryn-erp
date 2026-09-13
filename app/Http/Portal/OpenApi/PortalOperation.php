<?php

declare(strict_types=1);

namespace App\Http\Portal\OpenApi;

use Attribute;

/**
 * OpenAPI description of a portal endpoint, next to the code that serves it (slice D9). `response` and `request` name schemas in PortalSchemas;
 * `query` lists query parameters as name => [type, description].
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class PortalOperation
{
    /**
     * @param array<string, array{0: string, 1: string}> $query
     * @param list<int> $errors extra response codes the endpoint returns
     */
    public function __construct(
        public string $summary,
        public string $response,
        public ?string $request = null,
        public array $query = [],
        public int $status = 200,
        public ?string $ability = 'portal:read',
        public array $errors = [],
        public bool $public = false,
    ) {}
}
