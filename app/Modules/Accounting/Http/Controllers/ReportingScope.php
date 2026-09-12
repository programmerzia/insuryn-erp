<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Which entity and book a read-only accounting page shows. Design §9.2 MVP: single-entity UI over a
 * multi-entity model, LOCAL (primary) book only — `?entity_id=` selects another entity of the tenant.
 */
final readonly class ReportingScope
{
    private function __construct(
        public string $entityId,
        public string $entityCode,
        public string $entityName,
        public string $bookId,
        public string $currency,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $requested = $request->query('entity_id');
        $entity = DB::table('legal_entities')
            ->when(is_string($requested) && Str::isUuid($requested), fn ($q) => $q->where('id', $requested))
            ->orderBy('code')->first(['id', 'code', 'name', 'base_currency']);
        $bookId = DB::table('books')->where('is_primary', true)->value('id');
        if ($entity === null || ! is_string($bookId)) {
            throw new NotFoundHttpException('No legal entity or primary book is set up for this tenant.');
        }

        return new self((string) $entity->id, (string) $entity->code, (string) $entity->name, $bookId, (string) $entity->base_currency);
    }

    /** @return array{id: string, code: string, name: string, currency: string} */
    public function entityProps(): array
    {
        return ['id' => $this->entityId, 'code' => $this->entityCode, 'name' => $this->entityName, 'currency' => $this->currency];
    }
}
