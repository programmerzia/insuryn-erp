<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Generation;

use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;

/**
 * What a business context gives the document generator for one kind of object (slice R8). Platform knows no policy, receipt or claim: each
 * context implements this for its objects and tags the class with DocumentDataProvider::class in its service provider, e.g.
 *
 *     $this->app->tag([QuotationDocumentData::class], DocumentDataProvider::class);
 *
 * Adding a document for a new object is that one class plus its tag; templates, rendering, storage, versions, audit and permissions come from
 * DocumentGenerator.
 */
interface DocumentDataProvider
{
    /** The object type the provider reads (the audit subject type, e.g. `policy`, `policy_transaction`, `receipt`). */
    public function objectType(): string;

    /** @return list<DocumentTemplateCode> the documents this provider fills for its object type */
    public function templateCodes(): array;

    /**
     * Where the document belongs: the object whose Documents tab holds the PDF, its business number, its product class (to choose a class template)
     * and its authorization scope.
     *
     * @throws BusinessRuleViolation when the object does not exist or cannot have this document yet
     */
    public function subject(string $objectId, DocumentTemplateCode $code): DocumentSubject;

    /**
     * The variable bag for the template (DocumentVariables::NAMES): text, formatted money and dates, and lists of rows. Labels in $locale.
     *
     * @return array<string, mixed>
     */
    public function variables(string $objectId, DocumentTemplateCode $code, string $locale): array;
}
