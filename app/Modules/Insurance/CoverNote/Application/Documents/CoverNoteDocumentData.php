<?php

declare(strict_types=1);

namespace App\Modules\Insurance\CoverNote\Application\Documents;

use App\Modules\Insurance\Quotation\Application\Documents\RatedRiskDocument;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Documents\Generation\DocumentDataProvider;
use App\Modules\Platform\Documents\Generation\DocumentSubject;
use App\Modules\Platform\Documents\Generation\DocumentValues;
use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * The cover note (Phase 3 design §2 step 3, §3): temporary evidence of cover pending the policy — its own number and period, the risk and the premium
 * of the approved proposal (its re-rated result when underwriting added a loading, else the quotation's). Printed for any status, so a superseded or
 * cancelled note can still be reprinted with its status shown.
 */
final class CoverNoteDocumentData implements DocumentDataProvider
{
    public function __construct(private readonly RatedRiskDocument $rated) {}

    public function objectType(): string
    {
        return 'cover_note';
    }

    public function templateCodes(): array
    {
        return [DocumentTemplateCode::CoverNote];
    }

    public function subject(string $objectId, DocumentTemplateCode $code): DocumentSubject
    {
        $note = $this->note($objectId);

        return new DocumentSubject('cover_note', (string) $note->id, (string) $note->number, (string) $note->class_code,
            AuthorizationScope::branch((string) $note->entity_id, (string) $note->branch_id));
    }

    public function variables(string $objectId, DocumentTemplateCode $code, string $locale): array
    {
        $note = $this->note($objectId);
        $proposal = DB::table('proposals')->where('id', $note->proposal_id)->first();
        $quotation = $proposal === null ? null : DB::table('quotations')->where('id', $proposal->quotation_id)->first();
        if ($proposal === null || $quotation === null) {
            throw new BusinessRuleViolation('DOCUMENT_OBJECT_UNKNOWN', 'The cover note has no proposal or quotation to print from.');
        }
        if (is_string($proposal->rating_result ?? null)) {
            $quotation->rating_result = $proposal->rating_result;
        }
        $l = fn (string $en, string $bn): string => DocumentValues::label($locale, $en, $bn);
        $bag = $this->rated->variables($quotation, $locale);
        $bag['details'][] = ['label' => $l('Valid', 'মেয়াদ'), 'value' => DocumentValues::date((string) $note->valid_from).' – '.DocumentValues::date((string) $note->valid_to)];
        if ($note->status !== 'active') {
            $bag['details'][] = ['label' => $l('Status', 'অবস্থা'), 'value' => match ((string) $note->status) {
                'superseded' => $l('Superseded by the policy', 'পলিসি ইস্যুর মাধ্যমে প্রতিস্থাপিত'), 'cancelled' => $l('Cancelled', 'বাতিল'), 'expired' => $l('Expired', 'মেয়াদোত্তীর্ণ'),
                default => (string) $note->status,
            }];
        }

        return [...$bag,
            'document' => ['title' => $code->title($locale), 'number' => (string) $note->number, 'date' => DocumentValues::date(substr((string) $note->issued_at, 0, 10))],
            'period' => ['from' => DocumentValues::date((string) $note->valid_from), 'to' => DocumentValues::date((string) $note->valid_to)],
        ];
    }

    /** @throws BusinessRuleViolation DOCUMENT_OBJECT_UNKNOWN */
    private function note(string $coverNoteId): \stdClass
    {
        $note = DB::table('cover_notes')->where('id', $coverNoteId)->first();
        if (! $note instanceof \stdClass) {
            throw new BusinessRuleViolation('DOCUMENT_OBJECT_UNKNOWN', 'That cover note does not exist.');
        }

        return $note;
    }
}
