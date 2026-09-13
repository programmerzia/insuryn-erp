<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents;

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents attached to business objects (fix F2; market cross-check G2). Files live on the private disk `erp.documents.disk` at
 * `<tenant>/<first two hash characters>/<sha256>`: the same bytes are stored once and a stored file is never written again. The row in
 * stored_documents is append-only (database trigger) and names the object by its audit subject type, so `document.attached` and
 * `document.downloaded` land in the object's own audit trail.
 *
 * Platform does not know claims, receipts or policies: the business controller authorizes access to the object before calling this.
 */
final class DocumentStore
{
    /** Content types sent on download, by extension; anything else is served as a plain download. */
    private const MIME_BY_EXTENSION = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const DESCRIPTION_MAX = 500;

    public function __construct(private readonly Audit $audit) {}

    /** ASSUMPTION: A-52 — upload limit in kilobytes (config `erp.documents.max_upload_kb`). */
    public static function maxUploadKb(): int
    {
        return max(1, (int) config('erp.documents.max_upload_kb', 10240));
    }

    /**
     * ASSUMPTION: A-52 — accepted file extensions (config `erp.documents.allowed_extensions`).
     *
     * @return list<string>
     */
    public static function allowedExtensions(): array
    {
        $configured = config('erp.documents.allowed_extensions', []);

        return array_values(array_map(fn (mixed $extension): string => strtolower((string) $extension), is_array($configured) ? $configured : []));
    }

    /**
     * Laravel validation rules for an upload field, matching what attach() accepts.
     *
     * @return list<string>
     */
    public static function uploadRules(): array
    {
        return ['required', 'file', 'max:'.self::maxUploadKb(), 'extensions:'.implode(',', self::allowedExtensions())];
    }

    /** @throws BusinessRuleViolation DOCUMENT_TYPE_NOT_ALLOWED, DOCUMENT_TOO_LARGE, DOCUMENT_EMPTY, DOCUMENT_DESCRIPTION_TOO_LONG */
    public function attach(string $objectType, string $objectId, UploadedFile|DocumentContents $file, ?string $actorUserId, ?string $description = null): StoredDocument
    {
        self::assertSubject($objectType, $objectId);
        $name = self::cleanName($file instanceof UploadedFile ? $file->getClientOriginalName() : $file->name);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($extension, self::allowedExtensions(), true)) {
            throw new BusinessRuleViolation('DOCUMENT_TYPE_NOT_ALLOWED', 'Attach a PDF, a JPG or PNG image, or a Word or Excel file ('.implode(', ', self::allowedExtensions()).').');
        }
        // An upload is read from its temporary file (hashed and copied as a stream); given contents are used as they are.
        $contents = $file instanceof DocumentContents ? $file->contents : null;
        $source = null;
        if ($file instanceof UploadedFile) {
            $source = $file->getRealPath();
            if ($source === false) {
                throw new InvalidArgumentException('The uploaded file cannot be read.');
            }
        }
        $size = $source === null ? strlen((string) $contents) : (int) filesize($source);
        if ($size === 0) {
            throw new BusinessRuleViolation('DOCUMENT_EMPTY', 'The file is empty. Choose the document again.');
        }
        if ($size > self::maxUploadKb() * 1024) {
            throw new BusinessRuleViolation('DOCUMENT_TOO_LARGE', 'The document is larger than '.self::readableLimit().'. Attach a smaller file.');
        }
        $description = $description === null || trim($description) === '' ? null : trim($description);
        if ($description !== null && mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new BusinessRuleViolation('DOCUMENT_DESCRIPTION_TOO_LONG', 'Keep the description to '.self::DESCRIPTION_MAX.' characters.');
        }

        $sha256 = $source === null ? hash('sha256', (string) $contents) : (string) hash_file('sha256', $source);
        $diskName = self::diskName();
        $path = TenantContext::id().'/'.substr($sha256, 0, 2).'/'.$sha256;
        $disk = Storage::disk($diskName);
        if (! $disk->exists($path)) {
            if ($source === null) {
                $disk->put($path, (string) $contents);
            } else {
                $stream = fopen($source, 'rb');
                if ($stream === false) {
                    throw new InvalidArgumentException('The uploaded file cannot be read.');
                }
                try {
                    $disk->writeStream($path, $stream);
                } finally {
                    fclose($stream);
                }
            }
        }

        $row = ['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'object_type' => $objectType, 'object_id' => $objectId, 'original_name' => $name,
            'mime' => self::MIME_BY_EXTENSION[$extension] ?? 'application/octet-stream', 'size_bytes' => $size, 'sha256' => $sha256, 'disk' => $diskName, 'storage_path' => $path,
            'description' => $description, 'uploaded_by' => $actorUserId, 'uploaded_at' => CarbonImmutable::now()];
        DB::transaction(function () use ($row, $objectType, $objectId, $actorUserId): void {
            DB::table('stored_documents')->insert($row);
            $this->audit->record('document.attached', AuditSubject::of($objectType, $objectId), null,
                ['document_id' => $row['id'], 'name' => $row['original_name'], 'size_bytes' => $row['size_bytes'], 'sha256' => $row['sha256'], 'description' => $row['description']],
                actor: $actorUserId === null ? Actor::system() : Actor::user($actorUserId));
        });

        return StoredDocument::fromRow((object) ($row + ['uploaded_by_name' => null]));
    }

    /** @return list<StoredDocument> the object's documents, newest first */
    public function list(string $objectType, string $objectId): array
    {
        self::assertSubject($objectType, $objectId);

        return array_values($this->query()->where('d.object_type', $objectType)->where('d.object_id', $objectId)->orderByDesc('d.uploaded_at')->orderByDesc('d.id')->get()
            ->map(fn (\stdClass $row): StoredDocument => StoredDocument::fromRow($row))->all());
    }

    /** The document when it is attached to that object in the current tenant, otherwise null. */
    public function find(string $documentId, string $objectType, string $objectId): ?StoredDocument
    {
        if (! Str::isUuid($documentId)) {
            return null;
        }
        $row = $this->query()->where('d.id', $documentId)->where('d.object_type', $objectType)->where('d.object_id', $objectId)->first();

        return $row instanceof \stdClass ? StoredDocument::fromRow($row) : null;
    }

    /**
     * Streams the file under its original name as an attachment and audits the download.
     *
     * @throws BusinessRuleViolation DOCUMENT_FILE_MISSING when the stored file is gone
     */
    public function download(StoredDocument $document, string $actorUserId): StreamedResponse
    {
        $disk = Storage::disk($document->disk);
        if (! $disk->exists($document->storagePath)) {
            throw new BusinessRuleViolation('DOCUMENT_FILE_MISSING', 'The file of this document is missing from the document store. Ask an administrator to restore it from backup.');
        }
        $this->audit->record('document.downloaded', AuditSubject::of($document->objectType, $document->objectId), null,
            ['document_id' => $document->id, 'name' => $document->originalName], actor: Actor::user($actorUserId));

        return $disk->download($document->storagePath, $document->originalName, ['Content-Type' => $document->mime, 'X-Content-Type-Options' => 'nosniff']);
    }

    private function query(): \Illuminate\Database\Query\Builder
    {
        return DB::table('stored_documents as d')->leftJoin('users as u', 'u.id', '=', 'd.uploaded_by')->select(['d.*', 'u.name as uploaded_by_name']);
    }

    private static function diskName(): string
    {
        return (string) config('erp.documents.disk', 'documents');
    }

    private static function readableLimit(): string
    {
        $kb = self::maxUploadKb();

        return $kb % 1024 === 0 ? intdiv($kb, 1024).' MB' : $kb.' KB';
    }

    /** The file name without folders or control characters, at most 255 characters. */
    private static function cleanName(string $name): string
    {
        $base = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $name))));

        return mb_substr($base, -255);
    }

    private static function assertSubject(string $objectType, string $objectId): void
    {
        if (preg_match('/^[a-z][a-z_]{0,39}$/', $objectType) !== 1 || ! Str::isUuid($objectId)) {
            throw new InvalidArgumentException("Invalid document subject {$objectType} {$objectId}.");
        }
    }
}
