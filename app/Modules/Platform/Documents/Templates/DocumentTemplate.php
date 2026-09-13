<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Templates;

use Carbon\CarbonImmutable;

/** One version of a document template (table document_templates). */
final readonly class DocumentTemplate
{
    public function __construct(
        public string $id,
        public DocumentTemplateCode $code,
        public ?string $productClass,
        public int $version,
        public string $locale,
        public string $body,
        public ?string $letterhead,
        public DocumentTemplateStatus $status,
        public ?string $createdBy,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
        public ?string $activatedBy,
        public ?CarbonImmutable $activatedAt,
        public ?CarbonImmutable $retiredAt,
    ) {}

    public static function fromRow(\stdClass $row): self
    {
        $date = fn (mixed $value): ?CarbonImmutable => $value === null ? null : CarbonImmutable::parse((string) $value);

        return new self((string) $row->id, DocumentTemplateCode::from((string) $row->code), $row->product_class === null ? null : (string) $row->product_class,
            (int) $row->version, (string) $row->locale, (string) $row->body, $row->letterhead === null ? null : (string) $row->letterhead,
            DocumentTemplateStatus::from((string) $row->status), $row->created_by === null ? null : (string) $row->created_by,
            CarbonImmutable::parse((string) $row->created_at), CarbonImmutable::parse((string) $row->updated_at),
            $row->activated_by === null ? null : (string) $row->activated_by, $date($row->activated_at), $date($row->retired_at));
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentTemplateStatus::Draft;
    }
}
