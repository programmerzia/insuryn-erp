<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Http\Controllers;

use App\Http\Pages\ObjectDocuments;
use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Claims\Domain\Enums\ClaimPaymentStatus;
use App\Modules\Insurance\Claims\Domain\Enums\ClaimStatus;
use App\Modules\Insurance\Claims\Domain\Models\Claim;
use App\Modules\Insurance\Claims\Domain\Models\ClaimPayment;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Claims screens (design §5.5): list, register, and a claim page with reserve history, payments, recoveries and the lifecycle actions. */
final class ClaimPageController
{
    public const AREA = ['claim.register', 'claim.reserve', 'claim.approve', 'claim.pay_request', 'claim.pay_release', 'claim.close', 'reports.financial'];

    /** ASSUMPTION: A-53 — who may attach documents to a claim is not specified: the people who register, reserve or approve it. Reading follows AREA. */
    public const ATTACH_DOCUMENTS = ['claim.register', 'claim.reserve', 'claim.approve'];

    public function __construct(
        private readonly ClaimService $claims,
        private readonly ClaimPaymentService $payments,
        private readonly PermissionChecker $permissions,
    ) {}

    public function index(Request $request): Response
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);
        $status = (string) $request->query('status', '');
        $entity = PageSupport::entity();
        $page = DB::table('claims as c')->join('policies as p', 'p.id', '=', 'c.policy_id')->leftJoin('parties as h', 'h.id', '=', 'p.policyholder_party_id')
            ->where('c.entity_id', $entity['id'])->when($status !== '', fn ($q) => $q->where('c.status', $status))->orderByDesc('c.reported_on')->orderByDesc('c.number')
            ->select(['c.id', 'c.number', 'c.status', 'c.loss_date', 'c.reported_on', 'c.reserve_minor', 'c.currency', 'p.number as policy_number', 'h.display_name'])->paginate(PageSupport::LIST_PAGE_SIZE)->withQueryString();
        $rows = [];
        foreach ($page->items() as $c) {
            /** @var object{id: string, number: string, status: string, loss_date: string, reported_on: string, reserve_minor: int|string, currency: string, policy_number: string|null, display_name: string|null} $c */
            $rows[] = ['id' => $c->id, 'number' => $c->number, 'status' => $c->status, 'loss_date' => $c->loss_date, 'reported_on' => $c->reported_on,
                'reserve' => PageSupport::money((int) $c->reserve_minor, $c->currency), 'policy_number' => $c->policy_number, 'policyholder' => (string) $c->display_name];
        }

        return Inertia::render('claims/Index', ['filters' => ['status' => $status], 'statuses' => array_column(ClaimStatus::cases(), 'value'), 'claims' => PageSupport::page($page, $rows)]);
    }

    public function create(Request $request): Response
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);
        $entity = PageSupport::entity();

        return Inertia::render('claims/Create', [
            'policies' => DB::table('policies as p')->join('parties as h', 'h.id', '=', 'p.policyholder_party_id')->where('p.entity_id', $entity['id'])->whereNotNull('p.number')
                ->whereIn('p.status', ['issued', 'active', 'expired', 'lapsed', 'cancelled', 'renewed'])->orderBy('p.number')
                ->get(['p.id', 'p.number', 'h.display_name', 'p.inception', 'p.expiry'])->map(fn (object $p): array => (array) $p)->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var array{policy_id: string, loss_date: string, reported_on: string, description: string} $data */
        $data = $request->validate(['policy_id' => ['required', 'uuid'], 'loss_date' => ['required', 'date_format:Y-m-d'], 'reported_on' => ['required', 'date_format:Y-m-d'], 'description' => ['required', 'string', 'max:5000']]);
        $claim = $this->claims->register($data['policy_id'], CarbonImmutable::parse($data['loss_date']), $data['description'], PageSupport::actor($request), CarbonImmutable::parse($data['reported_on']));

        return redirect("/claims/{$claim->id}")->with('status', "Claim {$claim->number} registered.");
    }

    public function show(Request $request, string $claim): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $model = Claim::query()->findOrFail($claim);
        $money = fn (int $minor): string => PageSupport::money($minor, $model->currency);
        $can = fn (string $permission): bool => $this->permissions->has($actor, $permission, AuthorizationScope::branch($model->entity_id, $model->branch_id));
        $payments = ClaimPayment::query()->where('claim_id', $model->id)->orderBy('created_at')->get();
        $unsettled = $payments->contains(fn (ClaimPayment $p): bool => in_array($p->status->value, ClaimPaymentStatus::unsettled(), true));
        $status = $model->status;
        $policy = DB::table('policies as p')->leftJoin('parties as h', 'h.id', '=', 'p.policyholder_party_id')->where('p.id', $model->policy_id)->first(['p.id', 'p.number', 'h.display_name']);

        return Inertia::render('claims/Show', [
            'claim' => ['id' => $model->id, 'number' => $model->number, 'status' => $status->value, 'loss_date' => $model->loss_date->toDateString(), 'reported_on' => $model->reported_on->toDateString(),
                'description' => $model->description, 'reserve' => $money($model->reserve_minor), 'currency' => $model->currency, 'status_reason' => $model->status_reason,
                'policy' => ['id' => (string) ($policy->id ?? ''), 'number' => $policy->number ?? null, 'policyholder' => (string) ($policy->display_name ?? '')]],
            'reserves' => DB::table('claim_reserves')->where('claim_id', $model->id)->orderBy('version')->get(['version', 'reserve_minor', 'delta_minor', 'kind', 'reason', 'recorded_on'])
                ->map(fn (object $r): array => ['version' => (int) $r->version, 'reserve' => $money((int) $r->reserve_minor), 'delta' => $money((int) $r->delta_minor), 'kind' => (string) $r->kind,
                    'reason' => (string) $r->reason, 'recorded_on' => (string) $r->recorded_on])->values()->all(),
            'payments' => $payments->map(fn (ClaimPayment $p): array => ['id' => $p->id, 'amount' => $money($p->amount_minor), 'status' => $p->status->value, 'approved_on' => $p->approved_on->toDateString(),
                'paid_on' => $p->paid_on?->toDateString(),
                'can_request_release' => $p->status === ClaimPaymentStatus::Approved && $can('claim.pay_request'),
                'can_release' => $p->status === ClaimPaymentStatus::ReleaseRequested && $can('claim.pay_release')])->values()->all(),
            'recoveries' => DB::table('claim_recoveries')->where('claim_id', $model->id)->orderBy('received_on')->get(['type', 'amount_minor', 'received_on', 'reference'])
                ->map(fn (object $r): array => ['type' => (string) $r->type, 'amount' => $money((int) $r->amount_minor), 'received_on' => (string) $r->received_on, 'reference' => $r->reference])->values()->all(),
            'parties' => DB::table('parties')->orderBy('display_name')->get(['id', 'display_name'])->map(fn (object $p): array => (array) $p)->values()->all(),
            'bankAccounts' => DB::table('bank_accounts')->where('entity_id', $model->entity_id)->where('status', 'active')->get(['id', 'bank_name', 'account_no_masked'])->map(fn (object $b): array => (array) $b)->values()->all(),
            'documentUpload' => array_any(self::ATTACH_DOCUMENTS, $can) ? "/claims/{$model->id}/documents" : null,
            'actions' => [
                'reserve' => in_array($status, [ClaimStatus::Registered, ClaimStatus::Reserved, ClaimStatus::Approved, ClaimStatus::Paid], true) && $can('claim.reserve'),
                'approve' => in_array($status, [ClaimStatus::Reserved, ClaimStatus::Approved, ClaimStatus::Paid], true) && $can('claim.approve'),
                'recover' => in_array($status, [ClaimStatus::Paid, ClaimStatus::Closed], true) && $can('claim.pay_request'),
                'close' => in_array($status, [ClaimStatus::Approved, ClaimStatus::Paid], true) && ! $unsettled && $can('claim.close'),
                'reject' => in_array($status, [ClaimStatus::Registered, ClaimStatus::Reserved], true) && $can('claim.approve'),
                'reopen' => $status === ClaimStatus::Closed && $can('claim.approve'),
            ],
        ]);
    }

    public function reserve(Request $request, string $claim): RedirectResponse
    {
        /** @var array{reserve: string, reason: string, on: string} $data */
        $data = $request->validate(['reserve' => ['required', 'string'], 'reason' => ['required', 'string', 'max:1000'], 'on' => ['required', 'date_format:Y-m-d']]);
        $currency = (string) Claim::query()->whereKey($claim)->value('currency');
        $this->claims->reserve($claim, PageSupport::minor('reserve', $data['reserve'], $currency), $data['reason'], PageSupport::actor($request), CarbonImmutable::parse($data['on']));

        return redirect("/claims/{$claim}")->with('status', 'Reserve updated.');
    }

    public function approvePayment(Request $request, string $claim): RedirectResponse
    {
        /** @var array{amount: string, payee_party_id: string, on: string} $data */
        $data = $request->validate(['amount' => ['required', 'string'], 'payee_party_id' => ['required', 'uuid'], 'on' => ['required', 'date_format:Y-m-d']]);
        $currency = (string) Claim::query()->whereKey($claim)->value('currency');
        $payment = $this->payments->approve($claim, PageSupport::minor('amount', $data['amount'], $currency), $data['payee_party_id'], PageSupport::actor($request), CarbonImmutable::parse($data['on']));

        return redirect("/claims/{$claim}")->with('status', $payment->status === ClaimPaymentStatus::PendingApproval ? 'Payment sent for approval (above your limit).' : 'Payment approved.');
    }

    public function requestRelease(Request $request, string $payment): RedirectResponse
    {
        /** @var array{bank_account_id?: string|null} $data */
        $data = $request->validate(['bank_account_id' => ['nullable', 'uuid']]);
        $model = $this->payments->requestRelease($payment, PageSupport::actor($request), ($data['bank_account_id'] ?? '') === '' ? null : $data['bank_account_id']);

        return redirect("/claims/{$model->claim_id}")->with('status', 'Payment release requested; someone else must release it.');
    }

    public function release(Request $request, string $payment): RedirectResponse
    {
        /** @var array{paid_on: string} $data */
        $data = $request->validate(['paid_on' => ['required', 'date_format:Y-m-d']]);
        $model = $this->payments->release($payment, PageSupport::actor($request), CarbonImmutable::parse($data['paid_on']));

        return redirect("/claims/{$model->claim_id}")->with('status', $model->status === ClaimPaymentStatus::Paid ? 'Payment released.' : 'Release sent for approval (above your limit).');
    }

    public function decision(Request $request, string $claim, string $action): RedirectResponse
    {
        /** @var array{reason: string, on: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'on' => ['required', 'date_format:Y-m-d']]);
        $actor = PageSupport::actor($request);
        $on = CarbonImmutable::parse($data['on']);
        switch ($action) {
            case 'close':
                $this->claims->close($claim, $data['reason'], $actor, $on);
                $message = 'Claim closed; the unused reserve was released.';
                break;
            case 'reject':
                $this->claims->reject($claim, $data['reason'], $actor, $on);
                $message = 'Claim rejected.';
                break;
            default:
                $message = $this->claims->reopen($claim, $data['reason'], $actor, $on) === null ? 'Claim reopened.' : 'Reopening sent for approval.';
        }

        return redirect("/claims/{$claim}")->with('status', $message);
    }

    public function recover(Request $request, string $claim): RedirectResponse
    {
        /** @var array{type: string, amount: string, received_on: string, bank_account_id?: string|null, reference?: string|null} $data */
        $data = $request->validate(['type' => ['required', 'in:salvage,subrogation,third_party'], 'amount' => ['required', 'string'], 'received_on' => ['required', 'date_format:Y-m-d'],
            'bank_account_id' => ['nullable', 'uuid'], 'reference' => ['nullable', 'string', 'max:255']]);
        $currency = (string) Claim::query()->whereKey($claim)->value('currency');
        $this->claims->recover($claim, $data['type'], PageSupport::minor('amount', $data['amount'], $currency), ($data['bank_account_id'] ?? '') === '' ? null : $data['bank_account_id'],
            $data['reference'] ?? null, PageSupport::actor($request), CarbonImmutable::parse($data['received_on']));

        return redirect("/claims/{$claim}")->with('status', 'Recovery recorded.');
    }

    public function attachDocument(Request $request, string $claim, ObjectDocuments $documents): RedirectResponse
    {
        $model = Claim::query()->findOrFail($claim);
        $this->permissions->authorizeAny(PageSupport::actor($request), self::ATTACH_DOCUMENTS, AuthorizationScope::branch($model->entity_id, $model->branch_id));

        return $documents->attach($request, 'claim', $model->id, "/claims/{$model->id}");
    }

    public function downloadDocument(Request $request, string $claim, string $document, ObjectDocuments $documents): StreamedResponse
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);
        $model = Claim::query()->findOrFail($claim);

        return $documents->download($request, 'claim', $model->id, $document);
    }
}
