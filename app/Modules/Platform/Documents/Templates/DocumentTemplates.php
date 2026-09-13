<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Templates;

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Documents\Rendering\RenderedDocument;
use App\Modules\Platform\Documents\Rendering\TemplateRenderer;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Document templates (slice R8, design §3 "tenant-editable body"): versions per (code, product class, locale). A draft is edited in place; saving
 * over an active or retired version makes a new draft version (one open draft per code, class and locale); activating a draft retires the
 * version in force, so exactly one is active. A body is checked by TemplateBodyGuard and rendered with the demo variables before it is saved, so
 * a saved template always renders. Every change is audited on the template; permission document.manage_templates (A-101).
 * ASSUMPTION: A-106 — drafts are edited in place, one open draft per template, activation needs no second person.
 */
final class DocumentTemplates
{
    public const MANAGE = 'document.manage_templates';

    private const BODY_MAX = 200_000;

    private const LETTERHEAD_MAX = 50_000;

    public function __construct(
        private readonly Audit $audit,
        private readonly PermissionChecker $permissions,
        private readonly TemplateRenderer $renderer,
    ) {}

    /** @return list<DocumentTemplate> every version, by code, class, locale and newest version first */
    public function all(): array
    {
        return array_values(DB::table('document_templates')->orderBy('code')->orderByRaw("coalesce(product_class, '')")->orderBy('locale')->orderByDesc('version')->get()
            ->map(fn (\stdClass $row): DocumentTemplate => DocumentTemplate::fromRow($row))->all());
    }

    /** @throws BusinessRuleViolation DOCUMENT_TEMPLATE_UNKNOWN */
    public function find(string $templateId): DocumentTemplate
    {
        $row = Str::isUuid($templateId) ? DB::table('document_templates')->where('id', $templateId)->first() : null;
        if (! $row instanceof \stdClass) {
            throw new BusinessRuleViolation('DOCUMENT_TEMPLATE_UNKNOWN', 'That document template does not exist.');
        }

        return DocumentTemplate::fromRow($row);
    }

    /** The template in force for a document: the product class's own template, else the one for every class. */
    public function active(DocumentTemplateCode $code, ?string $productClass, string $locale): ?DocumentTemplate
    {
        foreach (array_unique([$productClass, null], SORT_REGULAR) as $class) {
            $row = DB::table('document_templates')->where('code', $code->value)->where('locale', $locale)->where('status', DocumentTemplateStatus::Active->value)
                ->when($class === null, fn ($q) => $q->whereNull('product_class'), fn ($q) => $q->where('product_class', $class))->first();
            if ($row instanceof \stdClass) {
                return DocumentTemplate::fromRow($row);
            }
        }

        return null;
    }

    /**
     * Versions of one template (same code, class and locale), newest first.
     *
     * @return list<DocumentTemplate>
     */
    public function versions(DocumentTemplate $template): array
    {
        return array_values($this->chain($template->code, $template->productClass, $template->locale)->orderByDesc('version')->get()
            ->map(fn (\stdClass $row): DocumentTemplate => DocumentTemplate::fromRow($row))->all());
    }

    /**
     * A new draft for a code, class and locale: version 1, or the next version when the template already has versions.
     *
     * @throws BusinessRuleViolation DOCUMENT_TEMPLATE_CODE_UNKNOWN, DOCUMENT_TEMPLATE_LOCALE_UNKNOWN, DOCUMENT_TEMPLATE_CLASS_UNKNOWN, DOCUMENT_TEMPLATE_DRAFT_EXISTS,
     *   DOCUMENT_TEMPLATE_UNSAFE, DOCUMENT_TEMPLATE_RENDER_FAILED, DOCUMENT_TEMPLATE_TOO_LONG
     */
    public function create(string $code, ?string $productClass, string $locale, ?string $body, ?string $letterhead, string $actorUserId): DocumentTemplate
    {
        $this->permissions->authorize($actorUserId, self::MANAGE);
        $templateCode = DocumentTemplateCode::tryFrom($code) ?? throw new BusinessRuleViolation('DOCUMENT_TEMPLATE_CODE_UNKNOWN', 'Choose one of the document types: '.implode(', ', DocumentTemplateCode::values()).'.');
        $locale = self::locale($locale);
        $productClass = $productClass === null || trim($productClass) === '' ? null : trim($productClass);
        if ($productClass !== null && ! DB::table('product_classes')->where('code', $productClass)->exists()) {
            throw new BusinessRuleViolation('DOCUMENT_TEMPLATE_CLASS_UNKNOWN', "There is no product class {$productClass}. Choose a class from the list, or none for every class.");
        }
        $body ??= ($this->active($templateCode, null, $locale)->body ?? DefaultDocumentTemplates::body($templateCode, $locale));
        $letterhead = self::blankToNull($letterhead);
        $this->validate($templateCode, $body, $letterhead, $locale);

        return DB::transaction(function () use ($templateCode, $productClass, $locale, $body, $letterhead, $actorUserId): DocumentTemplate {
            $this->lockChain($templateCode, $productClass, $locale);
            $this->refuseOpenDraft($templateCode, $productClass, $locale);
            $version = (int) $this->chain($templateCode, $productClass, $locale)->max('version') + 1;
            $id = $this->insert($templateCode, $productClass, $locale, $version, $body, $letterhead, DocumentTemplateStatus::Draft, $actorUserId);
            $this->audit->record('document_template.created', AuditSubject::of('document_template', $id), null, self::auditState($this->find($id)),
                permission: self::MANAGE, actor: Actor::user($actorUserId));

            return $this->find($id);
        });
    }

    /**
     * Saves a body and letterhead: a draft is updated; an active or retired version is left as it is and a new draft version is made from it.
     *
     * @throws BusinessRuleViolation DOCUMENT_TEMPLATE_DRAFT_EXISTS, DOCUMENT_TEMPLATE_UNSAFE, DOCUMENT_TEMPLATE_RENDER_FAILED, DOCUMENT_TEMPLATE_TOO_LONG
     */
    public function saveDraft(string $templateId, string $body, ?string $letterhead, string $actorUserId): DocumentTemplate
    {
        $this->permissions->authorize($actorUserId, self::MANAGE);
        $template = $this->find($templateId);
        $letterhead = self::blankToNull($letterhead);
        $this->validate($template->code, $body, $letterhead, $template->locale);

        return DB::transaction(function () use ($template, $body, $letterhead, $actorUserId): DocumentTemplate {
            $this->lockChain($template->code, $template->productClass, $template->locale);
            $current = $this->find($template->id);
            if ($current->isDraft()) {
                $before = self::auditState($current);
                DB::table('document_templates')->where('id', $current->id)->update(['body' => $body, 'letterhead' => $letterhead, 'updated_at' => CarbonImmutable::now()]);
                $saved = $this->find($current->id);
                $this->audit->record('document_template.draft_saved', AuditSubject::of('document_template', $saved->id), $before, self::auditState($saved),
                    permission: self::MANAGE, actor: Actor::user($actorUserId));

                return $saved;
            }
            $this->refuseOpenDraft($current->code, $current->productClass, $current->locale);
            $version = (int) $this->chain($current->code, $current->productClass, $current->locale)->max('version') + 1;
            $id = $this->insert($current->code, $current->productClass, $current->locale, $version, $body, $letterhead, DocumentTemplateStatus::Draft, $actorUserId);
            $saved = $this->find($id);
            $this->audit->record('document_template.created', AuditSubject::of('document_template', $id), null, self::auditState($saved) + ['based_on_version' => $current->version],
                permission: self::MANAGE, actor: Actor::user($actorUserId));

            return $saved;
        });
    }

    /**
     * Puts a draft in force and retires the version it replaces.
     *
     * @throws BusinessRuleViolation DOCUMENT_TEMPLATE_NOT_DRAFT, DOCUMENT_TEMPLATE_ACTIVATION_CONFLICT
     */
    public function activate(string $templateId, string $actorUserId): DocumentTemplate
    {
        $this->permissions->authorize($actorUserId, self::MANAGE);
        $template = $this->find($templateId);
        // A draft saved before a guard change must still be safe and render when it goes into use.
        $this->validate($template->code, $template->body, $template->letterhead, $template->locale);

        try {
            return DB::transaction(function () use ($template, $actorUserId): DocumentTemplate {
                $this->lockChain($template->code, $template->productClass, $template->locale);
                $current = $this->find($template->id);
                if (! $current->isDraft()) {
                    throw new BusinessRuleViolation('DOCUMENT_TEMPLATE_NOT_DRAFT', "Version {$current->version} is {$current->status->value}; only a draft can be activated. Save a new draft first.");
                }
                $now = CarbonImmutable::now();
                $previous = $this->chain($current->code, $current->productClass, $current->locale)->where('status', DocumentTemplateStatus::Active->value)->first();
                if ($previous instanceof \stdClass) {
                    DB::table('document_templates')->where('id', $previous->id)->update(['status' => DocumentTemplateStatus::Retired->value, 'retired_at' => $now, 'updated_at' => $now]);
                    $this->audit->record('document_template.retired', AuditSubject::of('document_template', (string) $previous->id), ['status' => 'active'],
                        ['status' => 'retired', 'replaced_by_version' => $current->version], permission: self::MANAGE, actor: Actor::user($actorUserId));
                }
                DB::table('document_templates')->where('id', $current->id)->update(['status' => DocumentTemplateStatus::Active->value, 'activated_by' => $actorUserId,
                    'activated_at' => $now, 'updated_at' => $now]);
                $this->audit->record('document_template.activated', AuditSubject::of('document_template', $current->id), ['status' => 'draft'],
                    ['status' => 'active', 'version' => $current->version, 'retired_version' => $previous instanceof \stdClass ? (int) $previous->version : null],
                    permission: self::MANAGE, actor: Actor::user($actorUserId));

                return $this->find($current->id);
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'document_templates_one_active')) {
                throw new BusinessRuleViolation('DOCUMENT_TEMPLATE_ACTIVATION_CONFLICT', 'Another version was activated at the same time. Refresh the page and try again.');
            }
            throw $e;
        }
    }

    /**
     * The page a body and letterhead print with the demo variables of the code, without storing anything.
     *
     * @throws BusinessRuleViolation DOCUMENT_TEMPLATE_UNSAFE, DOCUMENT_TEMPLATE_RENDER_FAILED
     */
    public function preview(DocumentTemplateCode $code, string $locale, string $body, ?string $letterhead): RenderedDocument
    {
        $locale = self::locale($locale);

        return $this->renderer->document($body, self::blankToNull($letterhead), DocumentVariables::demo($code, $locale), $locale, CarbonImmutable::now());
    }

    /**
     * The starting templates (English and Bangla, every class, active version 1) for every code the tenant has no template for yet. Safe to rerun.
     */
    public function seedCurrentTenant(): int
    {
        $created = 0;
        foreach (DocumentTemplateCode::cases() as $code) {
            foreach (['en', 'bn'] as $locale) {
                if ($this->chain($code, null, $locale)->exists()) {
                    continue;
                }
                $this->insert($code, null, $locale, 1, DefaultDocumentTemplates::body($code, $locale), null, DocumentTemplateStatus::Active, null);
                $created++;
            }
        }

        return $created;
    }

    /** @throws BusinessRuleViolation DOCUMENT_TEMPLATE_UNSAFE, DOCUMENT_TEMPLATE_RENDER_FAILED, DOCUMENT_TEMPLATE_TOO_LONG */
    private function validate(DocumentTemplateCode $code, string $body, ?string $letterhead, string $locale): void
    {
        if (trim($body) === '') {
            throw new BusinessRuleViolation('DOCUMENT_TEMPLATE_EMPTY', 'The template body is empty. Write the document, or start again from the default template.');
        }
        if (mb_strlen($body) > self::BODY_MAX || ($letterhead !== null && mb_strlen($letterhead) > self::LETTERHEAD_MAX)) {
            throw new BusinessRuleViolation('DOCUMENT_TEMPLATE_TOO_LONG', 'Keep the body to '.number_format(self::BODY_MAX).' characters and the letterhead to '.number_format(self::LETTERHEAD_MAX).'.');
        }
        foreach (['letterhead' => $letterhead, 'body' => $body] as $part => $blade) {
            if ($blade === null) {
                continue;
            }
            $problems = TemplateBodyGuard::problems($blade, DocumentVariables::NAMES);
            if ($problems !== []) {
                throw new BusinessRuleViolation('DOCUMENT_TEMPLATE_UNSAFE', ($part === 'letterhead' ? 'Letterhead: ' : '').implode(' ', $problems));
            }
        }
        $this->preview($code, $locale, $body, $letterhead);
    }

    private function insert(DocumentTemplateCode $code, ?string $productClass, string $locale, int $version, string $body, ?string $letterhead, DocumentTemplateStatus $status, ?string $actorUserId): string
    {
        $id = (string) Str::uuid7();
        $now = CarbonImmutable::now();
        DB::table('document_templates')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'code' => $code->value, 'product_class' => $productClass, 'version' => $version,
            'engine' => 'blade_pdf', 'locale' => $locale, 'body' => $body, 'letterhead' => $letterhead, 'status' => $status->value, 'created_by' => $actorUserId,
            'created_at' => $now, 'updated_at' => $now, 'activated_by' => $status === DocumentTemplateStatus::Active ? $actorUserId : null,
            'activated_at' => $status === DocumentTemplateStatus::Active ? $now : null]);

        return $id;
    }

    private function chain(DocumentTemplateCode $code, ?string $productClass, string $locale): \Illuminate\Database\Query\Builder
    {
        return DB::table('document_templates')->where('code', $code->value)->where('locale', $locale)
            ->when($productClass === null, fn ($q) => $q->whereNull('product_class'), fn ($q) => $q->where('product_class', $productClass));
    }

    /** Serialises changes to one template (its versions) within the tenant. */
    private function lockChain(DocumentTemplateCode $code, ?string $productClass, string $locale): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['document_template:'.TenantContext::id().':'.$code->value.':'.($productClass ?? '').':'.$locale]);
    }

    private function refuseOpenDraft(DocumentTemplateCode $code, ?string $productClass, string $locale): void
    {
        $draft = $this->chain($code, $productClass, $locale)->where('status', DocumentTemplateStatus::Draft->value)->value('version');
        if ($draft !== null) {
            throw new BusinessRuleViolation('DOCUMENT_TEMPLATE_DRAFT_EXISTS', "Draft version {$draft} of this template is already open. Edit or activate that draft instead.");
        }
    }

    /** @return array<string, mixed> */
    private static function auditState(DocumentTemplate $template): array
    {
        return ['code' => $template->code->value, 'product_class' => $template->productClass, 'locale' => $template->locale, 'version' => $template->version,
            'status' => $template->status->value, 'body_sha256' => hash('sha256', $template->body), 'letterhead_sha256' => $template->letterhead === null ? null : hash('sha256', $template->letterhead)];
    }

    /** @throws BusinessRuleViolation DOCUMENT_TEMPLATE_LOCALE_UNKNOWN */
    private static function locale(string $locale): string
    {
        if (! in_array($locale, ['en', 'bn'], true)) {
            throw new BusinessRuleViolation('DOCUMENT_TEMPLATE_LOCALE_UNKNOWN', 'Choose English (en) or Bangla (bn).');
        }

        return $locale;
    }

    private static function blankToNull(?string $text): ?string
    {
        return $text === null || trim($text) === '' ? null : $text;
    }
}
