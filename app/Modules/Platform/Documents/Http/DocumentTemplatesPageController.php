<?php

declare(strict_types=1);

namespace App\Modules\Platform\Documents\Http;

use App\Http\Pages\PageSupport;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Documents\Rendering\PdfRenderer;
use App\Modules\Platform\Documents\Templates\DocumentTemplate;
use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use App\Modules\Platform\Documents\Templates\DocumentTemplates;
use App\Modules\Platform\Documents\Templates\DocumentVariables;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Documents → Templates (slice R8): the templates by code, class, locale, version and status; the editor with the body, the letterhead, the
 * variables of the code and a live preview with demo data; save as draft and activate. Everything needs document.manage_templates (A-101).
 */
final class DocumentTemplatesPageController
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly DocumentTemplates $templates,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize($request);
        $names = DB::table('users')->pluck('name', 'id');

        return Inertia::render('documents/templates/Index', [
            'templates' => array_map(fn (DocumentTemplate $t): array => self::row($t, $names->all()), $this->templates->all()),
            'codes' => array_map(fn (DocumentTemplateCode $c): array => ['value' => $c->value, 'label' => $c->title()], DocumentTemplateCode::cases()),
            'classes' => self::classes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize($request);
        /** @var array{code: string, product_class?: string|null, locale: string} $data */
        $data = $request->validate(['code' => ['required', Rule::in(DocumentTemplateCode::values())], 'product_class' => ['nullable', 'string', 'max:40'],
            'locale' => ['required', Rule::in(['en', 'bn'])]], ['code.*' => 'Choose a document type.', 'locale.*' => 'Choose English or Bangla.']);
        $template = $this->templates->create($data['code'], $data['product_class'] ?? null, $data['locale'], null, null, PageSupport::actor($request));

        return redirect("/documents/templates/{$template->id}")->with('status', "Draft version {$template->version} created.");
    }

    public function show(Request $request, string $template): Response
    {
        $this->authorize($request);
        $model = $this->findOr404($template);
        $names = DB::table('users')->pluck('name', 'id')->all();

        return Inertia::render('documents/templates/Edit', [
            'template' => [...self::row($model, $names), 'body' => $model->body, 'letterhead' => $model->letterhead ?? ''],
            'versions' => array_map(fn (DocumentTemplate $t): array => self::row($t, $names), $this->templates->versions($model)),
            'variables' => DocumentVariables::documentation($model->code),
        ]);
    }

    public function update(Request $request, string $template): RedirectResponse
    {
        $this->authorize($request);
        /** @var array{body: string, letterhead?: string|null} $data */
        $data = $request->validate(['body' => ['required', 'string'], 'letterhead' => ['nullable', 'string']], ['body.required' => 'The template body is empty.']);
        $before = $this->findOr404($template);
        $saved = $this->templates->saveDraft($before->id, $data['body'], $data['letterhead'] ?? null, PageSupport::actor($request));

        return redirect("/documents/templates/{$saved->id}")->with('status', $saved->id === $before->id ? "Draft version {$saved->version} saved." : "Saved as draft version {$saved->version}; version {$before->version} is unchanged.");
    }

    public function activate(Request $request, string $template): RedirectResponse
    {
        $this->authorize($request);
        $activated = $this->templates->activate($this->findOr404($template)->id, PageSupport::actor($request));

        return redirect("/documents/templates/{$activated->id}")->with('status', "Version {$activated->version} is now in use.");
    }

    /** Live preview: the page the typed body and letterhead print with demo data, as HTML for the editor's frame. Nothing is stored. */
    public function preview(Request $request): JsonResponse
    {
        $this->authorize($request);
        /** @var array{code: string, locale: string, body?: string|null, letterhead?: string|null} $data */
        $data = $request->validate(['code' => ['required', Rule::in(DocumentTemplateCode::values())], 'locale' => ['required', Rule::in(['en', 'bn'])],
            'body' => ['nullable', 'string', 'max:200000'], 'letterhead' => ['nullable', 'string', 'max:50000']]);
        try {
            $rendered = $this->templates->preview(DocumentTemplateCode::from($data['code']), $data['locale'], (string) ($data['body'] ?? ''), $data['letterhead'] ?? null);
        } catch (BusinessRuleViolation $e) {
            return response()->json(['html' => null, 'reason' => $e->reasonCode, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['html' => $rendered->html, 'reason' => null, 'message' => null]);
    }

    /** A saved version with demo data: HTML, or the real PDF with ?format=pdf. Nothing is stored. */
    public function previewSaved(Request $request, string $template, PdfRenderer $pdf): HttpResponse
    {
        $this->authorize($request);
        $model = $this->findOr404($template);
        $format = $request->query('format') === 'pdf' ? 'pdf' : 'html';
        $rendered = $this->templates->preview($model->code, $model->locale, $model->body, $model->letterhead);
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; font-src data:; img-src data:"];

        return $format === 'pdf'
            ? response($pdf->render($rendered->html), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$model->code->value.'-v'.$model->version.'-'.$model->locale.'-preview.pdf"'] + $headers)
            : response($rendered->html, 200, ['Content-Type' => 'text/html; charset=utf-8'] + $headers);
    }

    private function authorize(Request $request): string
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorize($actor, DocumentTemplates::MANAGE);

        return $actor;
    }

    private function findOr404(string $id): DocumentTemplate
    {
        try {
            return $this->templates->find($id);
        } catch (BusinessRuleViolation) {
            abort(404);
        }
    }

    /**
     * @param array<array-key, mixed> $names user id => name
     * @return array<string, mixed>
     */
    private static function row(DocumentTemplate $t, array $names): array
    {
        $name = fn (?string $id): ?string => $id === null ? null : (isset($names[$id]) ? (string) $names[$id] : null);

        return ['id' => $t->id, 'code' => $t->code->value, 'title' => $t->code->title(), 'product_class' => $t->productClass, 'locale' => $t->locale, 'version' => $t->version,
            'status' => $t->status->value, 'updated_at' => $t->updatedAt->toIso8601String(), 'created_by' => $t->createdBy === null ? 'the system' : ($name($t->createdBy) ?? 'someone who has left'),
            'activated_at' => $t->activatedAt?->toIso8601String(), 'activated_by' => $t->activatedAt === null ? null : ($t->activatedBy === null ? 'the system' : $name($t->activatedBy))];
    }

    /** @return list<array{code: string, name: string}> */
    private static function classes(): array
    {
        return array_values(DB::table('product_classes')->where('status', 'active')->orderBy('sort_order')->orderBy('code')->get(['code', 'name_en'])
            ->map(fn (object $c): array => ['code' => (string) $c->code, 'name' => (string) $c->name_en])->all());
    }
}
