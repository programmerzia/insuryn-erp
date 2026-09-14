<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Generation;

use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Documents\DocumentContents;
use App\Modules\Platform\Documents\DocumentStore;
use App\Modules\Platform\Documents\Rendering\PdfRenderer;
use App\Modules\Platform\Documents\Rendering\TemplateRenderer;
use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use App\Modules\Platform\Documents\Templates\DocumentTemplates;
use App\Modules\Platform\Documents\Templates\DocumentVariables;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\BusinessClock;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates a document for a business object (slice R8, design §3): the provider for the object gives the variables, the active template for
 * the code, the object's product class and the locale renders them, headless Chromium prints the PDF, and the PDF is stored through the
 * DocumentStore on the object's page (so it is listed on its Documents tab) with a generated_documents row. Generated documents are never
 * overwritten: generating again makes the next version. The PDF is printed before the transaction; the version, the stored document, the row
 * and the audit row (`document.generated`) commit together.
 */
final class DocumentGenerator
{
    public const PERMISSION = 'document.generate';

    public function __construct(
        private readonly DocumentDataProviders $providers,
        private readonly DocumentTemplates $templates,
        private readonly TemplateRenderer $renderer,
        private readonly PdfRenderer $pdf,
        private readonly DocumentStore $store,
        private readonly PermissionChecker $permissions,
    ) {}

    /**
     * ASSUMPTION: A-102 — the locale defaults to config `erp.documents.default_locale` (en).
     *
     * @throws BusinessRuleViolation DOCUMENT_TEMPLATE_CODE_UNKNOWN, DOCUMENT_PROVIDER_MISSING, DOCUMENT_TEMPLATE_MISSING, DOCUMENT_TEMPLATE_UNSAFE,
     *   DOCUMENT_TEMPLATE_RENDER_FAILED, DOCUMENT_PDF_FAILED, and the provider's own refusals
     */
    public function generate(DocumentTemplateCode|string $templateCode, string $objectType, string $objectId, string $actorUserId, ?string $locale = null): GeneratedDocument
    {
        return $this->produce($templateCode, $objectType, $objectId, $actorUserId, $locale);
    }

    /**
     * Slice R9 (DECISION D-41): a document produced by a scheduled system run (the renewal notice at T-45), not by a person — so no permission applies; the
     * generated row has no `rendered_by` and the audit row names the system. Everything else is `generate`.
     */
    public function generateBySystem(DocumentTemplateCode|string $templateCode, string $objectType, string $objectId, ?string $locale = null): GeneratedDocument
    {
        return $this->produce($templateCode, $objectType, $objectId, null, $locale);
    }

    private function produce(DocumentTemplateCode|string $templateCode, string $objectType, string $objectId, ?string $actorUserId, ?string $locale): GeneratedDocument
    {
        $code = $templateCode instanceof DocumentTemplateCode ? $templateCode
            : (DocumentTemplateCode::tryFrom($templateCode) ?? throw new BusinessRuleViolation('DOCUMENT_TEMPLATE_CODE_UNKNOWN', "There is no document type {$templateCode}."));
        $locale ??= (string) config('erp.documents.default_locale', 'en');
        if (! in_array($locale, ['en', 'bn'], true)) {
            throw new BusinessRuleViolation('DOCUMENT_TEMPLATE_LOCALE_UNKNOWN', 'Choose English (en) or Bangla (bn).');
        }
        if (! Str::isUuid($objectId)) {
            throw new BusinessRuleViolation('DOCUMENT_OBJECT_UNKNOWN', 'That record does not exist.');
        }
        $provider = $this->providers->for($objectType, $code);
        $subject = $provider->subject($objectId, $code);
        if ($actorUserId !== null) {
            $this->permissions->authorize($actorUserId, self::PERMISSION, $subject->scope);
        }
        $template = $this->templates->active($code, $subject->productClass, $locale)
            ?? throw new BusinessRuleViolation('DOCUMENT_TEMPLATE_MISSING', 'No '.$code->title().' template is active in '.($locale === 'bn' ? 'Bangla' : 'English').'. Ask an administrator to activate one on the templates screen.');

        $variables = DocumentVariables::normalise($provider->variables($objectId, $code, $locale));
        $document = is_array($variables['document']) ? $variables['document'] : [];
        $variables['document'] = ['title' => ($document['title'] ?? '') !== '' ? $document['title'] : $code->title($locale),
            'number' => ($document['number'] ?? '') !== '' ? $document['number'] : (string) $subject->number, 'date' => $document['date'] ?? ''];
        $renderedAt = CarbonImmutable::now();
        $rendered = $this->renderer->document($template->body, $template->letterhead, $variables, $locale, $renderedAt->setTimezone(self::timezone()));
        $pdf = $this->pdf->render($rendered->html);

        return DB::transaction(function () use ($code, $objectType, $objectId, $actorUserId, $locale, $subject, $template, $rendered, $pdf, $renderedAt): GeneratedDocument {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['generated_document:'.TenantContext::id().":{$objectType}:{$objectId}:{$code->value}"]);
            $version = (int) DB::table('generated_documents')->where('object_type', $objectType)->where('object_id', $objectId)->where('template_code', $code->value)->max('version') + 1;
            $id = (string) Str::uuid7();
            $label = $subject->number ?? substr($objectId, -8);
            $fileName = Str::slug($code->value).'-'.(string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $label)."-v{$version}-{$locale}.pdf";
            $stored = $this->store->storeGenerated($subject->attachToType, $subject->attachToId, new DocumentContents($fileName, $pdf), $actorUserId,
                $code->title().' version '.$version.($locale === 'bn' ? ' (Bangla)' : ' (English)'),
                ['generated_document_id' => $id, 'template_code' => $code->value, 'template_version' => $template->version, 'version' => $version, 'locale' => $locale,
                    'generated_for' => ['type' => $objectType, 'id' => $objectId], 'number' => $subject->number]);
            DB::table('generated_documents')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'template_id' => $template->id, 'template_code' => $code->value,
                'template_version' => $template->version, 'locale' => $locale, 'object_type' => $objectType, 'object_id' => $objectId, 'number' => $subject->number,
                'version' => $version, 'stored_document_id' => $stored->id, 'sha256' => $stored->sha256, 'content_sha256' => $rendered->contentSha256,
                'size_bytes' => $stored->sizeBytes, 'rendered_at' => $renderedAt->format('Y-m-d H:i:s.uP'), 'rendered_by' => $actorUserId]);

            return $this->history($objectType, $objectId, $id)[0];
        });
    }

    /**
     * The generated documents of an object, newest first (every code, every version).
     *
     * @return list<GeneratedDocument>
     */
    public function history(string $objectType, string $objectId, ?string $onlyId = null): array
    {
        if (! Str::isUuid($objectId)) {
            return [];
        }

        return array_values(DB::table('generated_documents as g')->join('stored_documents as d', 'd.id', '=', 'g.stored_document_id')->leftJoin('users as u', 'u.id', '=', 'g.rendered_by')
            ->where('g.object_type', $objectType)->where('g.object_id', $objectId)->when($onlyId !== null, fn ($q) => $q->where('g.id', $onlyId))
            ->orderByDesc('g.rendered_at')->orderByDesc('g.id')->select(['g.*', 'd.original_name as file_name', 'u.name as rendered_by_name'])->get()
            ->map(fn (\stdClass $row): GeneratedDocument => GeneratedDocument::fromRow($row))->all());
    }

    /** The company's time zone, for "generated at" on the page: the business clock's (slice 2.1b, D-54; Asia/Dhaka by default). */
    private static function timezone(): string
    {
        return app(BusinessClock::class)->timezone();
    }
}
