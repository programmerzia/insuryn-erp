<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Http\Controllers;

use App\Http\Pages\ObjectDocuments;
use App\Http\Pages\ObjectHistory;
use App\Http\Pages\PageSupport;
use App\Modules\Finance\Payables\Application\BillService;
use App\Modules\Finance\Payables\Application\SupplierService;
use App\Modules\Finance\Payables\Domain\Enums\BillStatus;
use App\Modules\Finance\Payables\Domain\Models\ApBill;
use App\Modules\Finance\Payables\Domain\Models\Supplier;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payables → Bills (addendum v2 §B.4 Screens): the queue (views: awaiting approval, due this week, overdue), the create form with lines to expense
 * accounts, and the bill page with its lines, payments, journal, documents (the supplier's invoice) and timeline.
 */
final class BillsPageController
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly BillService $bills,
    ) {}

    public function index(Request $request): Response
    {
        $this->permissions->authorizeArea(PageSupport::actor($request), PayablesArea::AREA);
        $entity = PageSupport::entity();
        $today = app(BusinessClock::class)->today();
        $view = in_array($request->query('view'), ['awaiting_approval', 'due_this_week', 'overdue', 'open'], true) ? (string) $request->query('view') : 'all';
        $rows = DB::table('ap_bills as b')->join('suppliers as s', 's.id', '=', 'b.supplier_id')->join('parties as p', 'p.id', '=', 's.party_id')->join('branches as br', 'br.id', '=', 'b.branch_id')
            ->where('b.entity_id', $entity['id'])
            ->when($view === 'awaiting_approval', fn ($q) => $q->where('b.status', 'pending_approval'))
            ->when($view === 'open', fn ($q) => $q->whereIn('b.status', BillStatus::open()))
            ->when($view === 'due_this_week', fn ($q) => $q->whereIn('b.status', BillStatus::open())->whereBetween('b.due_date', [$today->toDateString(), $today->addDays(7)->toDateString()]))
            ->when($view === 'overdue', fn ($q) => $q->whereIn('b.status', BillStatus::open())->where('b.due_date', '<', $today->toDateString()))
            ->orderByDesc('b.bill_date')->orderByDesc('b.created_at')->limit(PageSupport::LIST_PAGE_SIZE)
            ->get(['b.id', 'b.number', 'p.display_name', 'b.supplier_reference', 'b.bill_date', 'b.due_date', 'br.code as branch', 'b.gross_minor', 'b.payable_minor', 'b.paid_minor', 'b.status', 'b.description'])
            ->map(fn (object $b): array => ['id' => (string) $b->id, 'number' => $b->number ?? 'Draft', 'supplier' => (string) $b->display_name, 'reference' => (string) $b->supplier_reference,
                'bill_date' => (string) $b->bill_date, 'due_date' => (string) $b->due_date, 'branch' => (string) $b->branch, 'gross' => PageSupport::money((int) $b->gross_minor, 'BDT'),
                'payable' => PageSupport::money((int) $b->payable_minor, 'BDT'), 'outstanding' => PageSupport::money((int) $b->payable_minor - (int) $b->paid_minor, 'BDT'),
                'status' => (string) $b->status, 'description' => $b->description])->values()->all();

        return Inertia::render('payables/bills/Index', ['bills' => $rows, 'view' => $view, 'statuses' => array_column(BillStatus::cases(), 'value'),
            'canEnter' => $this->permissions->has(PageSupport::actor($request), BillService::ENTER)]);
    }

    public function create(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorize($actor, BillService::ENTER);
        $entity = PageSupport::entity();

        return Inertia::render('payables/bills/Create', [
            'today' => app(BusinessClock::class)->today()->toDateString(),
            'suppliers' => DB::table('suppliers as s')->join('parties as p', 'p.id', '=', 's.party_id')->where('s.entity_id', $entity['id'])->where('s.status', '<>', 'blocked')
                ->orderBy('p.display_name')->get(['s.id', 's.code', 'p.display_name', 's.category', 's.payment_terms_days', 's.default_account_id'])
                ->map(function (object $s): array {
                    $rates = SupplierService::rates((string) $s->category);

                    return ['id' => (string) $s->id, 'label' => "{$s->display_name} ({$s->code})", 'terms' => (int) $s->payment_terms_days,
                        'vat_bp' => $rates['vat_bp'], 'vds_bp' => $rates['vds_bp'], 'tds_bp' => $rates['tds_bp'], 'category' => $rates['label'],
                        'default_account' => $s->default_account_id === null ? null : ['id' => (string) $s->default_account_id, 'label' => (string) PayablesArea::accountLabel((string) $s->default_account_id)]];
                })->values()->all(),
            'branches' => DB::table('branches')->where('entity_id', $entity['id'])->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (object $b): array => ['value' => (string) $b->id, 'label' => "{$b->code} · {$b->name}"])->values()->all(),
            // Optional claim link for garage, surveyor and hospital bills: open and recent claims.
            'claims' => DB::table('claims as c')->join('policies as p', 'p.id', '=', 'c.policy_id')->leftJoin('parties as h', 'h.id', '=', 'p.policyholder_party_id')
                ->where('c.entity_id', $entity['id'])->orderByDesc('c.reported_on')->limit(200)->get(['c.id', 'c.number', 'c.policy_id', 'p.number as policy_number', 'h.display_name'])
                ->map(fn (object $c): array => ['value' => (string) $c->id, 'label' => "{$c->number} · {$c->policy_number} · {$c->display_name}", 'policy_id' => (string) $c->policy_id])->values()->all(),
            'inputVatRecoverable' => (bool) config('erp.payables.input_vat_recoverable', false),
            'supplierId' => is_string($request->query('supplier')) ? $request->query('supplier') : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $actor = PageSupport::actor($request);
        $bill = $this->bills->create($data['branch_id'], $data['supplier_id'], $data['supplier_reference'], CarbonImmutable::parse($data['bill_date']),
            ($data['due_date'] ?? '') === '' ? null : CarbonImmutable::parse((string) $data['due_date']), $data['description'] ?? null, $this->lines($data['lines']), $actor);
        if ((bool) ($data['send_for_approval'] ?? false)) {
            $bill = $this->bills->submit($bill->id, $actor);

            return redirect("/payables/bills/{$bill->id}")->with('status', "Bill {$bill->number} sent for approval.");
        }

        return redirect("/payables/bills/{$bill->id}")->with('status', 'Bill saved as a draft.');
    }

    public function show(Request $request, string $bill, ObjectHistory $history, ObjectDocuments $documents, SodGuard $sod): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeArea($actor, PayablesArea::AREA);
        $model = ApBill::query()->findOrFail($bill);
        $supplier = Supplier::query()->findOrFail($model->supplier_id);
        $party = (string) DB::table('parties')->where('id', $supplier->party_id)->value('display_name');
        $scope = AuthorizationScope::branch($model->entity_id, $model->branch_id);
        $can = fn (string $permission): bool => $this->permissions->has($actor, $permission, $scope);
        $money = fn (int $minor): string => PageSupport::money($minor, $model->currency);
        $status = $model->status;
        $pendingApproval = DB::table('approvals')->where('object_type', 'ap_bill')->where('object_id', $model->id)->where('status', 'pending')->exists();
        $journals = array_values(DB::table('journals')->where('source_type', 'ap_bill')->where('source_id', $model->id)->pluck('id')->map(fn ($id): string => (string) $id)->all());
        $inRun = DB::table('payment_run_items')->where('payable_type', 'ap_bill')->where('payable_id', $model->id)->where('status', 'included')->exists();

        return Inertia::render('payables/bills/Show', [
            'bill' => ['id' => $model->id, 'number' => $model->number ?? 'Draft bill', 'status' => $status->value, 'supplier' => ['id' => $supplier->id, 'name' => $party, 'code' => $supplier->code],
                'reference' => $model->supplier_reference, 'bill_date' => $model->bill_date->toDateString(), 'due_date' => $model->due_date->toDateString(),
                'accounting_date' => $model->accounting_date?->toDateString(), 'branch' => (string) DB::table('branches')->where('id', $model->branch_id)->value('code'),
                'description' => $model->description, 'currency' => $model->currency, 'net' => $money($model->net_minor), 'vat' => $money($model->vat_minor), 'vds' => $money($model->vds_minor),
                'tds' => $money($model->tds_minor), 'gross' => $money($model->gross_minor), 'payable' => $money($model->payable_minor), 'paid' => $money($model->paid_minor),
                'outstanding' => $money($model->outstandingMinor()), 'cancelled_reason' => $model->cancelled_reason, 'in_payment_run' => $inRun,
                'approval_via_inbox' => $pendingApproval],
            'lines' => DB::table('ap_bill_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->leftJoin('claims as c', 'c.id', '=', 'l.claim_id')->leftJoin('policies as p', 'p.id', '=', 'l.policy_id')
                ->where('l.bill_id', $model->id)->orderBy('l.line_no')
                ->get(['l.line_no', 'l.description', 'a.code', 'a.name', 'l.claim_id', 'c.number as claim_number', 'l.policy_id', 'p.number as policy_number', 'l.net_minor', 'l.vat_minor', 'l.vds_minor', 'l.tds_minor'])
                ->map(fn (object $l): array => ['line_no' => (int) $l->line_no, 'description' => (string) $l->description, 'account' => "{$l->code} {$l->name}",
                    'claim' => $l->claim_id === null ? null : ['id' => (string) $l->claim_id, 'number' => (string) $l->claim_number],
                    'policy' => $l->policy_id === null ? null : ['id' => (string) $l->policy_id, 'number' => (string) $l->policy_number],
                    'net' => $money((int) $l->net_minor), 'vat' => $money((int) $l->vat_minor), 'vds' => $money((int) $l->vds_minor), 'tds' => $money((int) $l->tds_minor)])->values()->all(),
            'payments' => DB::table('payment_run_items as i')->join('payment_runs as r', 'r.id', '=', 'i.run_id')->where('i.payable_type', 'ap_bill')->where('i.payable_id', $model->id)
                ->whereIn('i.status', ['included', 'released'])->orderBy('r.pay_date')->get(['r.id', 'r.number', 'r.pay_date', 'r.status', 'i.amount_minor'])
                ->map(fn (object $p): array => ['id' => (string) $p->id, 'number' => (string) $p->number, 'pay_date' => (string) $p->pay_date, 'status' => (string) $p->status,
                    'amount' => $money((int) $p->amount_minor)])->values()->all(),
            'today' => app(BusinessClock::class)->today()->toDateString(),
            'actions' => [
                'submit' => $status === BillStatus::Draft && $can(BillService::ENTER),
                'approve' => $status === BillStatus::PendingApproval && $can(BillService::APPROVE) && $actor !== $model->created_by && $actor !== $model->submitted_by
                    && ! $sod->wouldBlock($actor, BillService::APPROVE, AuditSubject::of('ap_bill', $model->id)),
                'reject' => $status === BillStatus::PendingApproval && $can(BillService::APPROVE) && $actor !== $model->created_by && $actor !== $model->submitted_by,
                'cancel' => ($status === BillStatus::Draft && ($can(BillService::ENTER) || $can(BillService::APPROVE)))
                    || ($status === BillStatus::Posted && $model->paid_minor === 0 && ! $inRun && $can(BillService::APPROVE)),
            ],
            'documentUpload' => $can(BillService::ENTER) || $can(BillService::APPROVE) ? "/payables/bills/{$model->id}/documents" : null,
            'timeline' => $history->timeline([['ap_bill', $model->id]]),
            'accounting' => Inertia::defer(fn (): array => $history->accounting($journals), 'history'),
            'audit' => Inertia::defer(fn (): array => $history->audit([['ap_bill', $model->id]]), 'history'),
            'documents' => Inertia::defer(fn (): array => $documents->forPage('ap_bill', $model->id, "/payables/bills/{$model->id}"), 'history'),
        ]);
    }

    public function submit(Request $request, string $bill): RedirectResponse
    {
        $model = $this->bills->submit($bill, PageSupport::actor($request));

        return redirect("/payables/bills/{$bill}")->with('status', "Bill {$model->number} sent for approval; someone else approves it.");
    }

    public function approve(Request $request, string $bill): RedirectResponse
    {
        $model = $this->bills->approve($bill, PageSupport::actor($request));

        return redirect("/payables/bills/{$bill}")->with('status', $model->status === BillStatus::Posted ? "Bill {$model->number} approved and posted." : 'Approval recorded; the next approver decides.');
    }

    public function reject(Request $request, string $bill): RedirectResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->bills->reject($bill, $data['reason'], PageSupport::actor($request));

        return redirect("/payables/bills/{$bill}")->with('status', 'Bill returned to draft.');
    }

    public function cancel(Request $request, string $bill): RedirectResponse
    {
        /** @var array{reason: string, on: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'on' => ['required', 'date_format:Y-m-d']]);
        $model = $this->bills->cancel($bill, $data['reason'], CarbonImmutable::parse($data['on']), PageSupport::actor($request));

        return redirect("/payables/bills/{$bill}")->with('status', "Bill {$model->number} cancelled.");
    }

    public function attachDocument(Request $request, string $bill, ObjectDocuments $documents): RedirectResponse
    {
        $model = ApBill::query()->findOrFail($bill);
        $this->permissions->authorizeAny(PageSupport::actor($request), [BillService::ENTER, BillService::APPROVE], AuthorizationScope::branch($model->entity_id, $model->branch_id));

        return $documents->attach($request, 'ap_bill', $model->id, "/payables/bills/{$model->id}");
    }

    public function downloadDocument(Request $request, string $bill, string $document, ObjectDocuments $documents): StreamedResponse
    {
        $this->permissions->authorizeArea(PageSupport::actor($request), PayablesArea::AREA);
        $model = ApBill::query()->findOrFail($bill);

        return $documents->download($request, 'ap_bill', $model->id, $document);
    }

    /** @return array{branch_id: string, supplier_id: string, supplier_reference: string, bill_date: string, due_date?: string|null, description?: string|null, send_for_approval?: bool, lines: list<array{description?: string|null, account_id: string, net: string, vat?: string|null, claim_id?: string|null, policy_id?: string|null}>} */
    private function validated(Request $request): array
    {
        /** @var array{branch_id: string, supplier_id: string, supplier_reference: string, bill_date: string, due_date?: string|null, description?: string|null, send_for_approval?: bool, lines: list<array{description?: string|null, account_id: string, net: string, vat?: string|null, claim_id?: string|null, policy_id?: string|null}>} $data */
        $data = $request->validate([
            'branch_id' => ['required', 'uuid'], 'supplier_id' => ['required', 'uuid'], 'supplier_reference' => ['required', 'string', 'max:64'],
            'bill_date' => ['required', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date_format:Y-m-d'], 'description' => ['nullable', 'string', 'max:2000'], 'send_for_approval' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.description' => ['nullable', 'string', 'max:500'], 'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.net' => ['required', 'string'], 'lines.*.vat' => ['nullable', 'string'], 'lines.*.claim_id' => ['nullable', 'uuid'], 'lines.*.policy_id' => ['nullable', 'uuid'],
        ], ['supplier_id.required' => 'Choose the supplier.', 'supplier_reference.required' => "Enter the supplier's invoice number.", 'lines.*.account_id.required' => 'Choose an expense account for each line.',
            'lines.*.net.required' => 'Enter the amount before VAT for each line.']);

        return $data;
    }

    /**
     * @param list<array{description?: string|null, account_id: string, net: string, vat?: string|null, claim_id?: string|null, policy_id?: string|null}> $lines
     * @return list<array{description: string, account_id: string, net_minor: int, vat_minor: int|null, claim_id: string|null, policy_id: string|null}>
     */
    private function lines(array $lines): array
    {
        $result = [];
        foreach ($lines as $index => $line) {
            $claimId = ($line['claim_id'] ?? '') === '' ? null : (string) $line['claim_id'];
            $result[] = ['description' => (string) ($line['description'] ?? ''), 'account_id' => $line['account_id'], 'net_minor' => PageSupport::minor("lines.{$index}.net", $line['net'], 'BDT'),
                'vat_minor' => ($line['vat'] ?? '') === '' ? null : PageSupport::minor("lines.{$index}.vat", $line['vat'], 'BDT'), 'claim_id' => $claimId,
                'policy_id' => ($line['policy_id'] ?? '') !== '' ? (string) $line['policy_id'] : ($claimId === null ? null : (string) DB::table('claims')->where('id', $claimId)->value('policy_id'))];
        }

        return $result;
    }
}
