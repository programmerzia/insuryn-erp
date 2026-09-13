<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Generation;

use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use Carbon\CarbonImmutable;

/** A PDF produced from a template (table generated_documents), with the stored document that holds its bytes. */
final readonly class GeneratedDocument
{
    public function __construct(
        public string $id,
        public string $templateId,
        public DocumentTemplateCode $templateCode,
        public int $templateVersion,
        public string $locale,
        public string $objectType,
        public string $objectId,
        public ?string $number,
        public int $version,
        public string $storedDocumentId,
        public string $fileName,
        public string $sha256,
        public string $contentSha256,
        public int $sizeBytes,
        public CarbonImmutable $renderedAt,
        public ?string $renderedBy,
        public ?string $renderedByName,
    ) {}

    public static function fromRow(\stdClass $row): self
    {
        return new self((string) $row->id, (string) $row->template_id, DocumentTemplateCode::from((string) $row->template_code), (int) $row->template_version, (string) $row->locale,
            (string) $row->object_type, (string) $row->object_id, $row->number === null ? null : (string) $row->number, (int) $row->version, (string) $row->stored_document_id,
            (string) ($row->file_name ?? ''), (string) $row->sha256, (string) $row->content_sha256, (int) $row->size_bytes, CarbonImmutable::parse((string) $row->rendered_at),
            $row->rendered_by === null ? null : (string) $row->rendered_by, isset($row->rendered_by_name) ? (string) $row->rendered_by_name : null);
    }
}
