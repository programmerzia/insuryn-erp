<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Quotation\Http\Controllers;

use App\Http\Feedback\RiskProblems;
use App\Http\Pages\FormDefaults;
use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Product\Domain\Risk\RiskInputsInvalid;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use App\Modules\Insurance\Quotation\Domain\Enums\QuotationStatus;
use App\Modules\Insurance\Quotation\Domain\Models\Quotation;
use App\Modules\Insurance\Rating\Domain\RatingFailed;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 3 design §6 "Quote workbench (risk form left, live premium breakdown right with EN/BN labels)" (slice R4): the quotations queue, the
 * workbench for a new or saved quotation, live rating (JSON, nothing saved), save draft, issue and decline.
 */
final class QuotationPageController
{
    /** Who opens the quotation screens: people who quote (quotation.create) and, read-only, people who quote policies today. */
    public const AREA = ['quotation.create', 'policy.create'];

    public function __construct(
        private readonly QuotationService $quotations,
        private readonly PermissionChecker $permissions,
        private readonly FormDefaults $defaults,
    ) {}

    public function index(Request $request): Response
    {
        $reach = $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);
        $this->quotations->expireDue(app(BusinessClock::class)->today());
        $entity = PageSupport::entity();
        // G2: a branch-scoped user lists only their branches' quotations.
        $rows = $reach->constrain(DB::table('quotations as q'), 'q.entity_id', 'q.branch_id')->leftJoin('parties as c', 'c.id', '=', 'q.customer_party_id')->leftJoin('products as p', 'p.id', '=', 'q.product_id')
            ->leftJoin('producers as pr', 'pr.id', '=', 'q.producer_id')->leftJoin('users as u', 'u.id', '=', 'q.created_by')
            ->where('q.entity_id', $entity['id'])->orderByDesc('q.created_at')->limit(PageSupport::LIST_PAGE_SIZE)
            ->get(['q.id', 'q.number', 'q.status', 'q.inception', 'q.valid_until', 'q.sum_insured_minor', 'q.gross_premium_minor', 'q.currency', 'q.producer_eligible',
                'q.created_at', 'c.display_name as customer', 'p.code as product_code', 'p.name as product_name', 'pr.code as producer_code', 'u.name as created_by']);

        return Inertia::render('quotations/Index', [
            'currency' => $entity['currency'],
            'statuses' => array_column(QuotationStatus::cases(), 'value'),
            'canCreate' => in_array(QuotationService::PERMISSION, $this->permissions->permissionsOf(PageSupport::actor($request)), true),
            'quotations' => $rows->map(fn (object $q): array => [
                'id' => (string) $q->id, 'number' => $q->number === null ? null : (string) $q->number, 'status' => (string) $q->status,
                'customer' => $q->customer === null ? null : (string) $q->customer, 'product' => trim("{$q->product_code} · {$q->product_name}", ' ·'),
                'producer' => $q->producer_code === null ? null : (string) $q->producer_code, 'producer_eligible' => $q->producer_eligible === null ? null : (bool) $q->producer_eligible,
                'inception' => (string) $q->inception, 'valid_until' => $q->valid_until === null ? null : (string) $q->valid_until,
                'sum_insured' => $q->sum_insured_minor === null ? null : PageSupport::money((int) $q->sum_insured_minor, (string) $q->currency),
                'gross_premium' => $q->gross_premium_minor === null ? null : PageSupport::money((int) $q->gross_premium_minor, (string) $q->currency),
                'created_at' => CarbonImmutable::parse((string) $q->created_at)->toDateString(), 'created_by' => (string) $q->created_by,
            ])->values()->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->permissions->authorizeArea(PageSupport::actor($request), [QuotationService::PERMISSION]);

        return $this->workbench($request, null);
    }

    public function show(Request $request, string $quotation): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeArea($actor, self::AREA);
        $this->quotations->expireDue(app(BusinessClock::class)->today());
        $model = Quotation::query()->findOrFail($quotation);
        $this->permissions->authorizeAny($actor, self::AREA, AuthorizationScope::branch($model->entity_id, $model->branch_id)); // G2: another branch's quotation is 403

        return $this->workbench($request, $model);
    }

    /** POST /quotations/rate — the live premium for the workbench; nothing is saved. Field problems come back per field. */
    public function rate(Request $request): JsonResponse
    {
        $terms = $this->terms($request);
        try {
            $result = $this->quotations->rate($terms, PageSupport::actor($request));
        } catch (RiskInputsInvalid $invalid) {
            return response()->json(RiskProblems::json($invalid, RiskProblems::locale($request)), 422); // follow-up H2: each field in words, with its label
        } catch (RatingFailed $failed) {
            return response()->json(['reason' => $failed->reasonCode, 'message' => $failed->getMessage(), 'errors' => (object) []], 422);
        }

        return response()->json(['result' => $result->toArray()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $quotation = $this->quotations->saveDraft($this->terms($request), null, PageSupport::actor($request));
        $this->defaults->remember(PageSupport::actor($request), FormDefaults::LAST_PRODUCT, $quotation->product_id);

        return $this->afterSave($request, $quotation);
    }

    public function update(Request $request, string $quotation): RedirectResponse
    {
        $saved = $this->quotations->saveDraft($this->terms($request), $quotation, PageSupport::actor($request));
        $this->defaults->remember(PageSupport::actor($request), FormDefaults::LAST_PRODUCT, $saved->product_id);

        return $this->afterSave($request, $saved);
    }

    public function issue(Request $request, string $quotation): RedirectResponse
    {
        $this->quotations->issue($quotation, app(BusinessClock::class)->today(), PageSupport::actor($request));

        return redirect("/quotations/{$quotation}")->with('status', 'Quotation issued.');
    }

    public function decline(Request $request, string $quotation): RedirectResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->quotations->decline($quotation, $data['reason'], PageSupport::actor($request));

        return redirect("/quotations/{$quotation}")->with('status', 'Quotation declined.');
    }

    /** Save draft, or save and issue (`intent=issue`): a refused issue keeps the saved draft open with the reason. */
    private function afterSave(Request $request, Quotation $quotation): RedirectResponse
    {
        if ($request->input('intent') !== 'issue') {
            return redirect("/quotations/{$quotation->id}")->with('status', 'Draft saved.');
        }
        try {
            $this->quotations->issue($quotation->id, app(BusinessClock::class)->today(), PageSupport::actor($request));
        } catch (RiskInputsInvalid $invalid) {
            return redirect("/quotations/{$quotation->id}")->withErrors(RiskProblems::formErrorsFor($invalid, RiskProblems::locale($request)));
        } catch (BusinessRuleViolation $refused) {
            return redirect("/quotations/{$quotation->id}")->withErrors(['form' => \App\Http\Feedback\ReasonMessages::forPeople($refused->reasonCode, $refused->getMessage()), 'reason' => $refused->reasonCode]);
        }

        return redirect("/quotations/{$quotation->id}")->with('status', 'Quotation issued.');
    }

    private function terms(Request $request): QuotationTerms
    {
        /** @var array{branch_id: string, product_id: string, customer_party_id?: string|null, producer_id?: string|null, inception: string, risk_inputs?: array<string, mixed>|null, coverages?: list<string>|null} $data */
        $data = $request->validate([
            'branch_id' => ['required', 'uuid'], 'product_id' => ['required', 'uuid'], 'customer_party_id' => ['nullable', 'uuid'], 'producer_id' => ['nullable', 'uuid'],
            'inception' => ['required', 'date_format:Y-m-d'], 'risk_inputs' => ['nullable', 'array'], 'coverages' => ['nullable', 'array', 'max:50'], 'coverages.*' => ['string', 'max:64'],
        ]);

        return new QuotationTerms($data['branch_id'], $data['product_id'], $data['customer_party_id'] ?? null, $data['producer_id'] ?? null,
            CarbonImmutable::parse($data['inception']), $data['risk_inputs'] ?? [], $data['coverages'] ?? []);
    }

    private function workbench(Request $request, ?Quotation $quotation): Response
    {
        $actor = PageSupport::actor($request);
        $entity = PageSupport::entity();
        $today = app(BusinessClock::class)->today();
        $may = fn (string $permission): bool => $quotation === null
            ? in_array($permission, $this->permissions->permissionsOf($actor), true)
            : $this->permissions->has($actor, $permission, AuthorizationScope::branch($quotation->entity_id, $quotation->branch_id));
        $status = $quotation?->status;

        return Inertia::render('quotations/Workbench', [
            'currency' => $entity['currency'],
            'today' => $today->toDateString(),
            'validDays' => QuotationService::validDays(),
            'branches' => $this->permissions->reach($actor, self::AREA)->constrain(DB::table('branches'), 'entity_id', 'id')->where('entity_id', $entity['id'])->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $b): array => (array) $b)->values()->all(),
            'products' => self::products(),
            'quotation' => $quotation === null ? null : $this->present($quotation),
            // Flow fix X6: a new quotation starts with the product this user saved last.
            'lastProductId' => $quotation === null ? $this->defaults->remembered($actor, FormDefaults::LAST_PRODUCT) : null,
            // Printing the issued quotation (composed at the app layer with the document generator).
            'generation' => $quotation === null ? null : app(\App\Http\Documents\GeneratedDocumentsController::class)->forQuotation($actor, $quotation->id),
            'can' => [
                'edit' => ($status === null || $status === QuotationStatus::Draft) && $may(QuotationService::PERMISSION),
                'issue' => ($status === null || $status === QuotationStatus::Draft) && $may(QuotationService::PERMISSION),
                'decline' => in_array($status, [QuotationStatus::Draft, QuotationStatus::Issued], true) && $may(QuotationService::PERMISSION),
                // Slice R5: an issued quotation within its validity becomes a proposal.
                'convert' => $status === QuotationStatus::Issued && $may(QuotationService::PERMISSION),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Quotation $quotation): array
    {
        $customer = $quotation->customer_party_id === null ? null : DB::table('parties')->where('id', $quotation->customer_party_id)->first(['id', 'display_name', 'kind', 'tax_id']);
        $producer = $quotation->producer_id === null ? null : DB::table('producers as a')->join('parties as p', 'p.id', '=', 'a.party_id')->where('a.id', $quotation->producer_id)->first(['a.id', 'a.code', 'p.display_name']);

        return [
            'id' => $quotation->id, 'number' => $quotation->number, 'status' => $quotation->status->value, 'branch_id' => $quotation->branch_id, 'product_id' => $quotation->product_id,
            'product_version_id' => $quotation->product_version_id, 'inception' => $quotation->inception->toDateString(), 'valid_until' => $quotation->valid_until?->toDateString(),
            'customer' => $customer === null ? null : ['id' => (string) $customer->id, 'label' => (string) $customer->display_name, 'detail' => ucfirst((string) $customer->kind)],
            'producer' => $producer === null ? null : ['id' => (string) $producer->id, 'label' => "{$producer->code} · {$producer->display_name}", 'detail' => 'Producer'],
            'risk_inputs' => $quotation->risk_inputs, 'coverages' => $quotation->coverages, 'rating_result' => $quotation->rating_result === null ? null : $quotation->ratingResult()?->toArray(),
            'producer_eligible' => $quotation->producer_eligible, 'producer_eligibility_note' => $quotation->producer_eligibility_note,
            'decline_reason' => $quotation->decline_reason, 'issued_at' => $quotation->issued_at?->toIso8601String(),
            'proposal_id' => ($proposal = DB::table('proposals')->where('quotation_id', $quotation->id)->value('id')) === null ? null : (string) $proposal,
            // Slice R9: the expiring policy a renewal quotation renews.
            'renewal_of' => $quotation->renewal_of_policy_id === null ? null
                : ['id' => $quotation->renewal_of_policy_id, 'number' => (string) DB::table('policies')->where('id', $quotation->renewal_of_policy_id)->value('number')],
        ];
    }

    /**
     * Products that can be quoted: versions with a product class (and so a risk schema and a rating plan), with their coverages, by product.
     *
     * @return list<array{id: string, code: string, name: string, versions: list<array<string, mixed>>}>
     */
    private static function products(): array
    {
        $versions = DB::table('product_versions as v')->join('products as p', 'p.id', '=', 'v.product_id')->whereNotNull('v.class_code')
            ->where(fn ($q) => $q->whereNull('v.effective_to')->orWhere('v.effective_to', '>', app(BusinessClock::class)->today()->toDateString()))
            ->orderBy('p.code')->orderBy('v.effective_from')->get(['v.id', 'v.product_id', 'v.version', 'v.effective_from', 'v.effective_to', 'v.class_code', 'v.risk_schema', 'p.code', 'p.name']);
        $coverages = DB::table('coverages')->whereIn('product_version_id', $versions->pluck('id'))->orderBy('sort_order')->orderBy('code')
            ->get(['product_version_id', 'code', 'name_en', 'name_bn', 'mandatory'])->groupBy('product_version_id');
        $products = [];
        foreach ($versions as $v) {
            $products[(string) $v->product_id] ??= ['id' => (string) $v->product_id, 'code' => (string) $v->code, 'name' => (string) $v->name, 'versions' => []];
            $products[(string) $v->product_id]['versions'][] = [
                'id' => (string) $v->id, 'version' => (int) $v->version, 'effective_from' => (string) $v->effective_from, 'effective_to' => $v->effective_to === null ? null : (string) $v->effective_to,
                'class_code' => (string) $v->class_code, 'risk_schema' => json_decode((string) ($v->risk_schema ?? '[]'), true) ?? [],
                'coverages' => array_values(($coverages[(string) $v->id] ?? collect())->map(fn (object $c): array => ['code' => (string) $c->code, 'name_en' => (string) $c->name_en,
                    'name_bn' => (string) $c->name_bn, 'mandatory' => (bool) $c->mandatory])->all()),
            ];
        }

        return array_values($products);
    }
}
