<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Http\Controllers;

use App\Http\Pages\FormDefaults;
use App\Http\Pages\NextSteps;
use App\Http\Pages\ObjectDocuments;
use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Collections\Application\AgentCashPositionQuery;
use App\Modules\Insurance\Collections\Application\AgentDepositService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ChequeBounceService;
use App\Modules\Insurance\Collections\Application\ChequeDetails;
use App\Modules\Insurance\Collections\Application\ChequeRegisterQuery;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Collections\Application\RefundableQuery;
use App\Modules\Insurance\Collections\Application\RefundService;
use App\Modules\Insurance\Collections\Application\SuspenseQuery;
use App\Modules\Insurance\Collections\Application\SuspenseService;
use App\Modules\Insurance\Collections\Domain\Enums\ReceiptStatus;
use App\Modules\Insurance\Collections\Domain\Models\Receipt;
use App\Modules\Insurance\Collections\Domain\Models\SuspenseItem;
use App\Modules\Platform\Authorization\AreaReach;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Collections screens: receipts, suspense, refunds, agent cash, cheque register and dunning notices (design §2.4, §4.2, §4.9; spec §4). */
final class CollectionsPageController
{
    public const AREA = ['receipt.create', 'receipt.allocate', 'receipt.refund_request', 'receipt.refund_release', 'reports.financial'];

    /** ASSUMPTION: A-53 — who may attach documents to a receipt is not specified: the people who record or allocate receipts. Reading follows AREA. */
    public const ATTACH_DOCUMENTS = ['receipt.create', 'receipt.allocate'];

    private const CHANNELS = ['bank_transfer', 'cash', 'cheque', 'card', 'mobile_money'];

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function index(Request $request): Response
    {
        // G2: opens for the area's permissions in any scope; a branch-scoped user lists only their branches' receipts.
        $reach = $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);
        $entity = PageSupport::entity();
        $page = $reach->constrain(DB::table('receipts'), 'entity_id', 'branch_id')->where('entity_id', $entity['id'])->orderByDesc('value_date')->orderByDesc('number')->paginate(PageSupport::LIST_PAGE_SIZE)->withQueryString();
        $rows = [];
        foreach ($page->items() as $r) {
            /** @var object{id: string, number: string, channel: string, amount_minor: int|string, currency: string, value_date: string, reference: string|null, status: string, collected_by_agent_id: string|null} $r */
            $rows[] = ['id' => $r->id, 'number' => $r->number, 'channel' => $r->channel, 'amount' => PageSupport::money((int) $r->amount_minor, $r->currency), 'value_date' => $r->value_date,
                'reference' => $r->reference, 'status' => $r->status, 'agent_collection' => $r->collected_by_agent_id !== null];
        }

        return Inertia::render('receipts/Index', ['receipts' => PageSupport::page($page, $rows)]);
    }

    public function create(Request $request, FormDefaults $defaults): Response
    {
        $actor = PageSupport::actor($request);
        $reach = $this->permissions->authorizeArea($actor, self::AREA);
        $entity = PageSupport::entity();
        $policy = $request->query('policy');
        $branches = $reach->constrain(DB::table('branches'), 'entity_id', 'id')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $b): array => (array) $b)->values()->all();

        return Inertia::render('receipts/Create', [
            // Flow fix X1: opened from a policy (/receipts/create?policy=…) the receipt arrives filled in; otherwise the user's branch and today.
            // ASSUMPTION: A-195 (GA-38) — the channel starts as bank transfer, or as the payer's last channel when the payer is known (the policyholder of a prefilled receipt).
            'prefill' => is_string($policy) && Str::isUuid($policy) ? $this->prefill($entity, $policy, $reach) : null,
            // GA-07: opened from Home's "Receipts to record" (/receipts/create?statement_line=…), the receipt starts with the bank line's amount, date and reference.
            'statementLine' => is_string($line = $request->query('statement_line')) && Str::isUuid($line) ? self::statementLine($entity, $line) : null,
            'defaults' => ['branch_id' => $defaults->branch($actor, $entity['id']), 'value_date' => app(BusinessClock::class)->today()->toDateString(), 'channel' => 'bank_transfer'],
            'entity' => $entity,
            'channels' => self::CHANNELS,
            'branches' => $branches,
            // GA-03 (D-65): the branches where this user allocates money to installments; elsewhere the receipt is recorded into suspense for someone who does.
            'allocateBranchIds' => array_values(array_map(fn (array $b): string => (string) $b['id'], array_filter($branches,
                fn (array $b): bool => $this->permissions->has($actor, 'receipt.allocate', AuthorizationScope::branch($entity['id'], (string) $b['id']))))),
            'bankAccounts' => DB::table('bank_accounts')->where('entity_id', $entity['id'])->where('status', 'active')->orderBy('bank_name')
                ->get(['id', 'bank_name', 'account_no_masked'])->map(fn (object $b): array => (array) $b)->values()->all(),
            'agents' => DB::table('producers')->where('status', 'active')->orderBy('code')->get(['id', 'code'])->map(fn (object $a): array => (array) $a)->values()->all(),
            'installments' => $this->outstandingInstallments($entity, $reach),
        ]);
    }

    public function store(Request $request, ReceiptService $receipts, NextSteps $nextSteps): RedirectResponse
    {
        /** @var array{branch_id: string, party_id?: string|null, channel: string, amount: string, value_date: string, reference?: string|null, bank_account_id?: string|null, cheque_no?: string|null, cheque_bank?: string|null, cheque_date?: string|null, collected_by_agent_id?: string|null, for_policy_id?: string|null, allocations?: list<array{installment_id: string, amount: string}>} $data */
        $data = $request->validate(['branch_id' => ['required', 'uuid'], 'party_id' => ['nullable', 'uuid', 'exists:parties,id'], 'channel' => ['required', 'in:'.implode(',', self::CHANNELS)], 'amount' => ['required', 'string'],
            'value_date' => ['required', 'date_format:Y-m-d'], 'reference' => ['nullable', 'string', 'max:255'], 'bank_account_id' => ['nullable', 'uuid'],
            'cheque_no' => ['nullable', 'string', 'max:64'], 'cheque_bank' => ['nullable', 'string', 'max:255'], 'cheque_date' => ['nullable', 'date_format:Y-m-d'],
            'collected_by_agent_id' => ['nullable', 'uuid'], 'for_policy_id' => ['nullable', 'uuid'], 'allocations' => ['sometimes', 'array'], 'allocations.*.installment_id' => ['required', 'uuid'], 'allocations.*.amount' => ['required', 'string']]);
        $entity = PageSupport::entity();
        $allocations = [];
        foreach ($data['allocations'] ?? [] as $index => $line) {
            $allocations[] = new AllocationLine($line['installment_id'], PageSupport::minor("allocations.{$index}.amount", $line['amount'], $entity['currency']));
        }
        $cheque = isset($data['cheque_no'], $data['cheque_bank'], $data['cheque_date']) && $data['cheque_no'] !== ''
            ? new ChequeDetails($data['cheque_no'], $data['cheque_bank'], CarbonImmutable::parse($data['cheque_date'])) : null;
        // GA-38: "Received from"; left empty, the policyholder when every allocated installment belongs to one policyholder.
        $payer = ($data['party_id'] ?? '') !== '' ? $data['party_id'] : self::singleHolder(array_map(fn (AllocationLine $l): string => $l->installmentId, $allocations));
        $receipt = $receipts->record(new RecordReceiptRequest($entity['id'], $data['branch_id'], $payer, $data['channel'], PageSupport::minor('amount', $data['amount'], $entity['currency']),
            $entity['currency'], CarbonImmutable::parse($data['value_date']), $data['bank_account_id'] ?? null, $data['reference'] ?? null, $allocations, $cheque,
            ($data['collected_by_agent_id'] ?? '') === '' ? null : $data['collected_by_agent_id'], ($data['for_policy_id'] ?? '') === '' ? null : $data['for_policy_id']), PageSupport::actor($request));
        $status = "Receipt {$receipt->number} recorded.";
        if ($receipt->for_policy_id !== null && $allocations === [] && ! $this->permissions->has(PageSupport::actor($request), 'receipt.allocate', AuthorizationScope::branch($receipt->entity_id, $receipt->branch_id))) {
            $number = (string) DB::table('policies')->where('id', $receipt->for_policy_id)->value('number');
            $status .= " The money is held in suspense for {$number}; your branch manager allocates it.";
        }

        // Flow fix X5: printing the receipt for the customer is the next step.
        return redirect("/receipts/{$receipt->id}")->with('status', $status)->with('next', $nextSteps->afterReceipt(PageSupport::actor($request), $receipt->id));
    }

    public function show(Request $request, string $receipt): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeArea($actor, self::AREA);
        $model = Receipt::query()->findOrFail($receipt);
        $this->permissions->authorizeAny($actor, self::AREA, AuthorizationScope::branch($model->entity_id, $model->branch_id)); // G2: another branch's receipt is 403
        $money = fn (int $minor): string => PageSupport::money($minor, $model->currency);
        $item = SuspenseItem::query()->where('receipt_id', $model->id)->first();

        return Inertia::render('receipts/Show', [
            'receipt' => ['id' => $model->id, 'number' => $model->number, 'channel' => $model->channel, 'amount' => $money($model->amount_minor), 'value_date' => $model->value_date->toDateString(),
                // GA-38: who paid and which agent collected it, each linked.
                'payer' => $model->party_id === null ? null : ['id' => $model->party_id, 'name' => (string) DB::table('parties')->where('id', $model->party_id)->value('display_name')],
                'collected_by' => $model->collected_by_agent_id === null ? null : ['id' => $model->collected_by_agent_id, 'code' => (string) DB::table('producers')->where('id', $model->collected_by_agent_id)->value('code')],
                'reference' => $model->reference, 'status' => $model->status->value, 'cheque_no' => $model->cheque_no, 'cheque_bank' => $model->cheque_bank,
                'bounced_on' => $model->bounced_on?->toDateString(), 'bounce_reason' => $model->bounce_reason,
                // GA-03: the policy the money was taken for, while it waits in suspense for someone who allocates.
                'for_policy' => $model->for_policy_id === null ? null : ['id' => $model->for_policy_id, 'number' => (string) DB::table('policies')->where('id', $model->for_policy_id)->value('number')]],
            'allocations' => DB::table('receipt_allocations as a')->leftJoin('policies as p', 'p.id', '=', 'a.policy_id')->where('a.receipt_id', $model->id)->orderBy('a.allocated_at')
                ->get(['a.id', 'a.policy_id', 'p.number', 'a.amount_minor', 'a.posted_on', 'a.reversed_on'])
                ->map(fn (object $a): array => ['id' => (string) $a->id, 'policy_id' => $a->policy_id === null ? null : (string) $a->policy_id, 'policy_number' => $a->number, 'amount' => $money((int) $a->amount_minor), 'posted_on' => (string) $a->posted_on,
                    'reversed_on' => $a->reversed_on === null ? null : (string) $a->reversed_on])->values()->all(),
            'suspense' => $item === null ? null : ['id' => $item->id, 'amount' => $money($item->amount_minor), 'open' => $money($item->openMinor()), 'status' => $item->status->value],
            'documentUpload' => array_any(self::ATTACH_DOCUMENTS, fn (string $permission): bool => $this->permissions->has($actor, $permission, AuthorizationScope::branch($model->entity_id, $model->branch_id)))
                ? "/receipts/{$model->id}/documents" : null,
            'actions' => ['bounce' => $model->channel === 'cheque' && $model->status !== ReceiptStatus::Bounced && $this->permissions->has($actor, 'receipt.allocate', AuthorizationScope::branch($model->entity_id, $model->branch_id)),
                // Flow fix X5: print the receipt from the header; allocate what waits in suspense.
                'print' => app(NextSteps::class)->canPrintReceipt($actor, $model->id),
                'allocate' => $item !== null && $item->status->value === 'open' && $item->openMinor() > 0 && $this->permissions->has($actor, 'receipt.allocate', AuthorizationScope::branch($model->entity_id, $model->branch_id))],
        ]);
    }

    public function bounce(Request $request, string $receipt, ChequeBounceService $bounces): RedirectResponse
    {
        /** @var array{bounced_on: string, reason: string} $data */
        $data = $request->validate(['bounced_on' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:1000']]);
        $bounces->bounce($receipt, $data['reason'], PageSupport::actor($request), CarbonImmutable::parse($data['bounced_on']));

        return redirect("/receipts/{$receipt}")->with('status', 'Cheque marked as bounced; its allocations were reversed.');
    }

    public function suspense(Request $request, SuspenseQuery $query): Response
    {
        // Follow-up H1: opens for the area's permissions in any scope; a branch-scoped user sees only their branches' suspense and installments.
        $reach = $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);
        $entity = PageSupport::entity();
        $asOf = self::date($request, 'as_of');
        $ageing = $query->ageing($entity['id'], $asOf, $reach);

        return Inertia::render('suspense/Index', [
            'asOf' => $asOf->toDateString(),
            'ageing' => ['buckets' => array_map(fn (int $m): string => PageSupport::money($m, $entity['currency']), $ageing['buckets']), 'total' => PageSupport::money($ageing['total_minor'], $entity['currency']),
                'items' => array_map(fn (array $i): array => ['id' => $i['id'], 'receipt_id' => $i['receipt_id'], 'receipt_number' => $i['receipt_number'], 'reference' => $i['reference'],
                    'aged_since' => $i['aged_since'], 'days' => $i['days'], 'open' => PageSupport::money($i['open_minor'], $entity['currency'])], $ageing['items'])],
            'installments' => $this->outstandingInstallments($entity, $reach),
        ]);
    }

    public function allocate(Request $request, string $suspenseItem, SuspenseService $suspense): RedirectResponse
    {
        /** @var array{installment_id: string, amount: string, on: string} $data */
        $data = $request->validate(['installment_id' => ['required', 'uuid'], 'amount' => ['required', 'string'], 'on' => ['required', 'date_format:Y-m-d']]);
        $entity = PageSupport::entity();
        $suspense->allocate($suspenseItem, $data['installment_id'], PageSupport::minor('amount', $data['amount'], $entity['currency']), PageSupport::actor($request), CarbonImmutable::parse($data['on']));

        return back()->with('status', 'Suspense allocated.');
    }

    /** UX brief §6.3 allocation workbench: the receipt and its open suspense on the left, candidate installments (the payer's first) on the right. */
    public function allocateWorkbench(Request $request, string $receipt): Response
    {
        $actor = PageSupport::actor($request);
        $reach = $this->permissions->authorizeArea($actor, self::AREA);
        $entity = PageSupport::entity();
        $r = DB::table('receipts as r')->leftJoin('parties as p', 'p.id', '=', 'r.party_id')->leftJoin('policies as fp', 'fp.id', '=', 'r.for_policy_id')->where('r.id', $receipt)
            ->first(['r.id', 'r.number', 'r.party_id', 'r.entity_id', 'r.branch_id', 'r.amount_minor', 'r.currency', 'r.value_date', 'r.reference', 'r.channel', 'r.status', 'p.display_name', 'r.for_policy_id', 'fp.number as for_policy_number']) ?? abort(404);
        $this->permissions->authorizeAny($actor, self::AREA, AuthorizationScope::branch((string) $r->entity_id, (string) $r->branch_id)); // H1: another branch's receipt is 403
        $item = DB::table('suspense_items')->where('receipt_id', $receipt)->where('status', 'open')->first(['id', 'amount_minor', 'allocated_minor']);
        $candidates = [];
        // H1: candidate installments only on the user's branches' policies.
        $rows = $reach->constrain(DB::table('installments as i'), 'p.entity_id', 'p.branch_id')->join('policies as p', 'p.id', '=', 'i.policy_id')->leftJoin('parties as payer', 'payer.id', '=', 'i.payer_party_id')
            ->where('p.entity_id', $entity['id'])->whereIn('p.status', ['issued', 'active', 'lapsed', 'expired'])->whereRaw('i.amount_minor - i.paid_minor - i.cancelled_minor > 0')
            // GA-03: the policy the money was taken for comes first, then the payer's own installments.
            ->orderByRaw('case when p.id = ? then 0 when i.payer_party_id = ? then 1 else 2 end', [$r->for_policy_id, $r->party_id])->orderBy('i.due_date')->orderBy('p.number')->limit(500)
            ->get(['i.id', 'i.no', 'i.due_date', 'i.payer_party_id', 'p.number', 'payer.display_name', DB::raw('i.amount_minor - i.paid_minor - i.cancelled_minor as outstanding')]);
        foreach ($rows as $row) {
            $candidates[] = ['id' => (string) $row->id, 'policy_number' => (string) $row->number, 'no' => (int) $row->no, 'due_date' => (string) $row->due_date,
                'payer' => (string) $row->display_name, 'outstanding' => PageSupport::money((int) $row->outstanding, $entity['currency']),
                'payer_matches' => $r->party_id !== null && $row->payer_party_id === $r->party_id, 'for_this_policy' => $r->for_policy_number !== null && $row->number === $r->for_policy_number];
        }

        return Inertia::render('receipts/Allocate', [
            'receipt' => ['id' => (string) $r->id, 'number' => (string) $r->number, 'amount' => PageSupport::money((int) $r->amount_minor, (string) $r->currency), 'currency' => (string) $r->currency,
                'value_date' => (string) $r->value_date, 'reference' => $r->reference, 'channel' => (string) $r->channel, 'status' => (string) $r->status, 'payer' => $r->display_name,
                'open' => PageSupport::money($item === null ? 0 : (int) $item->amount_minor - (int) $item->allocated_minor, (string) $r->currency),
                'for_policy' => $r->for_policy_number === null ? null : (string) $r->for_policy_number],
            'suspenseItemId' => $item === null ? null : (string) $item->id,
            'candidates' => $candidates,
            'today' => app(BusinessClock::class)->today($entity['id'])->toDateString(), // slice 2.1b: the company's today, not the browser's
        ]);
    }

    /** One commit for the workbench: every line is allocated, or none is (the first refusal rolls the others back). */
    public function allocateMany(Request $request, string $suspenseItem, SuspenseService $suspense): RedirectResponse
    {
        /** @var array{on: string, lines: list<array{installment_id: string, amount: string}>} $data */
        $data = $request->validate(['on' => ['required', 'date_format:Y-m-d'], 'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.installment_id' => ['required', 'uuid'], 'lines.*.amount' => ['required', 'string']]);
        $entity = PageSupport::entity();
        $actor = PageSupport::actor($request);
        $total = 0;
        DB::transaction(function () use ($data, $entity, $actor, $suspenseItem, $suspense, &$total): void {
            foreach ($data['lines'] as $index => $line) {
                $amount = PageSupport::minor("lines.{$index}.amount", $line['amount'], $entity['currency']);
                $suspense->allocate($suspenseItem, $line['installment_id'], $amount, $actor, CarbonImmutable::parse($data['on']));
                $total += $amount;
            }
        });
        $open = DB::table('suspense_items')->where('id', $suspenseItem)->first(['amount_minor', 'allocated_minor']);
        $left = $open === null ? 0 : (int) $open->amount_minor - (int) $open->allocated_minor;
        $count = count($data['lines']);

        return back()->with('status', 'Allocated '.PageSupport::money($total, $entity['currency'])." to {$count} ".($count === 1 ? 'installment' : 'installments')
            .($left > 0 ? '; '.PageSupport::money($left, $entity['currency']).' is still unallocated.' : '; nothing is left in suspense.'));
    }

    public function refunds(Request $request, RefundableQuery $refundable): Response
    {
        $actor = PageSupport::actor($request);
        $reach = $this->permissions->authorizeArea($actor, self::AREA); // H1: a branch-scoped user sees only their branches' refunds
        $entity = PageSupport::entity();

        $rows = array_map(fn (array $r): array => $r + ['available' => PageSupport::money($r['available_minor'], $r['currency'])], $refundable->refundable($entity['id'], $reach));
        $policy = $request->query('policy');
        // GA-01: opened from a cancellation (/refunds?policy=…) the request starts with that policy and everything still refundable on it.
        $prefill = null;
        foreach ($rows as $row) {
            if ($row['policy_id'] === $policy) {
                $prefill = ['policy_id' => $row['policy_id'], 'amount' => $row['available'], 'reason' => 'Policy cancelled'];
            }
        }

        return Inertia::render('refunds/Index', [
            'refundable' => $rows,
            'prefill' => $prefill,
            'refunds' => $reach->constrain(DB::table('refunds as r'), 'r.entity_id', 'r.branch_id')->leftJoin('policies as p', 'p.id', '=', 'r.policy_id')->where('r.entity_id', $entity['id'])->orderByDesc('r.requested_at')->limit(100)
                ->get(['r.id', 'p.number', 'r.amount_minor', 'r.currency', 'r.reason', 'r.status', 'r.requested_at', 'r.decision_reason'])
                ->map(fn (object $r): array => ['id' => (string) $r->id, 'policy_number' => $r->number, 'amount' => PageSupport::money((int) $r->amount_minor, (string) $r->currency),
                    'reason' => (string) $r->reason, 'status' => (string) $r->status, 'requested_at' => (string) $r->requested_at, 'decision_reason' => $r->decision_reason])->values()->all(),
            // H1: offered to holders in any scope; RefundService checks the policy's or refund's branch.
            'can' => ['request' => ! $this->permissions->reach($actor, ['receipt.refund_request'])->isEmpty(), 'release' => ! $this->permissions->reach($actor, ['receipt.refund_release'])->isEmpty()],
        ]);
    }

    public function requestRefund(Request $request, RefundService $refunds): RedirectResponse
    {
        /** @var array{policy_id: string, amount: string, reason: string} $data */
        $data = $request->validate(['policy_id' => ['required', 'uuid'], 'amount' => ['required', 'string'], 'reason' => ['required', 'string', 'max:1000']]);
        $entity = PageSupport::entity();
        $refunds->request($data['policy_id'], PageSupport::minor('amount', $data['amount'], $entity['currency']), $data['reason'], PageSupport::actor($request));

        return redirect('/refunds')->with('status', 'Refund requested; someone else must release it.');
    }

    public function decideRefund(Request $request, string $refund, string $decision, RefundService $refunds): RedirectResponse
    {
        if ($decision === 'release') {
            /** @var array{paid_on: string} $data */
            $data = $request->validate(['paid_on' => ['required', 'date_format:Y-m-d']]);
            $refunds->release($refund, PageSupport::actor($request), CarbonImmutable::parse($data['paid_on']));
        } else {
            /** @var array{reason: string} $data */
            $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
            $refunds->reject($refund, $data['reason'], PageSupport::actor($request));
        }

        return redirect('/refunds')->with('status', $decision === 'release' ? 'Refund released.' : 'Refund rejected.');
    }

    public function agentCash(Request $request, AgentCashPositionQuery $position): Response
    {
        // H1: a branch-scoped user sees the agents of their branches (AgentDepositService checks a deposit on the agent's branch).
        $reach = $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);
        $entity = PageSupport::entity();
        $asOf = self::date($request, 'as_of');
        $result = $position->position($entity['id'], $asOf, $reach);
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);

        return Inertia::render('agentCash/Index', [
            'asOf' => $asOf->toDateString(),
            'position' => ['rows' => array_map(fn (array $r): array => ['agent_id' => $r['agent_id'], 'agent_code' => $r['agent_code'], 'collected' => $money($r['collected_minor']),
                'deposited' => $money($r['deposited_minor']), 'undeposited' => $money($r['undeposited_minor']), 'gl' => $money($r['gl_minor']), 'difference' => $money($r['difference_minor']),
                'oldest_undeposited_on' => $r['oldest_undeposited_on'], 'days_undeposited' => $r['days_undeposited']], $result['rows']),
                'totals' => array_map($money, $result['totals'])],
            'agents' => $reach->constrain(DB::table('producers as a')->join('branches as b', 'b.id', '=', 'a.branch_id'), 'b.entity_id', 'a.branch_id')->where('a.status', 'active')->orderBy('a.code')
                ->get(['a.id', 'a.code'])->map(fn (object $a): array => (array) $a)->values()->all(),
            'bankAccounts' => DB::table('bank_accounts')->where('entity_id', $entity['id'])->where('status', 'active')->orderBy('bank_name')
                ->get(['id', 'bank_name', 'account_no_masked'])->map(fn (object $b): array => (array) $b)->values()->all(),
        ]);
    }

    public function deposit(Request $request, AgentDepositService $deposits): RedirectResponse
    {
        /** @var array{agent_id: string, amount: string, deposited_on: string, bank_account_id?: string|null, reference?: string|null} $data */
        $data = $request->validate(['agent_id' => ['required', 'uuid'], 'amount' => ['required', 'string'], 'deposited_on' => ['required', 'date_format:Y-m-d'],
            'bank_account_id' => ['nullable', 'uuid'], 'reference' => ['nullable', 'string', 'max:255']]);
        $entity = PageSupport::entity();
        $deposit = $deposits->record($data['agent_id'], PageSupport::minor('amount', $data['amount'], $entity['currency']), ($data['bank_account_id'] ?? '') === '' ? null : $data['bank_account_id'],
            $data['reference'] ?? null, PageSupport::actor($request), CarbonImmutable::parse($data['deposited_on']));

        return back()->with('status', "Deposit {$deposit->number} recorded.");
    }

    public function cheques(Request $request, ChequeRegisterQuery $register): Response
    {
        $reach = $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA); // H1: only the user's branches' cheques
        $entity = PageSupport::entity();
        $from = self::date($request, 'from', app(BusinessClock::class)->today()->startOfMonth());
        $to = self::date($request, 'to');
        $result = $register->register($entity['id'], $from, $to, $reach);

        return Inertia::render('receipts/Cheques', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'register' => [
            'rows' => array_map(fn (array $r): array => $r + ['amount' => PageSupport::money($r['amount_minor'], $entity['currency'])], $result['rows']),
            'totals' => ['presented' => PageSupport::money($result['totals']['presented_minor'], $entity['currency']), 'bounced' => PageSupport::money($result['totals']['bounced_minor'], $entity['currency'])],
        ]]);
    }

    public function dunning(Request $request): Response
    {
        $reach = $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA); // H1: only notices on the user's branches' policies
        $entity = PageSupport::entity();
        $from = self::date($request, 'from', app(BusinessClock::class)->today()->startOfMonth());
        $to = self::date($request, 'to');

        return Inertia::render('receipts/Dunning', ['from' => $from->toDateString(), 'to' => $to->toDateString(),
            'notices' => $reach->constrain(DB::table('dunning_notices as n'), 'n.entity_id', 'p.branch_id')->join('policies as p', 'p.id', '=', 'n.policy_id')->join('installments as i', 'i.id', '=', 'n.installment_id')
                ->leftJoin('parties as payer', 'payer.id', '=', 'i.payer_party_id')->where('n.entity_id', $entity['id'])->whereBetween('n.issued_on', [$from->toDateString(), $to->toDateString()])
                ->orderByDesc('n.issued_on')->get(['n.id', 'p.id as policy_id', 'p.number', 'payer.display_name', 'n.level', 'n.days_overdue', 'n.outstanding_minor', 'n.issued_on'])
                ->map(fn (object $n): array => ['id' => (string) $n->id, 'policy_id' => (string) $n->policy_id, 'policy_number' => $n->number, 'payer' => (string) $n->display_name, 'level' => (int) $n->level,
                    'days_overdue' => (int) $n->days_overdue, 'outstanding' => PageSupport::money((int) $n->outstanding_minor, $entity['currency']), 'issued_on' => (string) $n->issued_on])->values()->all()]);
    }

    /**
     * Flow fix X1: the receipt of a policy's premium — the total outstanding, allocated line by line to its unpaid installments, oldest due first; null when
     * the policy is not this entity's (or outside the user's branches), is not in force or owes nothing.
     *
     * @param array{id: string, currency: string} $entity
     * @return array{policy: array{id: string, number: string}, amount: string, branch_id: string, allocations: list<array{installment_id: string, label: string, amount: string, outstanding: string}>}|null
     */
    private function prefill(array $entity, string $policyId, AreaReach $reach): ?array
    {
        $policy = $reach->constrain(DB::table('policies'), 'entity_id', 'branch_id')->where('id', $policyId)->where('entity_id', $entity['id'])->whereIn('status', NextSteps::COLLECTABLE_STATUSES)
            ->first(['id', 'number', 'branch_id', 'currency', 'policyholder_party_id']);
        $installments = $policy === null ? [] : NextSteps::outstandingInstallments($policyId);
        if ($policy === null || $installments === []) {
            return null;
        }
        $currency = (string) $policy->currency;
        $lines = array_map(fn (array $i): array => ['installment_id' => $i['id'], 'label' => \App\Modules\Insurance\Policy\Domain\InstallmentLabel::of((string) $policy->number, $i['no'], $i['endorsement_no']), 'amount' => PageSupport::money($i['outstanding_minor'], $currency),
            'outstanding' => PageSupport::money($i['outstanding_minor'], $currency)], $installments);

        $holder = (string) $policy->policyholder_party_id;
        $lastChannel = DB::table('receipts')->where('party_id', $holder)->where('status', '<>', 'bounced')->orderByDesc('value_date')->orderByDesc('created_at')->value('channel');

        return ['policy' => ['id' => (string) $policy->id, 'number' => (string) $policy->number], 'amount' => PageSupport::money(array_sum(array_column($installments, 'outstanding_minor')), $currency),
            'branch_id' => (string) $policy->branch_id, 'allocations' => $lines,
            'payer' => ['id' => $holder, 'label' => (string) DB::table('parties')->where('id', $holder)->value('display_name')],
            'channel' => is_string($lastChannel) && in_array($lastChannel, self::CHANNELS, true) ? $lastChannel : null];
    }

    /**
     * GA-07: an unmatched money-in line on one of the entity's bank statements, as receipt defaults.
     *
     * @param array{id: string, currency: string} $entity
     * @return array{amount: string, value_date: string, reference: string|null, bank_account_id: string}|null
     */
    private static function statementLine(array $entity, string $lineId): ?array
    {
        $line = DB::table('bank_statement_lines as l')->join('bank_accounts as b', 'b.id', '=', 'l.bank_account_id')->where('l.id', $lineId)->where('b.entity_id', $entity['id'])
            ->where('l.match_status', 'unmatched')->where('l.amount_minor', '>', 0)->first(['l.amount_minor', 'l.posted_on', 'l.reference', 'l.description', 'l.bank_account_id', 'b.currency']);

        return $line === null ? null : ['amount' => PageSupport::money((int) $line->amount_minor, (string) $line->currency), 'value_date' => substr((string) $line->posted_on, 0, 10),
            'reference' => $line->reference === null ? ($line->description === null ? null : (string) $line->description) : (string) $line->reference, 'bank_account_id' => (string) $line->bank_account_id];
    }

    /**
     * GA-38: the payer of a receipt nobody named — the policyholder when every allocated installment belongs to one; otherwise nobody.
     *
     * @param list<string> $installmentIds
     */
    private static function singleHolder(array $installmentIds): ?string
    {
        if ($installmentIds === []) {
            return null;
        }
        $holders = DB::table('installments as i')->join('policies as p', 'p.id', '=', 'i.policy_id')->whereIn('i.id', $installmentIds)->distinct()->pluck('p.policyholder_party_id');

        return $holders->count() === 1 ? (string) $holders->first() : null;
    }

    /**
     * @param array{id: string, currency: string} $entity
     * @return list<array{id: string, label: string, outstanding: string}>
     */
    private function outstandingInstallments(array $entity, AreaReach $reach): array
    {
        $rows = $reach->constrain(DB::table('installments as i'), 'p.entity_id', 'p.branch_id')->join('policies as p', 'p.id', '=', 'i.policy_id')->leftJoin('parties as payer', 'payer.id', '=', 'i.payer_party_id')
            ->where('p.entity_id', $entity['id'])->whereIn('p.status', ['issued', 'active', 'lapsed', 'expired'])->whereRaw('i.amount_minor - i.paid_minor - i.cancelled_minor > 0')
            ->orderBy('i.due_date')->orderBy('p.number')->limit(500)
            ->get(['i.id', 'p.number', 'i.no', 'i.endorsement_no', 'i.due_date', 'payer.display_name', DB::raw('i.amount_minor - i.paid_minor - i.cancelled_minor as outstanding')]);
        $options = [];
        foreach ($rows as $row) {
            $options[] = ['id' => (string) $row->id, 'label' => \App\Modules\Insurance\Policy\Domain\InstallmentLabel::of((string) $row->number, (int) $row->no, $row->endorsement_no === null ? null : (int) $row->endorsement_no)." · {$row->display_name} · due {$row->due_date}",
                'outstanding' => PageSupport::money((int) $row->outstanding, $entity['currency'])];
        }

        return $options;
    }

    public function attachDocument(Request $request, string $receipt, ObjectDocuments $documents): RedirectResponse
    {
        $model = Receipt::query()->findOrFail($receipt);
        $this->permissions->authorizeAny(PageSupport::actor($request), self::ATTACH_DOCUMENTS, AuthorizationScope::branch($model->entity_id, $model->branch_id));

        return $documents->attach($request, 'receipt', $model->id, "/receipts/{$model->id}");
    }

    public function downloadDocument(Request $request, string $receipt, string $document, ObjectDocuments $documents): StreamedResponse
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeArea($actor, self::AREA);
        $model = Receipt::query()->findOrFail($receipt);
        $this->permissions->authorizeAny($actor, self::AREA, AuthorizationScope::branch($model->entity_id, $model->branch_id));

        return $documents->download($request, 'receipt', $model->id, $document);
    }

    private static function date(Request $request, string $key, ?CarbonImmutable $default = null): CarbonImmutable
    {
        $value = $request->query($key);

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::parse($value) : ($default ?? app(BusinessClock::class)->today());
    }
}
