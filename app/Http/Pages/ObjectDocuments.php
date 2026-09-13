<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Modules\Platform\Documents\DocumentStore;
use App\Modules\Platform\Documents\StoredDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Documents tab of an object page (fix F2): the upload form, the list and the download. The module controller authorizes the object and
 * finds it first; this only speaks HTTP for the documents service.
 */
final class ObjectDocuments
{
    public function __construct(private readonly DocumentStore $store) {}

    /** Validates the upload, stores it against the object and returns to the object's Documents tab. */
    public function attach(Request $request, string $objectType, string $objectId, string $pageUrl): RedirectResponse
    {
        $limit = DocumentStore::maxUploadKb();
        $request->validate(['file' => DocumentStore::uploadRules(), 'description' => ['nullable', 'string', 'max:500']], [
            'file.required' => 'Choose a file to attach.',
            'file.file' => 'Choose a file to attach.',
            'file.uploaded' => 'The file did not arrive. It may be larger than the server accepts; try a smaller file.',
            'file.max' => 'The document must be '.($limit % 1024 === 0 ? intdiv($limit, 1024).' MB' : "{$limit} KB").' or smaller.',
            'file.extensions' => 'Attach a PDF, a JPG or PNG image, or a Word or Excel file.',
            'description.max' => 'Keep the description to 500 characters.',
        ]);
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Choose one file to attach.']);
        }
        $description = $request->input('description');
        $document = $this->store->attach($objectType, $objectId, $file, PageSupport::actor($request), is_string($description) ? $description : null);

        return redirect("{$pageUrl}?tab=documents")->with('status', "Document {$document->originalName} attached.");
    }

    public function download(Request $request, string $objectType, string $objectId, string $documentId): StreamedResponse
    {
        $document = $this->store->find($documentId, $objectType, $objectId);
        abort_if($document === null, 404);

        return $this->store->download($document, PageSupport::actor($request));
    }

    /**
     * The object's documents for its page, newest first.
     *
     * @return list<array{id: string, name: string, mime: string, size_bytes: int, description: string|null, uploaded_by: string, uploaded_at: string, url: string}>
     */
    public function forPage(string $objectType, string $objectId, string $pageUrl): array
    {
        return array_map(fn (StoredDocument $d): array => ['id' => $d->id, 'name' => $d->originalName, 'mime' => $d->mime, 'size_bytes' => $d->sizeBytes, 'description' => $d->description,
            'uploaded_by' => $d->uploadedBy === null ? 'the system' : ($d->uploadedByName ?? 'someone who has left'), 'uploaded_at' => $d->uploadedAt->toIso8601String(),
            'url' => "{$pageUrl}/documents/{$d->id}"], $this->store->list($objectType, $objectId));
    }
}
