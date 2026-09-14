<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Http\Controllers;

use App\Http\Pages\ObjectDocuments;
use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimPolicyFacts;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Claims\Domain\Enums\ClaimPaymentStatus;
use App\Modules\Insurance\Claims\Domain\Enums\ClaimStatus;
use App\Modules\Insurance\Claims\Domain\Models\Claim;
use App\Modules\Insurance\Claims\Domain\Models\ClaimPayment;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
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
        // G2: opens for the area's permissions in any scope; a branch-scoped user lists only their branches' claims.
        $reach = $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);
        $status = (string) $request->query('status', '');
        $entity = PageSupport::entity();
        $page = $reach->constrain(DB::table('claims as c'), 'c.entity_id', 'c.branch_id')->join('policies as p', 'p.id', '=', 'c.policy_id')->leftJoin('parties as h', 'h.id', '=', 'p.policyholder_party_id')
            ->where('c.entity_id', $entity['id'])->when($status !== '', fn ($q) => $q->where('c.status', $status))->orderByDesc('c.reported_on')->orderByDesc('c.number')
            ->select(['c.id', 'c.number', 'c.status', 'c.loss_date', 'c.reported_on', 'c.reserve_minor', 'c.currency', 'p.number as policy_number', 'h.display_name'])->paginate(PageSupport::listPageSize())->withQueryString();
        $rows = [];
        foreach ($page->items() as $c) {
            /** @var object{id: string, number: string, status: string, loss_date: string, reported_on: string, reserve_minor: int|string, currency: string, policy_number: string|null, display_name: string|null} $c */
            $rows[] = ['id' => $c->id, 'number' => $c->number, 'status' => $c->status, 'loss_date' => $c->loss_date, 'reported_on' => $c->reported_on,
                'reserve' => PageSupport::money((int) $c->reserve_minor, $c->currency), 'policy_number' => $c->policy_number, 'policyholder' => (string) $c->display_name];
        }

        return Inertia::render('claims/Index', ['filters' => ['status' => $status], 'statuses' => array_column(ClaimStatus::cases(), 'value'), 'claims' => PageSupport::page($page, $rows)]);
    }

    /**
     * GA-26: the claim payments queue (GET /claims/payments) — every payment of the user's branches' claims, the ones still to approve, request or release
     * first, oldest approval first; each opens its claim, where the payment is released. Home "Payments to release" opens it filtered (`f.status=`).
     */
    public function payments(Request $request): Response
    {
        $reach = $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);
        $entity = PageSupport::entity();
        $open = [ClaimPaymentStatus::PendingApproval->value, ClaimPaymentStatus::Approved->value, ClaimPaymentStatus::ReleaseRequested->value, ClaimPaymentStatus::ReleasePendingApproval->value];
        $page = $reach->constrain(DB::table('claim_payments as cp'), 'c.entity_id', 'c.branch_id')->join('claims as c', 'c.id', '=', 'cp.claim_id')->join('policies as p', 'p.id', '=', 'c.policy_id')
            ->leftJoin('parties as payee', 'payee.id', '=', 'cp.payee_party_id')->where('c.entity_id', $entity['id'])
            ->orderByRaw('case when cp.status in ('.implode(',', array_fill(0, count($open), '?')).') then 0 else 1 end', $open)->orderBy('cp.approved_on')->orderBy('c.number')
            ->select(['cp.id', 'cp.status', 'cp.approved_on', 'cp.paid_on', 'cp.amount_minor', 'cp.currency', 'c.id as claim_id', 'c.number as claim_number', 'p.number as policy_number', 'payee.display_name as payee'])
            ->paginate(PageSupport::listPageSize())->withQueryString();
        $rows = [];
        foreach ($page->items() as $payment) {
            /** @var object{id: string, status: string, approved_on: string, paid_on: string|null, amount_minor: int|string, currency: string, claim_id: string, claim_number: string, policy_number: string|null, payee: string|null} $payment */
            $rows[] = ['id' => (string) $payment->id, 'status' => (string) $payment->status, 'approved_on' => (string) $payment->approved_on, 'paid_on' => $payment->paid_on,
                'amount' => PageSupport::money((int) $payment->amount_minor, (string) $payment->currency), 'claim_id' => (string) $payment->claim_id, 'claim_number' => (string) $payment->claim_number,
                'policy_number' => $payment->policy_number, 'payee' => (string) ($payment->payee ?? '')];
        }

        return Inertia::render('claims/Payments', ['statuses' => array_column(ClaimPaymentStatus::cases(), 'value'), 'payments' => PageSupport::page($page, $rows)]);
    }

    public function create(Request $request): Response
    {
        $reach = $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);
        $entity = PageSupport::entity();

        return Inertia::render('claims/Create', [
            'today' => app(BusinessClock::class)->today()->toDateString(), // flow fix X2: reported on starts at today; the date of loss stays for the customer to say
            // GA-12: with each policy, the premium already due and unpaid today, so registration warns (never refuses) "no premium, no cover".
            'policies' => $reach->constrain(DB::table('policies as p'), 'p.entity_id', 'p.branch_id')->join('parties as h', 'h.id', '=', 'p.policyholder_party_id')->where('p.entity_id', $entity['id'])->whereNotNull('p.number')
                ->leftJoinSub(DB::table('installments')->where('due_date', '<=', app(BusinessClock::class)->today()->toDateString())->groupBy('policy_id')
                    ->select(['policy_id', DB::raw('sum(amount_minor - paid_minor - cancelled_minor) as unpaid')]), 'u', 'u.policy_id', '=', 'p.id')
                ->whereIn('p.status', ['issued', 'active', 'expired', 'lapsed', 'cancelled', 'renewed'])->orderBy('p.number')
                ->get(['p.id', 'p.number', 'h.display_name', 'p.inception', 'p.expiry', 'p.currency', 'u.unpaid'])
                ->map(fn (object $p): array => ['id' => (string) $p->id, 'number' => (string) $p->number, 'display_name' => (string) $p->display_name, 'inception' => (string) $p->inception,
                    'expiry' => (string) $p->expiry, 'unpaid_premium' => (int) ($p->unpaid ?? 0) > 0 ? PageSupport::money((int) $p->unpaid, (string) $p->currency) : null])->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var array{policy_id: string, loss_date: string, reported_on: string, description: string} $data */
        $data = $request->validate(['policy_id' => ['required', 'uuid'], 'loss_date' => ['required', 'date_format:Y-m-d'], 'reported_on' => ['required', 'date_format:Y-m-d'], 'description' => ['required', 'string', 'max:5000']]);
        $claim = $this->claims->register($data['policy_id'], CarbonImmutable::parse($data['loss_date']), $data['description'], PageSupport::actor($request), CarbonImmutable::parse($data['reported_on']));
        // GA-12: a warning, not a refusal — the claims desk checks the premium before reserving.
        $facts = app(ClaimPolicyFacts::class)->forPolicy($claim->policy_id, CarbonImmutable::parse($data['loss_date']), $claim->id);
        $warning = $facts !== null && $facts['overdue_minor'] > 0
            ? ' Premium of '.PageSupport::money($facts['overdue_minor'], $facts['currency'])." due by the date of loss is unpaid on {$facts['number']}: check it before reserving." : '';

        return redirect("/claims/{$claim->id}")->with('status', "Claim {$claim->number} registered.{$warning}");
    }

    public function show(Request $request, string $claim): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeArea($actor, self::AREA);
        $model = Claim::query()->findOrFail($claim);
        $this->permissions->authorizeAny($actor, self::AREA, AuthorizationScope::branch($model->entity_id, $model->branch_id)); // G2: another branch's claim is 403
        $money = fn (int $minor): string => PageSupport::money($minor, $model->currency);
        $can = fn (string $permission): bool => $this->permissions->has($actor, $permission, AuthorizationScope::branch($model->entity_id, $model->branch_id));
        $payments = ClaimPayment::query()->where('claim_id', $model->id)->orderBy('created_at')->get();
        $unsettled = $payments->contains(fn (ClaimPayment $p): bool => in_array($p->status->value, ClaimPaymentStatus::unsettled(), true));
        $status = $model->status;
        $policy = DB::table('policies as p')->leftJoin('parties as h', 'h.id', '=', 'p.policyholder_party_id')->where('p.id', $model->policy_id)->first(['p.id', 'p.number', 'p.policyholder_party_id', 'h.display_name']);
        $bankAccounts = DB::table('bank_accounts')->where('entity_id', $model->entity_id)->get(['id', 'bank_name', 'account_no_masked', 'gl_account_id', 'currency', 'status']);
        // Flow fix X3 / G1: the bank a payment is paid from is fixed when the release is requested (ClaimPaymentService::requestRelease); the release does
        // not change it. Without one, CLAIM_PAID credits bank_main — shown as the active account in the claim's currency behind that role.
        $bankMain = DB::table('account_role_mappings')->where('entity_id', $model->entity_id)->where('role_code', 'bank_main')->where('effective_from', '<=', app(BusinessClock::class)->today()->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', app(BusinessClock::class)->today()->toDateString()))->orderByDesc('effective_from')->value('account_id');
        $defaultBank = $bankAccounts->first(fn (object $b): bool => $b->gl_account_id === $bankMain && $b->currency === $model->currency && $b->status === 'active')?->id;
        $bankLabel = function (?string $id) use ($bankAccounts): string {
            $bank = $id === null ? null : $bankAccounts->firstWhere('id', $id);

            return $bank === null ? 'Main bank account (account role bank_main)' : "{$bank->bank_name} {$bank->account_no_masked}";
        };

        return Inertia::render('claims/Show', [
            'claim' => ['id' => $model->id, 'number' => $model->number, 'status' => $status->value, 'loss_date' => $model->loss_date->toDateString(), 'reported_on' => $model->reported_on->toDateString(),
                'description' => $model->description, 'reserve' => $money($model->reserve_minor), 'currency' => $model->currency, 'status_reason' => $model->status_reason,
                'policy' => ['id' => (string) ($policy->id ?? ''), 'number' => $policy->number ?? null, 'policyholder' => (string) ($policy->display_name ?? ''), 'policyholder_id' => (string) ($policy->policyholder_party_id ?? '')],
                // Flow fix X3: the approval drawer proposes the reserve not yet committed to payments.
                'uncommitted' => $money(max(0, $model->reserve_minor - (int) $payments->filter(fn (ClaimPayment $p): bool => in_array($p->status->value, ClaimPaymentStatus::committed(), true))->sum('amount_minor')))],
            'today' => app(BusinessClock::class)->today()->toDateString(),
            // GA-12: the policy as the claims desk needs it (cover, sum insured, premium paid, bounced cheques, earlier claims), read-only.
            'policyFacts' => $this->policyFacts($actor, $model),
            'nextStep' => $request->hasSession() ? $request->session()->get('next_step') : null,
            'reserves' => DB::table('claim_reserves')->where('claim_id', $model->id)->orderBy('version')->get(['version', 'reserve_minor', 'delta_minor', 'kind', 'reason', 'recorded_on'])
                ->map(fn (object $r): array => ['version' => (int) $r->version, 'reserve' => $money((int) $r->reserve_minor), 'delta' => $money((int) $r->delta_minor), 'kind' => (string) $r->kind,
                    'reason' => (string) $r->reason, 'recorded_on' => (string) $r->recorded_on])->values()->all(),
            'payments' => $payments->map(fn (ClaimPayment $p): array => ['id' => $p->id, 'amount' => $money($p->amount_minor), 'status' => $p->status->value, 'approved_on' => $p->approved_on->toDateString(),
                'paid_on' => $p->paid_on?->toDateString(), 'bank_account_id' => $p->bank_account_id ?? $defaultBank,
                'pay_from' => $bankLabel($p->bank_account_id ?? $defaultBank),
                'can_request_release' => $p->status === ClaimPaymentStatus::Approved && $can('claim.pay_request'),
                'can_release' => $p->status === ClaimPaymentStatus::ReleaseRequested && $can('claim.pay_release')])->values()->all(),
            'recoveries' => DB::table('claim_recoveries as r')->leftJoin('parties as payer', 'payer.id', '=', 'r.payer_party_id')->where('r.claim_id', $model->id)->orderBy('r.received_on')
                ->get(['r.type', 'r.amount_minor', 'r.received_on', 'r.reference', 'r.number', 'payer.display_name as payer'])
                ->map(fn (object $r): array => ['type' => (string) $r->type, 'amount' => $money((int) $r->amount_minor), 'received_on' => (string) $r->received_on, 'reference' => $r->reference,
                    'number' => $r->number, 'payer' => $r->payer])->values()->all(),
            // Gap fix GA-21: the bank accounts a recovery can be paid into (the claim's currency), the main one first.
            'recoveryBankAccounts' => $bankAccounts->filter(fn (object $b): bool => $b->status === 'active' && $b->currency === $model->currency)
                ->sortBy(fn (object $b): int => $b->id === $defaultBank ? 0 : 1)->map(fn (object $b): array => ['id' => (string) $b->id, 'label' => "{$b->bank_name} {$b->account_no_masked}"])->values()->all(),
            'documentUpload' => array_any(self::ATTACH_DOCUMENTS, $can) ? "/claims/{$model->id}/documents" : null,
            'actions' => [
                'reserve' => in_array($status, [ClaimStatus::Registered, ClaimStatus::Reserved, ClaimStatus::Approved, ClaimStatus::Paid], true) && $can('claim.reserve'),
                'approve' => in_array($status, [ClaimStatus::Reserved, ClaimStatus::Approved, ClaimStatus::Paid], true) && $can('claim.approve'),
                // Follow-up H3: a paid claim reopened and reserved again still closes and takes recoveries. Gap fix GA-21 (D-88): recoveries are receipted by
                // whoever takes money in (was: claim.pay_request).
                'recover' => $this->claims->recoverable($model) && array_any(\App\Modules\Insurance\Claims\Application\ClaimRecoveryReceipts::PERMISSIONS, $can),
                'close' => $this->claims->closable($model) && ! $unsettled && $can('claim.close'),
                'reject' => in_array($status, [ClaimStatus::Registered, ClaimStatus::Reserved], true) && $can('claim.approve'),
                'reopen' => $status === ClaimStatus::Closed && $can('claim.approve')
                    && ! DB::table('approvals')->where('object_type', 'claim_reopen')->where('object_id', $model->id)->where('status', 'pending')->exists(),
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function policyFacts(string $actor, Claim $claim): ?array
    {
        $facts = app(ClaimPolicyFacts::class)->forPolicy($claim->policy_id, $claim->loss_date, $claim->id);
        if ($facts === null) {
            return null;
        }
        $money = fn (?int $minor): ?string => $minor === null ? null : PageSupport::money($minor, $facts['currency']);
        $opensPolicy = $this->permissions->has($actor, 'reports.financial') || array_any(\App\Modules\Insurance\Policy\Http\Controllers\PolicyPageController::AREA,
            fn (string $permission): bool => $this->permissions->has($actor, $permission, AuthorizationScope::branch($claim->entity_id, $claim->branch_id)));

        return ['number' => $facts['number'], 'status' => $facts['status'], 'product' => $facts['product'], 'inception' => $facts['inception'], 'expiry' => $facts['expiry'],
            'sum_insured' => $money($facts['sum_insured_minor']), 'gross_premium' => $money($facts['gross_premium_minor']), 'paid' => $money($facts['paid_minor']),
            'outstanding' => $money($facts['outstanding_minor']), 'unpaid_at_loss' => $money($facts['overdue_minor']),
            'bounced_cheques' => array_map(fn (array $b): array => ['receipt_number' => $b['receipt_number'], 'cheque_no' => $b['cheque_no'], 'bounced_on' => $b['bounced_on'], 'amount' => $money($b['amount_minor'])], $facts['bounced_cheques']),
            'other_claims' => array_map(fn (array $c): array => ['id' => $c['id'], 'number' => $c['number'], 'loss_date' => $c['loss_date'], 'status' => $c['status'], 'reserve' => $money($c['reserve_minor'])], $facts['other_claims']),
            'href' => $opensPolicy ? "/policies/{$facts['id']}" : null];
    }

    public function reserve(Request $request, string $claim): RedirectResponse
    {
        /** @var array{reserve: string, reason: string, on: string} $data */
        $data = $request->validate(['reserve' => ['required', 'string'], 'reason' => ['required', 'string', 'max:1000'], 'on' => ['required', 'date_format:Y-m-d']]);
        $currency = (string) Claim::query()->whereKey($claim)->value('currency');
        $actor = PageSupport::actor($request);
        $this->claims->reserve($claim, PageSupport::minor('reserve', $data['reserve'], $currency), $data['reason'], $actor, CarbonImmutable::parse($data['on']));

        // Flow fix X3: the next step is the settlement. Offer it when this user may approve it now; otherwise say who does.
        $outlook = $this->payments->settlementOutlook($claim, $actor, app(BusinessClock::class)->today());
        if ($outlook['approve']) {
            return redirect("/claims/{$claim}")->with('status', 'Reserve set.')->with('next_step', 'approve_payment');
        }
        if ($outlook['available_minor'] === 0) {
            return redirect("/claims/{$claim}")->with('status', 'Reserve set.');
        }

        return redirect("/claims/{$claim}")->with('status', 'Reserve set. '.self::approvers($outlook['approver_roles']).' approves the settlement from their Home.');
    }

    /** @param list<string> $roles role names → "The claims manager", "The claims manager or CFO" (acronyms keep their capitals) */
    private static function approvers(array $roles): string
    {
        $names = array_map(fn (string $name): string => implode(' ', array_map(fn (string $word): string => $word === strtoupper($word) ? $word : strtolower($word), explode(' ', $name))), $roles);

        return $names === [] ? 'Someone given claim approval in Admin → Roles' : 'The '.implode(' or ', $names);
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

    /** Gap fix GA-21 (D-88): a recovery is receipted by whoever takes money in, with its number, payer and bank account. */
    public function recover(Request $request, string $claim, \App\Modules\Insurance\Claims\Application\ClaimRecoveryReceipts $receipts): RedirectResponse
    {
        /** @var array{type: string, amount: string, received_on: string, bank_account_id: string, payer_party_id: string, reference?: string|null} $data */
        $data = $request->validate(['type' => ['required', 'in:salvage,subrogation,third_party'], 'amount' => ['required', 'string'], 'received_on' => ['required', 'date_format:Y-m-d'],
            'bank_account_id' => ['required', 'uuid'], 'payer_party_id' => ['required', 'uuid'], 'reference' => ['nullable', 'string', 'max:255']],
            ['bank_account_id.required' => 'Choose the bank account the money was paid into.', 'payer_party_id.required' => 'Choose who paid the recovery.']);
        $currency = (string) Claim::query()->whereKey($claim)->value('currency');
        $recovery = $receipts->receive($claim, $data['type'], PageSupport::minor('amount', $data['amount'], $currency), $data['bank_account_id'], $data['payer_party_id'],
            $data['reference'] ?? null, PageSupport::actor($request), CarbonImmutable::parse($data['received_on']));

        return redirect("/claims/{$claim}")->with('status', "Recovery receipt {$recovery->number} recorded.");
    }

    public function attachDocument(Request $request, string $claim, ObjectDocuments $documents): RedirectResponse
    {
        $model = Claim::query()->findOrFail($claim);
        $this->permissions->authorizeAny(PageSupport::actor($request), self::ATTACH_DOCUMENTS, AuthorizationScope::branch($model->entity_id, $model->branch_id));

        return $documents->attach($request, 'claim', $model->id, "/claims/{$model->id}");
    }

    public function downloadDocument(Request $request, string $claim, string $document, ObjectDocuments $documents): StreamedResponse
    {
        $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);
        $model = Claim::query()->findOrFail($claim);
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA, AuthorizationScope::branch($model->entity_id, $model->branch_id));

        return $documents->download($request, 'claim', $model->id, $document);
    }
}
