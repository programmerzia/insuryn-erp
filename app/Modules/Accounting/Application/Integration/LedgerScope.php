<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Integration;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Default entity and primary book for ledger API reads (single-entity MVP, same as ReportingScope). */
final readonly class LedgerScope
{
    private function __construct(
        public string $entityId,
        public string $entityCode,
        public string $bookId,
        public string $currency,
    ) {}

    public static function resolve(): self
    {
        $entity = DB::table('legal_entities')->orderBy('code')->first(['id', 'code', 'base_currency']);
        $bookId = DB::table('books')->where('is_primary', true)->value('id');
        if ($entity === null || ! is_string($bookId)) {
            throw new NotFoundHttpException('No legal entity or primary book is set up for this tenant.');
        }

        return new self((string) $entity->id, (string) $entity->code, $bookId, (string) $entity->base_currency);
    }
}
