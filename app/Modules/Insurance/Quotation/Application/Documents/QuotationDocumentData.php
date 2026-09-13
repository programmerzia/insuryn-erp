<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Quotation\Application\Documents;

use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Documents\Generation\DocumentDataProvider;
use App\Modules\Platform\Documents\Generation\DocumentSubject;
use App\Modules\Platform\Documents\Generation\DocumentValues;
use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The quotation given to the customer (Phase 3 design §2 step 1 "save/print quotation", §3): risk details, the frozen premium breakdown, the proposed
 * period and how long the price holds. Only an issued quotation has a number and a price to print; a draft is refused (DOCUMENT_OBJECT_NOT_READY).
 */
final class QuotationDocumentData implements DocumentDataProvider
{
    public function __construct(private readonly RatedRiskDocument $rated) {}

    public function objectType(): string
    {
        return 'quotation';
    }

    public function templateCodes(): array
    {
        return [DocumentTemplateCode::Quotation];
    }

    public function subject(string $objectId, DocumentTemplateCode $code): DocumentSubject
    {
        $quotation = $this->quotation($objectId);
        if ($quotation->number === null || $quotation->rating_result === null) {
            throw new BusinessRuleViolation('DOCUMENT_OBJECT_NOT_READY', 'A draft quotation has no number or price to print yet. Issue it first.');
        }

        return new DocumentSubject('quotation', (string) $quotation->id, (string) $quotation->number, $quotation->class_code === null ? null : (string) $quotation->class_code,
            AuthorizationScope::branch((string) $quotation->entity_id, (string) $quotation->branch_id));
    }

    public function variables(string $objectId, DocumentTemplateCode $code, string $locale): array
    {
        $quotation = $this->quotation($objectId);
        $bag = $this->rated->variables($quotation, $locale);
        $term = (int) DB::table('product_versions')->where('id', $quotation->product_version_id)->value('term_months');
        $inception = CarbonImmutable::parse((string) $quotation->inception);
        $bag['details'][] = ['label' => DocumentValues::label($locale, 'Valid until', 'বৈধতার শেষ তারিখ'), 'value' => DocumentValues::date((string) $quotation->valid_until)];

        return [...$bag,
            'document' => ['title' => $code->title($locale), 'number' => (string) $quotation->number, 'date' => DocumentValues::date(substr((string) ($quotation->issued_at ?? $quotation->updated_at), 0, 10))],
            'period' => ['from' => DocumentValues::date($inception), 'to' => DocumentValues::date($inception->addMonthsNoOverflow(max(1, $term))->subDay())],
        ];
    }

    /** @throws BusinessRuleViolation DOCUMENT_OBJECT_UNKNOWN */
    private function quotation(string $quotationId): \stdClass
    {
        $quotation = DB::table('quotations')->where('id', $quotationId)->first();
        if (! $quotation instanceof \stdClass) {
            throw new BusinessRuleViolation('DOCUMENT_OBJECT_UNKNOWN', 'That quotation does not exist.');
        }

        return $quotation;
    }
}
