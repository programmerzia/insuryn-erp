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
        $this->permissions->authorizeAny($actor, PolicyPageController::AREA);
        $model = DB::table('policies')->where('id', $policy)->first(['id']);
        abort_if($model === null, 404);
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
        $this->permissions->authorizeAny($actor, CollectionsPageController::AREA);
        abort_if(! DB::table('receipts')->where('id', $receipt)->exists(), 404);
        /** @var array{locale: string} $data */
        $data = $request->validate(['locale' => ['required', Rule::in(['en', 'bn'])]]);
        $generated = $this->generator->generate(DocumentTemplateCode::Receipt, 'receipt', $receipt, $actor, $data['locale']);

        return redirect("/receipts/{$receipt}?tab=documents")->with('status', self::generatedMessage($generated));
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
