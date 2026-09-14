<?php

declare(strict_types=1);

namespace App\Modules\Finance\Expenses\Http\Controllers;

use App\Http\Pages\ObjectDocuments;
use App\Http\Pages\PageSupport;
use App\Modules\Finance\Expenses\Application\PettyCashService;
use App\Modules\Finance\FixedAssets\Http\Controllers\FixedAssetsPageController;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Petty cash screens (design addendum v2 §B.6): floats per branch, the float page (vouchers with receipts, replenishment, counts) and the petty cash book. */
final class PettyCashPageController
{
    public const AREA = ['pettycash.spend', 'pettycash.replenish', 'pettycash.approve', 'reports.financial'];

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function index(Request $request, PettyCashService $pettyCash): Response
    {
        $actor = PageSupport::actor($request);
        $reach = $this->permissions->authorizeArea($actor, self::AREA);
        $entity = PageSupport::entity();
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);

        return Inertia::render('pettyCash/Index', [
            'floats' => $reach->constrain(DB::table('petty_cash_floats as f'), 'f.entity_id', 'f.branch_id')->join('branches as b', 'b.id', '=', 'f.branch_id')->leftJoin('users as u', 'u.id', '=', 'f.custodian_user_id')
                ->where('f.entity_id', $entity['id'])->orderBy('b.code')->get(['f.id', 'f.code', 'f.name', 'b.code as branch', 'u.name as custodian', 'f.imprest_minor', 'f.status'])
                ->map(fn (object $f): array => ['id' => (string) $f->id, 'code' => (string) $f->code, 'name' => (string) $f->name, 'branch' => (string) $f->branch, 'custodian' => (string) ($f->custodian ?? ''),
                    'limit' => $money((int) $f->imprest_minor), 'on_hand' => $money($pettyCash->cashOnHand((string) $f->id)),
                    'to_replenish' => $money((int) DB::table('petty_cash_vouchers')->where('float_id', $f->id)->where('status', 'posted')->whereNull('replenishment_id')->sum('amount_minor')),
                    'pending' => DB::table('petty_cash_replenishments')->where('float_id', $f->id)->where('status', 'pending_approval')->exists(), 'status' => (string) $f->status])->values()->all(),
            'branches' => DB::table('branches')->where('entity_id', $entity['id'])->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $b): array => ['id' => (string) $b->id, 'label' => "{$b->code} · {$b->name}"])->values()->all(),
            'users' => DB::table('users')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'email'])->map(fn (object $u): array => ['id' => (string) $u->id, 'label' => "{$u->name} ({$u->email})"])->values()->all(),
            'accounts' => DB::table('accounts')->where('entity_id', $entity['id'])->where('type', 'asset')->where('is_postable', true)->where('is_control', false)->where('status', 'active')->orderBy('code')
                ->get(['id', 'code', 'name'])->map(fn (object $a): array => ['id' => (string) $a->id, 'label' => "{$a->code} · {$a->name}"])->values()->all(),
            'bankAccounts' => FixedAssetsPageController::bankAccounts($entity['id']),
            'defaultBranchId' => app(\App\Http\Pages\FormDefaults::class)->branch($actor, $entity['id']) ?? '',
            'can' => ['create' => $this->permissions->has($actor, 'pettycash.approve', AuthorizationScope::entity($entity['id']))],
        ]);
    }

    public function store(Request $request, PettyCashService $pettyCash): RedirectResponse
    {
        /** @var array{branch_id: string, code: string, name: string, custodian_user_id: string, limit: string, gl_account_id: string, bank_account_id: string, issued_on: string} $data */
        $data = $request->validate(['branch_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:32'], 'name' => ['required', 'string', 'max:255'], 'custodian_user_id' => ['required', 'uuid'],
            'limit' => ['required', 'string'], 'gl_account_id' => ['required', 'uuid'], 'bank_account_id' => ['required', 'uuid'], 'issued_on' => ['required', 'date_format:Y-m-d']]);
        $entity = PageSupport::entity();
        $id = $pettyCash->createFloat($entity['id'], $data['branch_id'], strtoupper($data['code']), $data['name'], $data['custodian_user_id'], PageSupport::minor('limit', $data['limit'], $entity['currency']),
            $data['gl_account_id'], $data['bank_account_id'], CarbonImmutable::parse($data['issued_on']), PageSupport::actor($request));

        return redirect("/petty-cash/{$id}")->with('status', 'Float issued to its custodian.');
    }

    public function show(Request $request, string $float, PettyCashService $pettyCash, \App\Http\Pages\ObjectHistory $history): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeArea($actor, self::AREA);
        $row = DB::table('petty_cash_floats as f')->join('branches as b', 'b.id', '=', 'f.branch_id')->leftJoin('users as u', 'u.id', '=', 'f.custodian_user_id')->where('f.id', $float)
            ->first(['f.*', 'b.code as branch_code', 'b.name as branch_name', 'u.name as custodian']) ?? abort(404);
        $scope = AuthorizationScope::branch((string) $row->entity_id, (string) $row->branch_id);
        $this->permissions->authorizeAny($actor, self::AREA, $scope);
        $money = fn (int $minor): string => PageSupport::money($minor, (string) $row->currency);
        $documents = DB::table('stored_documents')->where('object_type', 'petty_cash_voucher')->get(['id', 'object_id', 'original_name'])->groupBy('object_id');

        return Inertia::render('pettyCash/Show', [
            'float' => ['id' => (string) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name, 'branch' => "{$row->branch_code} · {$row->branch_name}", 'custodian' => (string) ($row->custodian ?? ''),
                'limit' => $money((int) $row->imprest_minor), 'on_hand' => $money($pettyCash->cashOnHand($float)), 'status' => (string) $row->status,
                'to_replenish' => $money((int) DB::table('petty_cash_vouchers')->where('float_id', $float)->where('status', 'posted')->whereNull('replenishment_id')->sum('amount_minor'))],
            'vouchers' => DB::table('petty_cash_vouchers as v')->join('accounts as a', 'a.id', '=', 'v.account_id')->leftJoin('petty_cash_replenishments as r', 'r.id', '=', 'v.replenishment_id')->where('v.float_id', $float)
                ->orderByDesc('v.voucher_date')->orderByDesc('v.number')->get(['v.id', 'v.number', 'v.voucher_date', 'v.payee', 'v.description', 'a.code', 'a.name', 'v.amount_minor', 'r.number as replenishment', 'r.status as replenishment_status'])
                ->map(fn (object $v): array => ['id' => (string) $v->id, 'number' => (string) $v->number, 'date' => (string) $v->voucher_date, 'payee' => (string) $v->payee, 'description' => (string) $v->description,
                    'account' => "{$v->code} {$v->name}", 'amount' => $money((int) $v->amount_minor), 'replenishment' => $v->replenishment === null ? null : "{$v->replenishment} ({$v->replenishment_status})",
                    'receipts' => ($documents[(string) $v->id] ?? collect())->map(fn (object $d): array => ['name' => (string) $d->original_name, 'url' => "/petty-cash/vouchers/{$v->id}/documents/{$d->id}"])->values()->all()])->values()->all(),
            'replenishments' => DB::table('petty_cash_replenishments as r')->leftJoin('users as q', 'q.id', '=', 'r.requested_by')->leftJoin('users as d', 'd.id', '=', 'r.decided_by')->where('r.float_id', $float)->orderByDesc('r.requested_at')
                ->get(['r.id', 'r.number', 'r.amount_minor', 'r.status', 'r.requested_at', 'r.paid_on', 'q.name as requested_by', 'd.name as decided_by', 'r.decision_reason'])
                ->map(fn (object $r): array => ['id' => (string) $r->id, 'number' => (string) $r->number, 'amount' => $money((int) $r->amount_minor), 'status' => (string) $r->status, 'requested_at' => (string) $r->requested_at,
                    'paid_on' => $r->paid_on, 'requested_by' => (string) ($r->requested_by ?? ''), 'decided_by' => $r->decided_by, 'reason' => $r->decision_reason])->values()->all(),
            'counts' => DB::table('petty_cash_counts as c')->leftJoin('users as u', 'u.id', '=', 'c.counted_by')->where('c.float_id', $float)->orderByDesc('c.counted_on')
                ->get(['c.counted_on', 'c.counted_minor', 'c.expected_minor', 'c.difference_minor', 'u.name', 'c.note'])
                ->map(fn (object $c): array => ['date' => (string) $c->counted_on, 'counted' => $money((int) $c->counted_minor), 'expected' => $money((int) $c->expected_minor), 'difference' => $money((int) $c->difference_minor),
                    'by' => (string) ($c->name ?? ''), 'note' => $c->note])->values()->all(),
            'accounts' => DB::table('accounts')->where('entity_id', $row->entity_id)->where('type', 'expense')->where('is_postable', true)->where('is_control', false)->where('status', 'active')->orderBy('code')
                ->get(['id', 'code', 'name'])->map(fn (object $a): array => ['id' => (string) $a->id, 'label' => "{$a->code} · {$a->name}"])->values()->all(),
            'bankAccounts' => FixedAssetsPageController::bankAccounts((string) $row->entity_id),
            'can' => ['spend' => $this->permissions->has($actor, 'pettycash.spend', $scope), 'replenish' => $this->permissions->has($actor, 'pettycash.replenish', $scope),
                'approve' => $this->permissions->has($actor, 'pettycash.approve', $scope), 'count' => $this->permissions->has($actor, 'pettycash.replenish', $scope) && $row->custodian_user_id !== $actor],
            // UI consistency pass: the float page is an object page with Timeline, Accounting and Audit like the other documents.
            'timeline' => $history->timeline([['petty_cash_float', $float]]),
            'accounting' => Inertia::defer(fn (): array => $history->accounting(self::journals($float)), 'history'),
            'audit' => Inertia::defer(fn (): array => $history->audit([['petty_cash_float', $float],
                ...DB::table('petty_cash_replenishments')->where('float_id', $float)->pluck('id')->map(fn (mixed $id): array => ['petty_cash_replenishment', (string) $id])->all()]), 'history'),
        ]);
    }

    /** @return list<string> journals posted for the float: its issue, its vouchers, replenishments and count differences */
    private static function journals(string $float): array
    {
        $ids = fn (string $table): array => DB::table($table)->where('float_id', $float)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

        return array_values(DB::table('journals')->where(fn ($q) => $q->where('source_type', 'petty_cash_float')->where('source_id', $float))
            ->orWhere(fn ($q) => $q->where('source_type', 'petty_cash_voucher')->whereIn('source_id', $ids('petty_cash_vouchers')))
            ->orWhere(fn ($q) => $q->where('source_type', 'petty_cash_replenishment')->whereIn('source_id', $ids('petty_cash_replenishments')))
            ->orWhere(fn ($q) => $q->where('source_type', 'petty_cash_count')->whereIn('source_id', $ids('petty_cash_counts')))
            ->pluck('id')->map(fn (mixed $id): string => (string) $id)->all());
    }

    public function spend(Request $request, string $float, PettyCashService $pettyCash, ObjectDocuments $documents): RedirectResponse
    {
        /** @var array{voucher_date: string, payee: string, description: string, account_id: string, amount: string} $data */
        $data = $request->validate(['voucher_date' => ['required', 'date_format:Y-m-d'], 'payee' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:500'],
            'account_id' => ['required', 'uuid'], 'amount' => ['required', 'string'], 'receipt' => ['nullable', 'file', 'max:10240', 'extensions:pdf,jpg,jpeg,png']]);
        $id = $pettyCash->spend($float, CarbonImmutable::parse($data['voucher_date']), $data['payee'], $data['description'], $data['account_id'], PageSupport::minor('amount', $data['amount'], PageSupport::entity()['currency']),
            PageSupport::actor($request));
        $file = $request->file('receipt');
        if ($file instanceof UploadedFile) {
            app(\App\Modules\Platform\Documents\DocumentStore::class)->attach('petty_cash_voucher', $id, $file, PageSupport::actor($request), 'Receipt');
        }

        return redirect("/petty-cash/{$float}")->with('status', 'Voucher paid.');
    }

    public function replenish(Request $request, string $float, PettyCashService $pettyCash): RedirectResponse
    {
        /** @var array{bank_account_id: string} $data */
        $data = $request->validate(['bank_account_id' => ['required', 'uuid']]);
        $pettyCash->requestReplenishment($float, $data['bank_account_id'], PageSupport::actor($request), app(BusinessClock::class)->today());

        return redirect("/petty-cash/{$float}")->with('status', 'Replenishment requested; the finance manager approves it.');
    }

    public function decide(Request $request, string $replenishment, string $decision, PettyCashService $pettyCash): RedirectResponse
    {
        $floatId = (string) DB::table('petty_cash_replenishments')->where('id', $replenishment)->value('float_id');
        if ($decision === 'approve') {
            /** @var array{paid_on: string} $data */
            $data = $request->validate(['paid_on' => ['required', 'date_format:Y-m-d']]);
            $pettyCash->approveReplenishment($replenishment, CarbonImmutable::parse($data['paid_on']), PageSupport::actor($request));
        } else {
            /** @var array{reason: string} $data */
            $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
            $pettyCash->rejectReplenishment($replenishment, $data['reason'], PageSupport::actor($request));
        }

        return redirect("/petty-cash/{$floatId}")->with('status', $decision === 'approve' ? 'Replenishment approved and paid from the bank.' : 'Replenishment rejected.');
    }

    public function count(Request $request, string $float, PettyCashService $pettyCash): RedirectResponse
    {
        /** @var array{counted_on: string, counted: string, note?: string|null} $data */
        $data = $request->validate(['counted_on' => ['required', 'date_format:Y-m-d'], 'counted' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:500']]);
        $pettyCash->count($float, CarbonImmutable::parse($data['counted_on']), PageSupport::minor('counted', $data['counted'], PageSupport::entity()['currency']), $data['note'] ?? null, PageSupport::actor($request));

        return redirect("/petty-cash/{$float}")->with('status', 'Cash count recorded.');
    }

    public function downloadDocument(Request $request, string $voucher, string $document, ObjectDocuments $documents): StreamedResponse
    {
        $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);

        return $documents->download($request, 'petty_cash_voucher', $voucher, $document);
    }

    /** The petty cash book of a float for a period (reports/Show): opening balance, receipts, payments, running balance. */
    public function book(Request $request, PettyCashService $pettyCash): Response
    {
        $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);

        return Inertia::render('reports/Show', $this->bookTable($request, $pettyCash) + ['report' => 'petty-cash-book', 'baseUrl' => '/petty-cash/book']);
    }

    public function exportBook(Request $request, PettyCashService $pettyCash): StreamedResponse
    {
        $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);
        $page = $this->bookTable($request, $pettyCash);

        return FixedAssetsPageController::download($page['title'], $page['columns'], $page['rows'], "petty-cash-book-{$page['filters']['from']}-to-{$page['filters']['to']}", $request->query('format') === 'xlsx' ? 'xlsx' : 'csv');
    }

    /** @return array{title: string, filter: string, filters: array{from: string, to: string, as_of: string, by: string}, columns: list<array{key: string, label: string, align: string}>, rows: list<array{cells: array<string, mixed>, link: string|null}>, totals: array<string, string>, summaries: list<mixed>, related: list<array{label: string, href: string}>} */
    private function bookTable(Request $request, PettyCashService $pettyCash): array
    {
        $entity = PageSupport::entity();
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);
        $today = app(BusinessClock::class)->today();
        $date = fn (string $key, CarbonImmutable $default): CarbonImmutable => is_string($request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) === 1 ? CarbonImmutable::parse((string) $request->query($key)) : $default;
        $from = $date('from', $today->startOfMonth());
        $to = $date('to', $today);
        $floats = DB::table('petty_cash_floats as f')->join('branches as b', 'b.id', '=', 'f.branch_id')->where('f.entity_id', $entity['id'])->orderBy('b.code')->get(['f.id', 'f.code', 'f.name']);
        $floatId = is_string($request->query('float')) ? $request->query('float') : (string) ($floats->first()->id ?? '');
        $float = $floats->firstWhere('id', $floatId);
        $rows = [];
        $opening = 0;
        $in = 0;
        $out = 0;
        if ($float !== null) {
            $opening = $pettyCash->cashOnHand($floatId, $from->subDay());
            $balance = $opening;
            $rows[] = ['cells' => ['date' => $from->toDateString(), 'number' => '', 'description' => 'Balance brought forward', 'account' => '', 'receipt' => '', 'payment' => '', 'balance' => $money($balance)], 'link' => null];
            foreach ($pettyCash->movements($floatId, $from, $to) as $entry) {
                $balance += $entry['amount_minor'];
                $entry['amount_minor'] > 0 ? $in += $entry['amount_minor'] : $out -= $entry['amount_minor'];
                $rows[] = ['cells' => ['date' => $entry['date'], 'number' => $entry['number'], 'description' => $entry['description'], 'account' => $entry['account'] ?? '',
                    'receipt' => $entry['amount_minor'] > 0 ? $money($entry['amount_minor']) : '', 'payment' => $entry['amount_minor'] < 0 ? $money(-$entry['amount_minor']) : '', 'balance' => $money($balance)],
                    'link' => "/petty-cash/{$floatId}"];
            }
        }

        return ['title' => $float === null ? 'Petty cash book' : "Petty cash book · {$float->code} {$float->name}", 'filter' => 'range',
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'as_of' => $to->toDateString(), 'by' => 'float'],
            'columns' => array_map(fn (array $c): array => ['key' => $c[0], 'label' => $c[1], 'align' => $c[2] ?? 'left'], [['date', 'Date'], ['number', 'Voucher'], ['description', 'Particulars'], ['account', 'Account'],
                ['receipt', 'Received', 'right'], ['payment', 'Paid', 'right'], ['balance', 'Balance', 'right']]),
            'rows' => $rows, 'totals' => ['opening_balance' => $money($opening), 'received' => $money($in), 'paid' => $money($out), 'closing_balance' => $money($opening + $in - $out)], 'summaries' => [],
            'related' => array_values($floats->map(fn (object $f): array => ['label' => (string) $f->code, 'href' => '/petty-cash/book?'.http_build_query(['float' => $f->id, 'from' => $from->toDateString(), 'to' => $to->toDateString()])])->all())];
    }
}
