<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Http\Controllers;

use App\Http\Pages\FormDefaults;
use App\Http\Pages\NextSteps;
use App\Http\Pages\ObjectDocuments;
use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Policy\Application\PayerShare;
use App\Modules\Insurance\Policy\Application\PayerStatementQuery;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Policy\Domain\Enums\PolicyStatus;
use App\Modules\Insurance\Policy\Domain\Models\Installment;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Policy\Domain\Models\PolicyTransaction;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
use App\Modules\Insurance\Product\Domain\Risk\RiskInputsInvalid;
use App\Modules\Insurance\Rating\Domain\RatingFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Policies screens (design §5.4): list and filter, quote (with payers), detail with transactions, installments and the lifecycle actions.
 * Slice R7: the quote form lists only products without a rating plan (rated products are quoted in the quote workbench); a rated policy's page has a Rating tab
 * (the frozen breakdown, risk, special terms and every endorsement's re-rating) and its Endorse changes the risk, re-rated live, instead of typing a premium.
 */
final class PolicyPageController
{
    public const AREA = ['policy.create', 'policy.issue', 'policy.endorse', 'policy.cancel', 'receipt.create', 'receipt.allocate', 'reports.financial'];

    /** ASSUMPTION: A-53 — who may attach documents to a policy is not specified: the people who quote, issue or endorse it. Reading follows AREA. */
    public const ATTACH_DOCUMENTS = ['policy.create', 'policy.issue', 'policy.endorse'];

    public function __construct(
        private readonly PolicyLifecycle $lifecycle,
        private readonly PayerStatementQuery $payers,
        private readonly PermissionChecker $permissions,
        private readonly NextSteps $nextSteps,
        private readonly FormDefaults $defaults,
    ) {}

    public function index(Request $request): Response
    {
        // G2: opens for the area's permissions in any scope; a branch-scoped user lists only their branches' policies.
        $reach = $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);
        $status = (string) $request->query('status', '');
        $search = trim((string) $request->query('search', ''));
        $entity = PageSupport::entity();
        $page = $reach->constrain(DB::table('policies as p'), 'p.entity_id', 'p.branch_id')->leftJoin('parties as h', 'h.id', '=', 'p.policyholder_party_id')->leftJoin('products as pr', 'pr.id', '=', 'p.product_id')
            ->where('p.entity_id', $entity['id'])->when($status !== '', fn ($q) => $q->where('p.status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('p.number ilike ?', ["%{$search}%"])->orWhereRaw('h.display_name ilike ?', ["%{$search}%"])))
            ->orderByDesc('p.created_at')->select(['p.id', 'p.number', 'p.status', 'p.inception', 'p.expiry', 'p.gross_premium_minor', 'p.currency', 'h.display_name as policyholder', 'pr.code as product_code'])
            ->paginate(PageSupport::LIST_PAGE_SIZE)->withQueryString();

        return Inertia::render('policies/Index', [
            'filters' => ['status' => $status, 'search' => $search],
            'statuses' => array_column(PolicyStatus::cases(), 'value'),
            'policies' => PageSupport::page($page, self::rows($page->items())),
            // GA-11: "New quote" opens the rated quote workbench; the typed-premium form is offered only while a product has no rating plan.
            'unratedProducts' => self::unratedProducts()->count(),
        ]);
    }

    /** Products none of whose versions has a product class, so they are priced by a typed premium (A-115). */
    private static function unratedProducts(): \Illuminate\Database\Query\Builder
    {
        return DB::table('products')->whereNotExists(fn ($q) => $q->from('product_versions as v')->whereColumn('v.product_id', 'products.id')->whereNotNull('v.class_code'));
    }

    public function create(Request $request): Response
    {
        $reach = $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);

        return Inertia::render('policies/Create', [
            'entity' => PageSupport::entity(),
            // Flow fix X6: the form starts with the product this user quoted last.
            'lastProductId' => $this->defaults->remembered(PageSupport::actor($request), FormDefaults::LAST_PRODUCT),
            'branches' => $reach->constrain(DB::table('branches'), 'entity_id', 'id')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $b): array => (array) $b)->values()->all(),
            // Slice R7: typing a premium stays only for products without a rating plan.
            'products' => self::unratedProducts()->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $p): array => (array) $p)->values()->all(),
            'ratedProducts' => DB::table('products')->whereExists(fn ($q) => $q->from('product_versions as v')->whereColumn('v.product_id', 'products.id')->whereNotNull('v.class_code'))->count(),
            'parties' => DB::table('parties')->orderBy('display_name')->get(['id', 'display_name'])->map(fn (object $p): array => (array) $p)->values()->all(),
            'agents' => DB::table('producers as a')->join('parties as p', 'p.id', '=', 'a.party_id')->where('a.status', 'active')->orderBy('a.code')
                ->get(['a.id', 'a.code', 'p.display_name'])->map(fn (object $a): array => (array) $a)->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var array{branch_id: string, product_id: string, policyholder_party_id: string, agent_id?: string|null, inception: string, premium: string, installment_count: int, payers?: list<array{party_id: string, share_percent: string}>} $data */
        $data = $request->validate(['branch_id' => ['required', 'uuid'], 'product_id' => ['required', 'uuid'], 'policyholder_party_id' => ['required', 'uuid'],
            'agent_id' => ['nullable', 'uuid'], 'inception' => ['required', 'date_format:Y-m-d'], 'premium' => ['required', 'string'],
            'installment_count' => ['required', 'integer', 'min:1', 'max:12'], 'payers' => ['sometimes', 'array', 'max:10'],
            'payers.*.party_id' => ['required', 'uuid'], 'payers.*.share_percent' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:100']]);
        $entity = PageSupport::entity();
        $payers = array_map(fn (array $p): PayerShare => new PayerShare($p['party_id'], self::basisPoints($p['share_percent'])), $data['payers'] ?? []);
        $policy = $this->lifecycle->quote(new QuoteRequest($entity['id'], $data['branch_id'], $data['product_id'], $data['policyholder_party_id'], $data['agent_id'] ?? null,
            CarbonImmutable::parse($data['inception']), PageSupport::minor('premium', $data['premium'], $entity['currency']), $entity['currency'], (int) $data['installment_count'], $payers),
            PageSupport::actor($request));
        $this->defaults->remember(PageSupport::actor($request), FormDefaults::LAST_PRODUCT, $data['product_id']);

        return redirect("/policies/{$policy->id}")->with('status', 'Quote created.');
    }

    public function show(Request $request, string $policy): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeArea($actor, self::AREA);
        $model = Policy::query()->findOrFail($policy);
        $this->permissions->authorizeAny($actor, self::AREA, \App\Modules\Platform\Authorization\AuthorizationScope::branch($model->entity_id, $model->branch_id)); // G2: another branch's policy is 403
        $money = fn (int $minor): string => PageSupport::money($minor, $model->currency);
        $names = DB::table('parties')->pluck('display_name', 'id');
        $can = fn (string $permission): bool => $this->permissions->has($actor, $permission, \App\Modules\Platform\Authorization\AuthorizationScope::branch($model->entity_id, $model->branch_id));
        $status = $model->status;

        return Inertia::render('policies/Show', [
            'policy' => ['id' => $model->id, 'number' => $model->number, 'status' => $status->value, 'version' => $model->version, 'inception' => $model->inception->toDateString(),
                'expiry' => $model->expiry->toDateString(), 'channel' => $model->channel, 'currency' => $model->currency, 'policyholder' => (string) ($names[$model->policyholder_party_id] ?? ''),
                'product_code' => (string) DB::table('products')->where('id', $model->product_id)->value('code'), 'agent_code' => $model->agent_id === null ? null : (string) DB::table('producers')->where('id', $model->agent_id)->value('code'),
                'gross_premium' => $money($model->gross_premium_minor), 'net_premium' => $money($model->net_premium_minor), 'tax' => $money($model->tax_minor), 'stamp_duty' => $money($model->stamp_duty_minor),
                'cancel_date' => $model->cancel_date?->toDateString()],
            'transactions' => $model->transactions()->get()->map(fn (PolicyTransaction $t): array => ['id' => $t->id, 'type' => $t->type->value, 'effective_date' => $t->effective_date->toDateString(),
                'premium_delta' => $money($t->premium_delta_minor), 'reason' => $t->reason])->values()->all(),
            'installments' => Installment::query()->where('policy_id', $model->id)->orderBy('no')->orderBy('id')->get()->map(fn (Installment $i): array => ['id' => $i->id, 'no' => $i->no,
                'label' => \App\Modules\Insurance\Policy\Domain\InstallmentLabel::short($i->no, $i->endorsement_no),
                'payer' => (string) ($names[$i->payer_party_id] ?? ''), 'due_date' => $i->due_date->toDateString(), 'amount' => $money($i->amount_minor), 'paid' => $money($i->paid_minor),
                'credited' => $money($i->cancelled_minor), 'outstanding' => $money($i->outstanding()), 'status' => $i->status->value])->values()->all(),
            'payers' => array_map(fn (array $p): array => ['name' => $p['name'], 'share_percent' => sprintf('%d.%02d', intdiv($p['share_bp'], 100), $p['share_bp'] % 100), 'billed' => $money($p['billed_minor']),
                'paid' => $money($p['paid_minor']), 'outstanding' => $money($p['outstanding_minor'])], $this->payers->forPolicy($model->id)['payers']),
            'documentUpload' => array_any(self::ATTACH_DOCUMENTS, $can) ? "/policies/{$model->id}/documents" : null,
            'rating' => $this->rating($model),
            'today' => app(BusinessClock::class)->today()->toDateString(),
            'actions' => [
                'issue' => $status === PolicyStatus::Quote && $can('policy.issue'),
                // Flow fix X1: record the premium receipt, prefilled from this policy, while money is outstanding.
                'record_receipt' => $this->nextSteps->canRecordReceipt($actor, $model->id),
                'endorse' => $model->rating_result === null && in_array($status, [PolicyStatus::Issued, PolicyStatus::Active], true) && $can('policy.endorse'),
                'endorse_risk' => $model->rating_result !== null && in_array($status, [PolicyStatus::Issued, PolicyStatus::Active], true) && $can('policy.endorse'),
                'cancel' => in_array($status, [PolicyStatus::Issued, PolicyStatus::Active], true) && $can('policy.cancel'),
                'lapse' => $status === PolicyStatus::Active && $can('policy.cancel'),
                'reinstate' => $status === PolicyStatus::Lapsed && $can('policy.issue'),
                // Slice R9 (A-130): a rated policy renews through its renewal quotation (Renewals), not by a typed renewal quote.
                'renew' => $model->rating_result === null && in_array($status, [PolicyStatus::Active, PolicyStatus::Expired], true) && $can('policy.create'),
            ],
        ]);
    }

    public function issue(Request $request, string $policy): RedirectResponse
    {
        /** @var array{on: string} $data */
        $data = $request->validate(['on' => ['required', 'date_format:Y-m-d']]);
        $actor = PageSupport::actor($request);
        $issued = $this->lifecycle->issue($policy, CarbonImmutable::parse($data['on']), $actor);

        // Flow fix X1: the premium receipt is the next step (Part A step 3).
        return redirect("/policies/{$policy}")->with('status', "Policy {$issued->number} issued.")->with('next', $this->nextSteps->afterIssue($actor, $policy));
    }

    public function endorse(Request $request, string $policy): RedirectResponse
    {
        /** @var array{effective_date: string, premium_delta: string, reason: string} $data */
        $data = $request->validate(['effective_date' => ['required', 'date_format:Y-m-d'], 'premium_delta' => ['required', 'string'], 'reason' => ['required', 'string', 'max:1000']]);
        $currency = (string) Policy::query()->whereKey($policy)->value('currency');
        $delta = PageSupport::minor('premium_delta', $data['premium_delta'], $currency, true);
        $endorsed = $this->lifecycle->endorse($policy, CarbonImmutable::parse($data['effective_date']), $delta, $data['reason'], PageSupport::actor($request));

        return $this->endorsed($request, $endorsed, "/policies/{$policy}", $delta > 0);
    }

    /** Slice R7: the re-rating of a risk change, nothing written (JSON for the endorse drawer): {rating} or 422 {reason, message, errors}. */
    public function endorsementRating(Request $request, string $policy): JsonResponse
    {
        [$date, $inputs, $coverages] = self::riskChange($request);
        try {
            $rating = $this->lifecycle->rateEndorsement($policy, $date, $inputs, PageSupport::actor($request), $coverages);
        } catch (RiskInputsInvalid $invalid) {
            return response()->json(\App\Http\Feedback\RiskProblems::json($invalid, \App\Http\Feedback\RiskProblems::locale($request)), 422); // follow-up H2: in words, with labels
        } catch (RatingFailed $failed) {
            return response()->json(['reason' => $failed->reasonCode, 'message' => $failed->getMessage(), 'errors' => (object) []], 422);
        }

        return response()->json(['rating' => $rating->toArray()]);
    }

    /** Slice R7: endorse a rated policy's risk (re-rated, POLICY_ENDORSED for the change; journal preview through moves-money). */
    public function endorseRisk(Request $request, string $policy): RedirectResponse
    {
        [$date, $inputs, $coverages] = self::riskChange($request);
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $before = (int) Policy::query()->whereKey($policy)->value('gross_premium_minor');
        $endorsed = $this->lifecycle->endorseRisk($policy, $date, $inputs, $data['reason'], PageSupport::actor($request), $coverages);

        return $this->endorsed($request, $endorsed, "/policies/{$policy}?tab=rating", $endorsed->gross_premium_minor > $before);
    }

    /** GA-25: the endorsement's number in the confirmation, and the next steps — collect an increase, print the endorsement. */
    private function endorsed(Request $request, Policy $policy, string $url, bool $increased): RedirectResponse
    {
        $n = PolicyLifecycle::endorsementNo($policy);
        $transaction = (string) PolicyTransaction::query()->where('policy_id', $policy->id)->where('type', 'endorsement')->orderByDesc('created_at')->orderByDesc('id')->value('id');

        return redirect($url)->with('status', "Endorsement {$policy->number}/E{$n} recorded.")
            ->with('next', $this->nextSteps->afterEndorsement(PageSupport::actor($request), $policy->id, $transaction, $increased));
    }

    /** @return array{0: CarbonImmutable, 1: array<string, mixed>, 2: list<string>|null} */
    private static function riskChange(Request $request): array
    {
        /** @var array{effective_date: string, risk_inputs?: array<string, mixed>|null, coverages?: list<string>|null} $data */
        $data = $request->validate(['effective_date' => ['required', 'date_format:Y-m-d'], 'risk_inputs' => ['present', 'array'], 'coverages' => ['nullable', 'array', 'max:50'],
            'coverages.*' => ['string', 'max:64']]);

        return [CarbonImmutable::parse($data['effective_date']), $data['risk_inputs'] ?? [], $data['coverages'] ?? null];
    }

    /**
     * Slice R7: the Rating tab of a policy issued from a proposal — null for products without a rating plan.
     *
     * @return array<string, mixed>|null
     */
    private function rating(Policy $policy): ?array
    {
        $result = $policy->ratingResult();
        if ($result === null) {
            return null;
        }
        $version = ProductVersion::query()->whereKey($policy->product_version_id)->firstOrFail();
        $endorsements = PolicyTransaction::query()->where('policy_id', $policy->id)->whereNotNull('rating_result')->orderBy('created_at')->orderBy('id')->get();
        $before = $result;
        $rows = [];
        foreach ($endorsements as $endorsement) {
            $after = \App\Modules\Insurance\Rating\Domain\RatingResult::fromArray($endorsement->rating_result ?? []);
            $rows[] = ['id' => $endorsement->id, 'effective_date' => $endorsement->effective_date->toDateString(), 'reason' => $endorsement->reason, 'rating' => [
                'basis' => (string) $endorsement->rating_basis, 'before' => $before->toArray(), 'after' => $after->toArray(),
                'change' => ['net_minor' => $endorsement->net_delta_minor, 'tax_minor' => $endorsement->tax_delta_minor, 'stamp_duty_minor' => $endorsement->stamp_duty_delta_minor,
                    'gross_minor' => $endorsement->premium_delta_minor],
                // Charged pro rata when the change is not the whole difference of the two ratings (erp.policies.endorsement_premium at the time, A-119).
                'pro_rata' => $endorsement->net_delta_minor !== $after->netPremiumMinor - $before->netPremiumMinor,
                'days_charged' => (int) $endorsement->effective_date->diffInDays($policy->expiry) + 1, 'days_in_term' => (int) $policy->inception->diffInDays($policy->expiry) + 1,
            ]];
            $before = $after;
        }
        $number = fn (string $table, ?string $id): ?array => $id === null ? null : ['id' => $id, 'number' => (string) DB::table($table)->where('id', $id)->value('number')];
        $risk = [];
        foreach ($version->riskSchema()->fields as $field) {
            $value = $result->riskInputs[$field->key] ?? null;
            $risk[] = ['label_en' => $field->labelEn, 'label_bn' => $field->labelBn, 'value' => match (true) {
                $value === null => '—',
                $field->type === \App\Modules\Insurance\Product\Domain\Enums\RiskFieldType::Money && is_int($value) => PageSupport::money($value, $policy->currency),
                $field->type === \App\Modules\Insurance\Product\Domain\Enums\RiskFieldType::Select => (string) (array_column($field->options, 'label_en', 'value')[(string) $value] ?? $value),
                $field->type === \App\Modules\Insurance\Product\Domain\Enums\RiskFieldType::Boolean => $value === true ? 'Yes' : 'No',
                default => (string) $value,
            }];
        }

        return [
            'result' => $result->toArray(), 'risk' => $risk,
            'special_terms' => array_map(fn (array $t): string => $t['text'], $policy->special_terms ?? []),
            'issue_basis' => $policy->issue_basis, 'premium_received_reference' => $policy->premium_received_reference,
            'proposal' => $number('proposals', $policy->proposal_id), 'quotation' => $number('quotations', $policy->quotation_id),
            'uses_current_tariff' => $version->endorsement_uses_current_tariff,
            'endorsements' => $rows,
            'schema' => $version->risk_schema ?? [], 'current_inputs' => $before->riskInputs,
            'coverages' => array_values($version->coverageDefinitions()->get()->map(fn ($c): array => ['code' => $c->code, 'name_en' => $c->name_en, 'name_bn' => $c->name_bn, 'mandatory' => $c->mandatory])->all()),
            'chosen_coverages' => $before->coverages,
        ];
    }

    public function cancel(Request $request, string $policy): RedirectResponse
    {
        /** @var array{cancel_date: string, reason: string} $data */
        $data = $request->validate(['cancel_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:1000']]);
        $actor = PageSupport::actor($request);
        $this->lifecycle->cancel($policy, CarbonImmutable::parse($data['cancel_date']), $data['reason'], $actor);

        // GA-01 / GA-24: settling the money is the next step — the refund owed to the customer, or the earned premium they still owe.
        return redirect("/policies/{$policy}")->with('status', 'Policy cancelled.')->with('next', $this->nextSteps->afterCancel($actor, $policy));
    }

    public function transition(Request $request, string $policy, string $action): RedirectResponse
    {
        /** @var array{reason?: string} $data */
        $data = $request->validate(['reason' => [Rule::requiredIf($action !== 'renew'), 'nullable', 'string', 'max:1000']]);
        $actor = PageSupport::actor($request);
        $result = match ($action) {
            'lapse' => $this->lifecycle->lapse($policy, (string) ($data['reason'] ?? ''), $actor),
            'reinstate' => $this->lifecycle->reinstate($policy, (string) ($data['reason'] ?? ''), $actor),
            'renew' => $this->lifecycle->renew($policy, $actor),
            default => abort(404),
        };

        return redirect("/policies/{$result->id}")->with('status', $action === 'renew' ? 'Renewal quote created.' : 'Policy '.($action === 'lapse' ? 'lapsed.' : 'reinstated.'));
    }

    /**
     * @param array<int, mixed> $items
     * @return list<array<string, mixed>>
     */
    private static function rows(array $items): array
    {
        $rows = [];
        foreach ($items as $p) {
            /** @var object{id: string, number: string|null, status: string, inception: string, expiry: string, gross_premium_minor: int|string, currency: string, policyholder: string|null, product_code: string|null} $p */
            $rows[] = ['id' => (string) $p->id, 'number' => $p->number, 'status' => (string) $p->status, 'inception' => (string) $p->inception, 'expiry' => (string) $p->expiry,
                'policyholder' => (string) $p->policyholder, 'product_code' => (string) $p->product_code, 'gross_premium' => PageSupport::money((int) $p->gross_premium_minor, (string) $p->currency)];
        }

        return $rows;
    }

    /** "60" or "33.33" percent → basis points, with string arithmetic. */
    private static function basisPoints(string $percent): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($percent), 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public function attachDocument(Request $request, string $policy, ObjectDocuments $documents): RedirectResponse
    {
        $model = Policy::query()->findOrFail($policy);
        $this->permissions->authorizeAny(PageSupport::actor($request), self::ATTACH_DOCUMENTS, \App\Modules\Platform\Authorization\AuthorizationScope::branch($model->entity_id, $model->branch_id));

        return $documents->attach($request, 'policy', $model->id, "/policies/{$model->id}");
    }

    public function downloadDocument(Request $request, string $policy, string $document, ObjectDocuments $documents): StreamedResponse
    {
        $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);
        $model = Policy::query()->findOrFail($policy);
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA, \App\Modules\Platform\Authorization\AuthorizationScope::branch($model->entity_id, $model->branch_id));

        return $documents->download($request, 'policy', $model->id, $document);
    }
}
