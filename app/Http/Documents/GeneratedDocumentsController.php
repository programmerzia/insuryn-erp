<?php

declare(strict_types=1);

namespace App\Http\Documents;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Collections\Http\Controllers\CollectionsPageController;
use App\Modules\Insurance\Policy\Http\Controllers\PolicyPageController;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Documents\Generation\DocumentGenerator;
use App\Modules\Platform\Documents\Generation\GeneratedDocument;
use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Generated documents on object pages (slice R8), composed at the app layer: the "Generate schedule / endorsement / receipt" actions and the
 * versions generated, on the Documents tab of the policy and receipt pages. Opening the page's area is required as for every page action;
 * DocumentGenerator checks document.generate in the object's branch (A-101). The PDFs themselves are stored documents of the page, downloaded
 * through the page's documents route.
 */
final class GeneratedDocumentsController
{
    public function __construct(
        private readonly DocumentGenerator $generator,
        private readonly PermissionChecker $permissions,
    ) {}

    public function policy(Request $request, string $policy): RedirectResponse
    {
        $actor = PageSupport::actor($request);
        $this->open($actor, PolicyPageController::AREA, 'policies', $policy);
        /** @var array{template_code: string, object_id?: string|null, locale: string} $data */
        $data = $request->validate(['template_code' => ['required', Rule::in([DocumentTemplateCode::PolicySchedule->value, DocumentTemplateCode::Endorsement->value])],
            'object_id' => ['nullable', 'uuid'], 'locale' => ['required', Rule::in(['en', 'bn'])]]);
        [$objectType, $objectId] = $data['template_code'] === DocumentTemplateCode::Endorsement->value
            ? ['policy_transaction', (string) DB::table('policy_transactions')->where('id', $data['object_id'] ?? null)->where('policy_id', $policy)->value('id')]
            : ['policy', $policy];
        abort_if($objectId === '', 404);
        $generated = $this->generator->generate($data['template_code'], $objectType, $objectId, $actor, $data['locale']);

        return redirect("/policies/{$policy}?tab=documents")->with('status', self::generatedMessage($generated));
    }

    public function receipt(Request $request, string $receipt): RedirectResponse
    {
        $actor = PageSupport::actor($request);
        $this->open($actor, CollectionsPageController::AREA, 'receipts', $receipt);
        /** @var array{locale: string} $data */
        $data = $request->validate(['locale' => ['required', Rule::in(['en', 'bn'])]]);
        $generated = $this->generator->generate(DocumentTemplateCode::Receipt, 'receipt', $receipt, $actor, $data['locale']);

        // Flow fix X5: printed from the receipt page's header, the file is offered for download straight away.
        return redirect("/receipts/{$receipt}?tab=documents")->with('status', self::generatedMessage($generated))
            ->with('next', ['label' => 'Download', 'url' => "/receipts/{$receipt}/documents/{$generated->storedDocumentId}", 'method' => 'download']);
    }

    /** POST /quotations/{id}/generated-documents — print an issued quotation (Phase 3 §2 step 1 "save/print quotation"). */
    public function quotation(Request $request, string $quotation): RedirectResponse
    {
        $actor = PageSupport::actor($request);
        $this->open($actor, \App\Modules\Insurance\Quotation\Http\Controllers\QuotationPageController::AREA, 'quotations', $quotation);
        /** @var array{locale: string} $data */
        $data = $request->validate(['locale' => ['required', Rule::in(['en', 'bn'])]]);
        $generated = $this->generator->generate(DocumentTemplateCode::Quotation, 'quotation', $quotation, $actor, $data['locale']);

        return redirect("/quotations/{$quotation}")->with('status', self::generatedMessage($generated));
    }

    /** POST /cover-notes/{id}/generated-documents — print a cover note (Phase 3 §2 step 3). */
    public function coverNote(Request $request, string $coverNote): RedirectResponse
    {
        $actor = PageSupport::actor($request);
        $this->open($actor, \App\Modules\Insurance\CoverNote\Http\Controllers\CoverNotesPageController::AREA, 'cover_notes', $coverNote);
        /** @var array{locale: string} $data */
        $data = $request->validate(['locale' => ['required', Rule::in(['en', 'bn'])]]);
        $generated = $this->generator->generate(DocumentTemplateCode::CoverNote, 'cover_note', $coverNote, $actor, $data['locale']);

        return redirect('/cover-notes')->with('status', self::generatedMessage($generated));
    }

    /** GET /quotations/{id}/documents/{document} — a printed quotation, audited as a download. */
    public function quotationDocument(Request $request, \App\Http\Pages\ObjectDocuments $documents, string $quotation, string $document): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->open(PageSupport::actor($request), \App\Modules\Insurance\Quotation\Http\Controllers\QuotationPageController::AREA, 'quotations', $quotation);

        return $documents->download($request, 'quotation', $quotation, $document);
    }

    /** GET /cover-notes/{id}/documents/{document} — a printed cover note, audited as a download. */
    public function coverNoteDocument(Request $request, \App\Http\Pages\ObjectDocuments $documents, string $coverNote, string $document): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->open(PageSupport::actor($request), \App\Modules\Insurance\CoverNote\Http\Controllers\CoverNotesPageController::AREA, 'cover_notes', $coverNote);

        return $documents->download($request, 'cover_note', $coverNote, $document);
    }

    /**
     * Follow-up H1 (D-43): the page's area opens in any scope, the object must exist (404), and its own branch must be within the user's reach (403).
     *
     * @param list<string> $area
     */
    private function open(string $actor, array $area, string $table, string $id): void
    {
        $this->permissions->authorizeArea($actor, $area);
        $model = DB::table($table)->where('id', $id)->first(['entity_id', 'branch_id']);
        abort_if($model === null, 404);
        $this->permissions->authorizeAny($actor, $area, AuthorizationScope::branch((string) $model->entity_id, (string) $model->branch_id));
    }

    /** @return array<string, mixed> the quotation workbench's print panel: printing once issued, and every version printed */
    public function forQuotation(string $actor, string $quotation): array
    {
        $model = DB::table('quotations')->where('id', $quotation)->first(['id', 'entity_id', 'branch_id', 'number']);
        if ($model === null) {
            return self::panel(null, [], []);
        }
        $may = $model->number !== null && $this->permissions->has($actor, DocumentGenerator::PERMISSION, AuthorizationScope::branch((string) $model->entity_id, (string) $model->branch_id));

        return self::panel("/quotations/{$quotation}/generated-documents", $may ? [['label' => 'Generate quotation', 'template_code' => DocumentTemplateCode::Quotation->value, 'object_id' => null]] : [],
            array_map(fn (GeneratedDocument $g): array => self::row($g, "/quotations/{$quotation}"), $this->generator->history('quotation', $quotation)));
    }

    /**
     * A cover note's printed versions for the cover notes queue, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forCoverNote(string $coverNote): array
    {
        return array_map(fn (GeneratedDocument $g): array => self::row($g, "/cover-notes/{$coverNote}"), $this->generator->history('cover_note', $coverNote));
    }

    /**
     * The policy page's generation panel: what the user may generate (the schedule once issued, each endorsement) and every version generated.
     *
     * @return array<string, mixed>
     */
    public function forPolicy(string $actor, string $policy): array
    {
        $model = DB::table('policies')->where('id', $policy)->first(['id', 'entity_id', 'branch_id', 'status', 'number']);
        if ($model === null) {
            return self::panel(null, [], []);
        }
        $endorsements = DB::table('policy_transactions')->where('policy_id', $policy)->where('type', 'endorsement')->orderBy('created_at')->orderBy('id')->get(['id', 'effective_date']);
        $actions = [];
        if ($this->permissions->has($actor, DocumentGenerator::PERMISSION, AuthorizationScope::branch((string) $model->entity_id, (string) $model->branch_id))) {
            if ($model->status !== 'quote' && $model->number !== null) {
                $actions[] = ['label' => 'Generate schedule', 'template_code' => DocumentTemplateCode::PolicySchedule->value, 'object_id' => null];
            }
            foreach ($endorsements as $i => $endorsement) {
                $actions[] = ['label' => 'Generate endorsement '.($i + 1).' ('.substr((string) $endorsement->effective_date, 0, 10).')',
                    'template_code' => DocumentTemplateCode::Endorsement->value, 'object_id' => (string) $endorsement->id];
            }
        }
        $history = $this->generator->history('policy', $policy);
        foreach ($endorsements as $endorsement) {
            $history = [...$history, ...$this->generator->history('policy_transaction', (string) $endorsement->id)];
        }
        usort($history, fn (GeneratedDocument $a, GeneratedDocument $b): int => [$b->renderedAt->format('Y-m-d H:i:s.u'), $b->id] <=> [$a->renderedAt->format('Y-m-d H:i:s.u'), $a->id]);

        return self::panel("/policies/{$policy}/generated-documents", $actions, array_map(fn (GeneratedDocument $g): array => self::row($g, "/policies/{$policy}"), $history));
    }

    /** @return array<string, mixed> */
    public function forReceipt(string $actor, string $receipt): array
    {
        $model = DB::table('receipts')->where('id', $receipt)->first(['id', 'entity_id', 'branch_id', 'status']);
        if ($model === null) {
            return self::panel(null, [], []);
        }
        $may = $model->status !== 'bounced' && $this->permissions->has($actor, DocumentGenerator::PERMISSION, AuthorizationScope::branch((string) $model->entity_id, (string) $model->branch_id));

        return self::panel("/receipts/{$receipt}/generated-documents", $may ? [['label' => 'Generate receipt', 'template_code' => DocumentTemplateCode::Receipt->value, 'object_id' => null]] : [],
            array_map(fn (GeneratedDocument $g): array => self::row($g, "/receipts/{$receipt}"), $this->generator->history('receipt', $receipt)));
    }

    /**
     * @param list<array{label: string, template_code: string, object_id: string|null}> $actions
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>
     */
    private static function panel(?string $url, array $actions, array $history): array
    {
        return ['url' => $url, 'actions' => $actions, 'locales' => [['value' => 'en', 'label' => 'English'], ['value' => 'bn', 'label' => 'বাংলা']], 'history' => $history];
    }

    /** @return array<string, mixed> */
    private static function row(GeneratedDocument $g, string $pageUrl): array
    {
        return ['id' => $g->id, 'title' => $g->templateCode->title(), 'number' => $g->number, 'version' => $g->version, 'locale' => $g->locale, 'template_version' => $g->templateVersion,
            'rendered_by' => $g->renderedBy === null ? 'the system' : ($g->renderedByName ?? 'someone who has left'), 'rendered_at' => $g->renderedAt->toIso8601String(),
            'reference' => substr($g->contentSha256, 0, 12), 'sha256' => $g->sha256, 'size_bytes' => $g->sizeBytes, 'url' => "{$pageUrl}/documents/{$g->storedDocumentId}"];
    }

    private static function generatedMessage(GeneratedDocument $generated): string
    {
        return "{$generated->templateCode->title()} version {$generated->version} generated.";
    }
}
