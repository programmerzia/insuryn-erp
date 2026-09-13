<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Http\Controllers;

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
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Collections screens: receipts, suspense, refunds, agent cash, cheque register and dunning notices (design §2.4, §4.2, §4.9; spec §4). */
final class CollectionsPageController
{
    public const AREA = ['receipt.create', 'receipt.allocate', 'receipt.refund_request', 'receipt.refund_release', 'reports.financial'];
    private const CHANNELS = ['bank_transfer', 'cash', 'cheque', 'card', 'mobile_money'];

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function index(Request $request): Response
    {
        $this->authorize($request);
        $entity = PageSupport::entity();
        $page = DB::table('receipts')->where('entity_id', $entity['id'])->orderByDesc('value_date')->orderByDesc('number')->paginate(PageSupport::LIST_PAGE_SIZE)->withQueryString();
        $rows = [];
        foreach ($page->items() as $r) {
            /** @var object{id: string, number: string, channel: string, amount_minor: int|string, currency: string, value_date: string, reference: string|null, status: string, collected_by_agent_id: string|null} $r */
            $rows[] = ['id' => $r->id, 'number' => $r->number, 'channel' => $r->channel, 'amount' => PageSupport::money((int) $r->amount_minor, $r->currency), 'value_date' => $r->value_date,
                'reference' => $r->reference, 'status' => $r->status, 'agent_collection' => $r->collected_by_agent_id !== null];
        }

        return Inertia::render('receipts/Index', ['receipts' => PageSupport::page($page, $rows)]);
    }

    public function create(Request $request): Response
    {
        $this->authorize($request);
        $entity = PageSupport::entity();

        return Inertia::render('receipts/Create', [
            'entity' => $entity,
            'channels' => self::CHANNELS,
            'branches' => DB::table('branches')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $b): array => (array) $b)->values()->all(),
            'bankAccounts' => DB::table('bank_accounts')->where('entity_id', $entity['id'])->where('status', 'active')->orderBy('bank_name')
                ->get(['id', 'bank_name', 'account_no_masked'])->map(fn (object $b): array => (array) $b)->values()->all(),
            'agents' => DB::table('producers')->where('status', 'active')->orderBy('code')->get(['id', 'code'])->map(fn (object $a): array => (array) $a)->values()->all(),
            'installments' => $this->outstandingInstallments($entity),
        ]);
    }

    public function store(Request $request, ReceiptService $receipts): RedirectResponse
    {
        /** @var array{branch_id: string, channel: string, amount: string, value_date: string, reference?: string|null, bank_account_id?: string|null, cheque_no?: string|null, cheque_bank?: string|null, cheque_date?: string|null, collected_by_agent_id?: string|null, allocations?: list<array{installment_id: string, amount: string}>} $data */
        $data = $request->validate(['branch_id' => ['required', 'uuid'], 'channel' => ['required', 'in:'.implode(',', self::CHANNELS)], 'amount' => ['required', 'string'],
            'value_date' => ['required', 'date_format:Y-m-d'], 'reference' => ['nullable', 'string', 'max:255'], 'bank_account_id' => ['nullable', 'uuid'],
            'cheque_no' => ['nullable', 'string', 'max:64'], 'cheque_bank' => ['nullable', 'string', 'max:255'], 'cheque_date' => ['nullable', 'date_format:Y-m-d'],
            'collected_by_agent_id' => ['nullable', 'uuid'], 'allocations' => ['sometimes', 'array'], 'allocations.*.installment_id' => ['required', 'uuid'], 'allocations.*.amount' => ['required', 'string']]);
        $entity = PageSupport::entity();
        $allocations = [];
        foreach ($data['allocations'] ?? [] as $index => $line) {
            $allocations[] = new AllocationLine($line['installment_id'], PageSupport::minor("allocations.{$index}.amount", $line['amount'], $entity['currency']));
        }
        $cheque = isset($data['cheque_no'], $data['cheque_bank'], $data['cheque_date']) && $data['cheque_no'] !== ''
            ? new ChequeDetails($data['cheque_no'], $data['cheque_bank'], CarbonImmutable::parse($data['cheque_date'])) : null;
        $receipt = $receipts->record(new RecordReceiptRequest($entity['id'], $data['branch_id'], null, $data['channel'], PageSupport::minor('amount', $data['amount'], $entity['currency']),
            $entity['currency'], CarbonImmutable::parse($data['value_date']), $data['bank_account_id'] ?? null, $data['reference'] ?? null, $allocations, $cheque,
            ($data['collected_by_agent_id'] ?? '') === '' ? null : $data['collected_by_agent_id']), PageSupport::actor($request));

        return redirect("/receipts/{$receipt->id}")->with('status', "Receipt {$receipt->number} recorded.");
    }

    public function show(Request $request, string $receipt): Response
    {
        $actor = $this->authorize($request);
        $model = Receipt::query()->findOrFail($receipt);
        $money = fn (int $minor): string => PageSupport::money($minor, $model->currency);
        $item = SuspenseItem::query()->where('receipt_id', $model->id)->first();

        return Inertia::render('receipts/Show', [
            'receipt' => ['id' => $model->id, 'number' => $model->number, 'channel' => $model->channel, 'amount' => $money($model->amount_minor), 'value_date' => $model->value_date->toDateString(),
                'reference' => $model->reference, 'status' => $model->status->value, 'cheque_no' => $model->cheque_no, 'cheque_bank' => $model->cheque_bank,
                'bounced_on' => $model->bounced_on?->toDateString(), 'bounce_reason' => $model->bounce_reason],
            'allocations' => DB::table('receipt_allocations as a')->leftJoin('policies as p', 'p.id', '=', 'a.policy_id')->where('a.receipt_id', $model->id)->orderBy('a.allocated_at')
                ->get(['a.id', 'p.number', 'a.amount_minor', 'a.posted_on', 'a.reversed_on'])
                ->map(fn (object $a): array => ['id' => (string) $a->id, 'policy_number' => $a->number, 'amount' => $money((int) $a->amount_minor), 'posted_on' => (string) $a->posted_on,
                    'reversed_on' => $a->reversed_on === null ? null : (string) $a->reversed_on])->values()->all(),
            'suspense' => $item === null ? null : ['id' => $item->id, 'amount' => $money($item->amount_minor), 'open' => $money($item->openMinor()), 'status' => $item->status->value],
            'actions' => ['bounce' => $model->channel === 'cheque' && $model->status !== ReceiptStatus::Bounced && $this->permissions->has($actor, 'receipt.allocate')],
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
        $this->authorize($request);
        $entity = PageSupport::entity();
        $asOf = self::date($request, 'as_of');
        $ageing = $query->ageing($entity['id'], $asOf);

        return Inertia::render('suspense/Index', [
            'asOf' => $asOf->toDateString(),
            'ageing' => ['buckets' => array_map(fn (int $m): string => PageSupport::money($m, $entity['currency']), $ageing['buckets']), 'total' => PageSupport::money($ageing['total_minor'], $entity['currency']),
                'items' => array_map(fn (array $i): array => ['id' => $i['id'], 'receipt_id' => $i['receipt_id'], 'receipt_number' => $i['receipt_number'], 'reference' => $i['reference'],
                    'aged_since' => $i['aged_since'], 'days' => $i['days'], 'open' => PageSupport::money($i['open_minor'], $entity['currency'])], $ageing['items'])],
            'installments' => $this->outstandingInstallments($entity),
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
        $this->authorize($request);
        $entity = PageSupport::entity();
        $r = DB::table('receipts as r')->leftJoin('parties as p', 'p.id', '=', 'r.party_id')->where('r.id', $receipt)
            ->first(['r.id', 'r.number', 'r.party_id', 'r.amount_minor', 'r.currency', 'r.value_date', 'r.reference', 'r.channel', 'r.status', 'p.display_name']) ?? abort(404);
        $item = DB::table('suspense_items')->where('receipt_id', $receipt)->where('status', 'open')->first(['id', 'amount_minor', 'allocated_minor']);
        $candidates = [];
        $rows = DB::table('installments as i')->join('policies as p', 'p.id', '=', 'i.policy_id')->leftJoin('parties as payer', 'payer.id', '=', 'i.payer_party_id')
            ->where('p.entity_id', $entity['id'])->whereIn('p.status', ['issued', 'active', 'lapsed', 'expired'])->whereRaw('i.amount_minor - i.paid_minor - i.cancelled_minor > 0')
            ->orderByRaw('case when i.payer_party_id = ? then 0 else 1 end', [$r->party_id])->orderBy('i.due_date')->orderBy('p.number')->limit(500)
            ->get(['i.id', 'i.no', 'i.due_date', 'i.payer_party_id', 'p.number', 'payer.display_name', DB::raw('i.amount_minor - i.paid_minor - i.cancelled_minor as outstanding')]);
        foreach ($rows as $row) {
            $candidates[] = ['id' => (string) $row->id, 'policy_number' => (string) $row->number, 'no' => (int) $row->no, 'due_date' => (string) $row->due_date,
                'payer' => (string) $row->display_name, 'outstanding' => PageSupport::money((int) $row->outstanding, $entity['currency']),
                'payer_matches' => $r->party_id !== null && $row->payer_party_id === $r->party_id];
        }

        return Inertia::render('receipts/Allocate', [
            'receipt' => ['id' => (string) $r->id, 'number' => (string) $r->number, 'amount' => PageSupport::money((int) $r->amount_minor, (string) $r->currency), 'currency' => (string) $r->currency,
                'value_date' => (string) $r->value_date, 'reference' => $r->reference, 'channel' => (string) $r->channel, 'status' => (string) $r->status, 'payer' => $r->display_name,
                'open' => PageSupport::money($item === null ? 0 : (int) $item->amount_minor - (int) $item->allocated_minor, (string) $r->currency)],
            'suspenseItemId' => $item === null ? null : (string) $item->id,
            'candidates' => $candidates,
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
        $actor = $this->authorize($request);
        $entity = PageSupport::entity();

        return Inertia::render('refunds/Index', [
            'refundable' => array_map(fn (array $r): array => $r + ['available' => PageSupport::money($r['available_minor'], $r['currency'])], $refundable->refundable($entity['id'])),
            'refunds' => DB::table('refunds as r')->leftJoin('policies as p', 'p.id', '=', 'r.policy_id')->where('r.entity_id', $entity['id'])->orderByDesc('r.requested_at')->limit(100)
                ->get(['r.id', 'p.number', 'r.amount_minor', 'r.currency', 'r.reason', 'r.status', 'r.requested_at', 'r.decision_reason'])
                ->map(fn (object $r): array => ['id' => (string) $r->id, 'policy_number' => $r->number, 'amount' => PageSupport::money((int) $r->amount_minor, (string) $r->currency),
                    'reason' => (string) $r->reason, 'status' => (string) $r->status, 'requested_at' => (string) $r->requested_at, 'decision_reason' => $r->decision_reason])->values()->all(),
            'can' => ['request' => $this->permissions->has($actor, 'receipt.refund_request'), 'release' => $this->permissions->has($actor, 'receipt.refund_release')],
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
        $this->authorize($request);
        $entity = PageSupport::entity();
        $asOf = self::date($request, 'as_of');
        $result = $position->position($entity['id'], $asOf);
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);

        return Inertia::render('agentCash/Index', [
            'asOf' => $asOf->toDateString(),
            'position' => ['rows' => array_map(fn (array $r): array => ['agent_id' => $r['agent_id'], 'agent_code' => $r['agent_code'], 'collected' => $money($r['collected_minor']),
                'deposited' => $money($r['deposited_minor']), 'undeposited' => $money($r['undeposited_minor']), 'gl' => $money($r['gl_minor']), 'difference' => $money($r['difference_minor']),
                'oldest_undeposited_on' => $r['oldest_undeposited_on'], 'days_undeposited' => $r['days_undeposited']], $result['rows']),
                'totals' => array_map($money, $result['totals'])],
            'agents' => DB::table('producers')->where('status', 'active')->orderBy('code')->get(['id', 'code'])->map(fn (object $a): array => (array) $a)->values()->all(),
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
        $this->authorize($request);
        $entity = PageSupport::entity();
        $from = self::date($request, 'from', CarbonImmutable::today()->startOfMonth());
        $to = self::date($request, 'to');
        $result = $register->register($entity['id'], $from, $to);

        return Inertia::render('receipts/Cheques', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'register' => [
            'rows' => array_map(fn (array $r): array => $r + ['amount' => PageSupport::money($r['amount_minor'], $entity['currency'])], $result['rows']),
            'totals' => ['presented' => PageSupport::money($result['totals']['presented_minor'], $entity['currency']), 'bounced' => PageSupport::money($result['totals']['bounced_minor'], $entity['currency'])],
        ]]);
    }

    public function dunning(Request $request): Response
    {
        $this->authorize($request);
        $entity = PageSupport::entity();
        $from = self::date($request, 'from', CarbonImmutable::today()->startOfMonth());
        $to = self::date($request, 'to');

        return Inertia::render('receipts/Dunning', ['from' => $from->toDateString(), 'to' => $to->toDateString(),
            'notices' => DB::table('dunning_notices as n')->join('policies as p', 'p.id', '=', 'n.policy_id')->join('installments as i', 'i.id', '=', 'n.installment_id')
                ->leftJoin('parties as payer', 'payer.id', '=', 'i.payer_party_id')->where('n.entity_id', $entity['id'])->whereBetween('n.issued_on', [$from->toDateString(), $to->toDateString()])
                ->orderByDesc('n.issued_on')->get(['n.id', 'p.id as policy_id', 'p.number', 'payer.display_name', 'n.level', 'n.days_overdue', 'n.outstanding_minor', 'n.issued_on'])
                ->map(fn (object $n): array => ['id' => (string) $n->id, 'policy_id' => (string) $n->policy_id, 'policy_number' => $n->number, 'payer' => (string) $n->display_name, 'level' => (int) $n->level,
                    'days_overdue' => (int) $n->days_overdue, 'outstanding' => PageSupport::money((int) $n->outstanding_minor, $entity['currency']), 'issued_on' => (string) $n->issued_on])->values()->all()]);
    }

    /**
     * @param array{id: string, currency: string} $entity
     * @return list<array{id: string, label: string, outstanding: string}>
     */
    private function outstandingInstallments(array $entity): array
    {
        $rows = DB::table('installments as i')->join('policies as p', 'p.id', '=', 'i.policy_id')->leftJoin('parties as payer', 'payer.id', '=', 'i.payer_party_id')
            ->where('p.entity_id', $entity['id'])->whereIn('p.status', ['issued', 'active', 'lapsed', 'expired'])->whereRaw('i.amount_minor - i.paid_minor - i.cancelled_minor > 0')
            ->orderBy('i.due_date')->orderBy('p.number')->limit(500)
            ->get(['i.id', 'p.number', 'i.no', 'i.due_date', 'payer.display_name', DB::raw('i.amount_minor - i.paid_minor - i.cancelled_minor as outstanding')]);
        $options = [];
        foreach ($rows as $row) {
            $options[] = ['id' => (string) $row->id, 'label' => "{$row->number} #{$row->no} · {$row->display_name} · due {$row->due_date}",
                'outstanding' => PageSupport::money((int) $row->outstanding, $entity['currency'])];
        }

        return $options;
    }

    private function authorize(Request $request): string
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);

        return $actor;
    }

    private static function date(Request $request, string $key, ?CarbonImmutable $default = null): CarbonImmutable
    {
        $value = $request->query($key);

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::parse($value) : ($default ?? CarbonImmutable::today());
    }
}
