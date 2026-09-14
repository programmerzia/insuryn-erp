<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Renewal\Application\Documents;

use App\Modules\Insurance\Quotation\Application\Documents\RatedRiskDocument;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Documents\Generation\DocumentDataProvider;
use App\Modules\Platform\Documents\Generation\DocumentSubject;
use App\Modules\Platform\Documents\Generation\DocumentValues;
use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The renewal notice (Phase 3 design §3 `renewal_notice`, §4 notices; slice R9) for a row of the expiry register: the expiring policy, when it expires, the
 * date to renew by, the risk to be renewed and the renewal premium of its renewal quotation (net premium, each duty, gross), and the proposed renewal period.
 * The PDF is kept on the expiring policy's page (its Documents tab). A row without an offered renewal quotation has nothing to print (DOCUMENT_OBJECT_NOT_READY).
 */
final class RenewalNoticeDocumentData implements DocumentDataProvider
{
    public const OBJECT_TYPE = 'expiry_register';

    public function __construct(private readonly RatedRiskDocument $rated) {}

    public function objectType(): string
    {
        return self::OBJECT_TYPE;
    }

    public function templateCodes(): array
    {
        return [DocumentTemplateCode::RenewalNotice];
    }

    public function subject(string $objectId, DocumentTemplateCode $code): DocumentSubject
    {
        [$entry] = $this->load($objectId);

        return new DocumentSubject('policy', (string) $entry->policy_id, (string) $entry->policy_number, $entry->class_code === null ? null : (string) $entry->class_code,
            AuthorizationScope::branch((string) $entry->entity_id, (string) $entry->branch_id));
    }

    public function variables(string $objectId, DocumentTemplateCode $code, string $locale): array
    {
        [$entry, $quotation] = $this->load($objectId);
        $bag = $this->rated->variables($quotation, $locale);
        $l = fn (string $en, string $bn): string => DocumentValues::label($locale, $en, $bn);
        $term = (int) DB::table('product_versions')->where('id', $quotation->product_version_id)->value('term_months');
        $inception = CarbonImmutable::parse((string) $quotation->inception);
        $details = [
            ['label' => $l('Expiring policy', 'মেয়াদোত্তীর্ণ পলিসি'), 'value' => (string) $entry->policy_number],
            ['label' => $l('Expires on', 'মেয়াদ শেষ'), 'value' => DocumentValues::date((string) $entry->expiry)],
            ['label' => $l('Renew by', 'নবায়নের শেষ তারিখ'), 'value' => DocumentValues::date((string) $quotation->valid_until)],
            ['label' => $l('Renewal quotation', 'নবায়ন কোটেশন'), 'value' => (string) $quotation->number],
            ...$bag['details'],
        ];

        return [...$bag,
            'document' => ['title' => $code->title($locale), 'number' => (string) $entry->policy_number, 'date' => DocumentValues::date(CarbonImmutable::today())],
            'details' => $details,
            'total' => ['label' => $l('Renewal premium', 'নবায়ন প্রিমিয়াম'), 'amount' => $bag['total']['amount']],
            'period' => ['from' => DocumentValues::date($inception), 'to' => DocumentValues::date($inception->addMonthsNoOverflow(max(1, $term))->subDay())],
        ];
    }

    /**
     * @return array{0: \stdClass, 1: \stdClass} the register row and its renewal quotation
     *
     * @throws BusinessRuleViolation DOCUMENT_OBJECT_UNKNOWN, DOCUMENT_OBJECT_NOT_READY
     */
    private function load(string $entryId): array
    {
        $entry = DB::table('expiry_register')->where('id', $entryId)->first();
        if (! $entry instanceof \stdClass) {
            throw new BusinessRuleViolation('DOCUMENT_OBJECT_UNKNOWN', 'That policy is not in the expiry register.');
        }
        $quotation = $entry->renewal_quotation_id === null ? null : DB::table('quotations')->where('id', $entry->renewal_quotation_id)->first();
        if (! $quotation instanceof \stdClass || $quotation->number === null || $quotation->rating_result === null) {
            throw new BusinessRuleViolation('DOCUMENT_OBJECT_NOT_READY', 'No renewal quotation has been offered for this policy yet, so there is no renewal premium to print.');
        }

        return [$entry, $quotation];
    }
}
