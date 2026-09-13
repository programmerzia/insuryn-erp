<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents;

use Carbon\CarbonImmutable;

/** A stored document (table stored_documents): what was attached to which object, by whom and when, and where its bytes live. */
final readonly class StoredDocument
{
    public function __construct(
        public string $id,
        public string $objectType,
        public string $objectId,
        public string $originalName,
        public string $mime,
        public int $sizeBytes,
        public string $sha256,
        public string $disk,
        public string $storagePath,
        public ?string $description,
        public ?string $uploadedBy,
        public ?string $uploadedByName,
        public CarbonImmutable $uploadedAt,
    ) {}

    public static function fromRow(\stdClass $row): self
    {
        return new self((string) $row->id, (string) $row->object_type, (string) $row->object_id, (string) $row->original_name, (string) $row->mime, (int) $row->size_bytes,
            (string) $row->sha256, (string) $row->disk, (string) $row->storage_path, $row->description === null ? null : (string) $row->description,
            $row->uploaded_by === null ? null : (string) $row->uploaded_by, isset($row->uploaded_by_name) ? (string) $row->uploaded_by_name : null,
            CarbonImmutable::parse((string) $row->uploaded_at));
    }
}
