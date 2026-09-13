<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Generation;

use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;

/** The data providers tagged DocumentDataProvider::class, found by object type and template code. */
final class DocumentDataProviders
{
    /** @param iterable<DocumentDataProvider> $providers */
    public function __construct(private readonly iterable $providers) {}

    public function has(string $objectType, DocumentTemplateCode $code): bool
    {
        return $this->find($objectType, $code) !== null;
    }

    /** @throws BusinessRuleViolation DOCUMENT_PROVIDER_MISSING */
    public function for(string $objectType, DocumentTemplateCode $code): DocumentDataProvider
    {
        return $this->find($objectType, $code)
            ?? throw new BusinessRuleViolation('DOCUMENT_PROVIDER_MISSING', "A {$code->title()} cannot be generated for a {$objectType} yet.");
    }

    private function find(string $objectType, DocumentTemplateCode $code): ?DocumentDataProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->objectType() === $objectType && in_array($code, $provider->templateCodes(), true)) {
                return $provider;
            }
        }

        return null;
    }
}
