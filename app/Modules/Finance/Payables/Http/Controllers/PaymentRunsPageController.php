<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Http\Controllers;

use App\Http\Pages\ObjectHistory;
use App\Http\Pages\PageSupport;
use App\Modules\Finance\Payables\Application\BankPaymentFile;
use App\Modules\Finance\Payables\Application\PaymentRunService;
use App\Modules\Finance\Payables\Domain\Enums\PaymentRunStatus;
use App\Modules\Finance\Payables\Domain\Models\PaymentRun;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payables → Payment runs (addendum v2 §B.4 Screens, slice 2.4): the queue, the new-run page (due bills filtered by due date and supplier, paid from a
 * bank account on a pay date) and the run workbench — submit, approve, release with the journal preview, cancel, download the bank file.
 */
final class PaymentRunsPageController
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly PaymentRunService $runs,
    ) {}

    public function index(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeArea($actor, PayablesArea::AREA);
        $entity = PageSupport::entity();
        // GA-40: the list pages on the server.
        $page = DB::table('payment_runs as r')->join('bank_accounts as b', 'b.id', '=', 'r.bank_account_id')->leftJoin('users as u', 'u.id', '=', 'r.created_by')
            ->where('r.entity_id', $entity['id'])->orderByDesc('r.pay_date')->orderByDesc('r.created_at')
            ->select(['r.id', 'r.number', 'r.pay_date', 'r.status', 'r.total_minor', 'r.item_count', 'b.bank_name', 'b.account_no_masked', 'u.name'])
            ->paginate(PageSupport::listPageSize())->withQueryString();
        $rows = [];
        foreach ($page->items() as $r) {
            /** @var object{id: string, number: string, pay_date: string, status: string, total_minor: int|string, item_count: int|string, bank_name: string, account_no_masked: string, name: string|null} $r */
            $rows[] = ['id' => (string) $r->id, 'number' => (string) $r->number, 'pay_date' => (string) $r->pay_date, 'status' => (string) $r->status,
                'total' => PageSupport::money((int) $r->total_minor, 'BDT'), 'bills' => (int) $r->item_count, 'bank' => "{$r->bank_name} {$r->account_no_masked}", 'prepared_by' => (string) ($r->name ?? '')];
        }

        return Inertia::render('payables/runs/Index', ['runs' => PageSupport::page($page, $rows), 'statuses' => array_column(PaymentRunStatus::cases(), 'value'),
            'canPrepare' => $this->permissions->has($actor, PaymentRunService::PREPARE)]);
    }

    public function create(Request $request): Response
    {
        $this->permissions->authorize(PageSupport::actor($request), PaymentRunService::PREPARE);
        $entity = PageSupport::entity();
        $today = app(BusinessClock::class)->today();
        $dueBy = is_string($request->query('due_by')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('due_by')) === 1 ? CarbonImmutable::parse($request->query('due_by')) : $today->addDays(7);
        $supplierId = is_string($request->query('supplier_id')) && preg_match('/^[0-9a-f-]{36}$/', $request->query('supplier_id')) === 1 ? $request->query('supplier_id') : null;

        return Inertia::render('payables/runs/Create', [
            'filters' => ['due_by' => $dueBy->toDateString(), 'supplier_id' => $supplierId ?? ''],
            'today' => $today->toDateString(),
            'bankAccounts' => PayablesArea::bankAccounts($entity['id']),
            // The supplier filter is a lookup; the chosen supplier comes back as its first value.
            'supplier' => $supplierId === null ? null : (($name = DB::table('suppliers as s')->join('parties as p', 'p.id', '=', 's.party_id')->where('s.id', $supplierId)->value('p.display_name')) === null
                ? null : ['id' => $supplierId, 'label' => (string) $name]),
            'bills' => array_map(fn (array $b): array => ['id' => $b['id'], 'number' => $b['number'], 'supplier' => $b['supplier'], 'reference' => $b['supplier_reference'],
                'due_date' => $b['due_date'], 'amount' => PageSupport::money($b['outstanding_minor'], 'BDT'), 'amount_minor' => $b['outstanding_minor']],
                $this->runs->dueBills($entity['id'], $dueBy, $supplierId)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var array{bank_account_id: string, pay_date: string, bill_ids: list<string>, send_for_approval?: bool} $data */
        $data = $request->validate(['bank_account_id' => ['required', 'uuid'], 'pay_date' => ['required', 'date_format:Y-m-d'], 'bill_ids' => ['required', 'array', 'min:1'],
            'bill_ids.*' => ['uuid'], 'send_for_approval' => ['nullable', 'boolean']], ['bill_ids.required' => 'Choose at least one bill to pay.', 'bank_account_id.required' => 'Choose the bank account to pay from.']);
        $actor = PageSupport::actor($request);
        $run = $this->runs->create(PageSupport::entity()['id'], $data['bank_account_id'], CarbonImmutable::parse($data['pay_date']), $data['bill_ids'], $actor);
        if ((bool) ($data['send_for_approval'] ?? false)) {
            $this->runs->submit($run->id, $actor);

            return redirect("/payables/payment-runs/{$run->id}")->with('status', "Payment run {$run->number} sent for approval.");
        }

        return redirect("/payables/payment-runs/{$run->id}")->with('status', "Payment run {$run->number} prepared.");
    }

    public function show(Request $request, string $run, ObjectHistory $history, SodGuard $sod): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeArea($actor, PayablesArea::AREA);
        $model = PaymentRun::query()->findOrFail($run);
        $money = fn (int $minor): string => PageSupport::money($minor, $model->currency);
        $bank = DB::table('bank_accounts')->where('id', $model->bank_account_id)->first(['bank_name', 'account_no_masked']);
        $names = DB::table('users')->whereIn('id', array_filter([$model->created_by, $model->approved_by, $model->released_by]))->pluck('name', 'id');
        $status = $model->status;
        $subject = AuditSubject::of('payment_run', $model->id);
        $journals = array_values(DB::table('journals')->where('source_type', 'payment_run')->where('source_id', $model->id)->pluck('id')->map(fn ($id): string => (string) $id)->all());

        return Inertia::render('payables/runs/Show', [
            'run' => ['id' => $model->id, 'number' => $model->number, 'status' => $status->value, 'pay_date' => $model->pay_date->toDateString(), 'currency' => $model->currency,
                'total' => $money($model->total_minor), 'bills' => $model->item_count, 'bank' => $bank === null ? '' : "{$bank->bank_name} {$bank->account_no_masked}",
                'prepared_by' => $names[$model->created_by] ?? null, 'approved_by' => $model->approved_by === null ? null : ($names[$model->approved_by] ?? null),
                'released_by' => $model->released_by === null ? null : ($names[$model->released_by] ?? null), 'cancelled_reason' => $model->cancelled_reason,
                'files' => DB::table('bank_payment_files')->where('run_id', $model->id)->orderBy('version')->get(['version', 'sha256', 'generated_at'])
                    ->map(fn (object $f): array => ['version' => (int) $f->version, 'sha256' => substr((string) $f->sha256, 0, 12), 'generated_at' => (string) $f->generated_at])->values()->all()],
            'items' => DB::table('payment_run_items as i')->join('parties as p', 'p.id', '=', 'i.payee_party_id')->join('ap_bills as b', 'b.id', '=', 'i.payable_id')
                ->where('i.run_id', $model->id)->orderBy('p.display_name')->get(['i.id', 'p.display_name', 'b.id as bill_id', 'b.number', 'b.supplier_reference', 'b.due_date', 'i.amount_minor', 'i.status', 'i.bank_account_snapshot'])
                ->map(function (object $i) use ($money): array {
                    /** @var array{bank_name?: string|null, bank_branch?: string|null, routing_no?: string|null, account_name?: string|null, account_no_masked?: string|null} $bank */
                    $bank = (array) json_decode((string) $i->bank_account_snapshot, true);

                    return ['id' => (string) $i->id, 'supplier' => (string) $i->display_name, 'bill_id' => (string) $i->bill_id, 'bill' => (string) $i->number, 'reference' => (string) $i->supplier_reference,
                        'due_date' => (string) $i->due_date, 'amount' => $money((int) $i->amount_minor), 'status' => (string) $i->status,
                        'bank' => trim(($bank['bank_name'] ?? '').' '.($bank['bank_branch'] ?? '')), 'routing_no' => $bank['routing_no'] ?? null, 'account' => $bank['account_no_masked'] ?? null];
                })->values()->all(),
            'actions' => [
                'submit' => $status === PaymentRunStatus::Draft && $this->permissions->has($actor, PaymentRunService::PREPARE),
                'approve' => $status === PaymentRunStatus::PendingApproval && $this->permissions->has($actor, PaymentRunService::APPROVE) && $actor !== $model->created_by
                    && ! $sod->wouldBlock($actor, PaymentRunService::APPROVE, $subject),
                'reject' => $status === PaymentRunStatus::PendingApproval && $this->permissions->has($actor, PaymentRunService::APPROVE) && $actor !== $model->created_by,
                'release' => $status === PaymentRunStatus::Approved && $this->permissions->has($actor, PaymentRunService::RELEASE) && $actor !== $model->created_by && $actor !== $model->approved_by
                    && ! $sod->wouldBlock($actor, PaymentRunService::RELEASE, $subject),
                'cancel' => in_array($status, [PaymentRunStatus::Draft, PaymentRunStatus::PendingApproval, PaymentRunStatus::Approved], true)
                    && ($this->permissions->has($actor, PaymentRunService::PREPARE) || $this->permissions->has($actor, PaymentRunService::APPROVE)),
                'bank_file' => $status === PaymentRunStatus::Released && ($this->permissions->has($actor, PaymentRunService::RELEASE) || $this->permissions->has($actor, PaymentRunService::PREPARE)),
            ],
            // Who is waiting: SoD means the next person is never the one who did the previous step.
            'waitingFor' => match ($status) {
                PaymentRunStatus::PendingApproval => 'Waiting for approval by someone other than the preparer (finance manager).',
                PaymentRunStatus::Approved => 'Waiting for release by someone who neither prepared nor approved it (CFO).',
                default => null,
            },
            'timeline' => $history->timeline([['payment_run', $model->id]]),
            'accounting' => Inertia::defer(fn (): array => $history->accounting($journals), 'history'),
            'audit' => Inertia::defer(fn (): array => $history->audit([['payment_run', $model->id]]), 'history'),
        ]);
    }

    public function submit(Request $request, string $run): RedirectResponse
    {
        $model = $this->runs->submit($run, PageSupport::actor($request));

        return redirect("/payables/payment-runs/{$run}")->with('status', "Payment run {$model->number} sent for approval.");
    }

    public function approve(Request $request, string $run): RedirectResponse
    {
        $model = $this->runs->approve($run, PageSupport::actor($request));

        return redirect("/payables/payment-runs/{$run}")->with('status', $model->status === PaymentRunStatus::Approved ? "Payment run {$model->number} approved; someone else releases it." : 'Approval recorded.');
    }

    public function reject(Request $request, string $run): RedirectResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->runs->reject($run, $data['reason'], PageSupport::actor($request));

        return redirect("/payables/payment-runs/{$run}")->with('status', 'Payment run returned to draft.');
    }

    public function release(Request $request, string $run): RedirectResponse
    {
        $model = $this->runs->release($run, PageSupport::actor($request));

        return redirect("/payables/payment-runs/{$run}")->with('status', "Payment run {$model->number} released. Download the bank file and upload it to the bank.");
    }

    public function cancel(Request $request, string $run): RedirectResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->runs->cancel($run, $data['reason'], PageSupport::actor($request));

        return redirect("/payables/payment-runs/{$run}")->with('status', 'Payment run cancelled; its bills can be paid again.');
    }

    public function bankFile(Request $request, string $run, BankPaymentFile $files): StreamedResponse
    {
        $file = $files->generate($run, PageSupport::actor($request));

        return response()->streamDownload(function () use ($file): void {
            echo $file['contents'];
        }, $file['name'], ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
