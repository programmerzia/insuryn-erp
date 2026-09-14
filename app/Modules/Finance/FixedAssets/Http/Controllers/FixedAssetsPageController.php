<?php

declare(strict_types=1);

namespace App\Modules\Finance\FixedAssets\Http\Controllers;

use App\Http\Pages\ObjectDocuments;
use App\Http\Pages\ObjectHistory;
use App\Http\Pages\PageSupport;
use App\Modules\Accounting\Application\Queries\FiscalPeriodQuery;
use App\Modules\Finance\FixedAssets\Application\DepreciationRun;
use App\Modules\Finance\FixedAssets\Application\FixedAssetReconciliation;
use App\Modules\Finance\FixedAssets\Application\FixedAssetRegisterQuery;
use App\Modules\Finance\FixedAssets\Application\FixedAssetService;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exports\XlsxWriter;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fixed asset screens (design addendum v2 §B.7): the register queue with capitalisation, the asset page (depreciation schedule, movements, disposal,
 * documents, accounting), asset classes, the monthly depreciation batch (preview → post) and the register report with its reconciliation to the ledger.
 */
final class FixedAssetsPageController
{
    public const AREA = ['fa.manage', 'fa.post_depreciation', 'reports.financial'];

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function index(Request $request, FixedAssetRegisterQuery $register): Response
    {
        $actor = PageSupport::actor($request);
        $reach = $this->permissions->authorizeArea($actor, self::AREA);
        $entity = PageSupport::entity();
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);
        $today = app(BusinessClock::class)->today();
        $onBooks = array_column($register->register($entity['id'], $today), null, 'id');
        $assets = $reach->constrain(DB::table('fixed_assets as f'), 'f.entity_id', 'f.branch_id')->join('asset_classes as c', 'c.id', '=', 'f.class_id')->join('branches as b', 'b.id', '=', 'f.branch_id')
            ->where('f.entity_id', $entity['id'])->orderByDesc('f.acquired_on')->orderByDesc('f.number')
            ->get(['f.id', 'f.number', 'f.description', 'c.name as class_name', 'b.code as branch_code', 'f.location', 'f.custodian', 'f.acquired_on', 'f.cost_minor', 'f.status', 'f.disposed_on'])
            ->map(fn (object $f): array => ['id' => (string) $f->id, 'number' => (string) $f->number, 'description' => (string) $f->description, 'class' => (string) $f->class_name,
                'branch' => (string) $f->branch_code, 'location' => $f->location, 'custodian' => $f->custodian, 'acquired_on' => (string) $f->acquired_on, 'cost' => $money((int) $f->cost_minor),
                'accumulated' => isset($onBooks[(string) $f->id]) ? $money($onBooks[(string) $f->id]['accumulated_minor']) : null,
                'nbv' => $money(isset($onBooks[(string) $f->id]) ? $onBooks[(string) $f->id]['nbv_minor'] : 0), 'status' => (string) $f->status])->values()->all();

        return Inertia::render('fixedAssets/Index', [
            'assets' => $assets,
            'classes' => DB::table('asset_classes')->where('entity_id', $entity['id'])->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'capitalisation_threshold_minor'])
                ->map(fn (object $c): array => ['id' => (string) $c->id, 'label' => "{$c->code} · {$c->name}", 'threshold' => $money((int) $c->capitalisation_threshold_minor)])->values()->all(),
            'branches' => $this->branches($actor, $entity['id'], ['fa.manage']),
            'bankAccounts' => self::bankAccounts($entity['id']),
            'can' => ['manage' => ! $this->permissions->reach($actor, ['fa.manage'])->isEmpty(), 'depreciate' => $this->permissions->has($actor, 'fa.post_depreciation', AuthorizationScope::entity($entity['id']))],
        ]);
    }

    public function store(Request $request, FixedAssetService $assets): RedirectResponse
    {
        /** @var array{class_id: string, branch_id: string, description: string, serial_no?: string|null, location?: string|null, custodian?: string|null, supplier?: string|null, invoice_ref?: string|null, acquired_on: string, cost: string, paid_via: string, bank_account_id?: string|null} $data */
        $data = $request->validate(['class_id' => ['required', 'uuid'], 'branch_id' => ['required', 'uuid'], 'description' => ['required', 'string', 'max:255'],
            'serial_no' => ['nullable', 'string', 'max:255'], 'location' => ['nullable', 'string', 'max:255'], 'custodian' => ['nullable', 'string', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:255'], 'invoice_ref' => ['nullable', 'string', 'max:255'], 'acquired_on' => ['required', 'date_format:Y-m-d'],
            'cost' => ['required', 'string'], 'paid_via' => ['required', Rule::in(['bank', 'payable'])], 'bank_account_id' => ['nullable', 'required_if:paid_via,bank', 'uuid']],
            ['bank_account_id.required_if' => 'Choose the bank account the asset was paid from.']);
        $entity = PageSupport::entity();
        $id = $assets->acquire($entity['id'], [...$data, 'cost_minor' => PageSupport::minor('cost', $data['cost'], $entity['currency'])], PageSupport::actor($request));

        return redirect("/fixed-assets/{$id}?tab=documents")->with('status', 'Asset capitalised. Attach the supplier\'s invoice here.');
    }

    public function show(Request $request, string $asset, ObjectHistory $history, ObjectDocuments $documents, FixedAssetRegisterQuery $register): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeArea($actor, self::AREA);
        $row = DB::table('fixed_assets as f')->join('asset_classes as c', 'c.id', '=', 'f.class_id')->join('branches as b', 'b.id', '=', 'f.branch_id')->where('f.id', $asset)
            ->first(['f.*', 'c.name as class_name', 'c.code as class_code', 'b.code as branch_code', 'b.name as branch_name']) ?? abort(404);
        $this->permissions->authorizeAny($actor, self::AREA, AuthorizationScope::branch((string) $row->entity_id, (string) $row->branch_id));
        $money = fn (int $minor): string => PageSupport::money($minor, (string) $row->currency);
        $today = app(BusinessClock::class)->today();
        $accumulated = $register->accumulated($row, $row->disposed_on === null ? $today : CarbonImmutable::parse((string) $row->disposed_on));
        $canManage = $this->permissions->has($actor, 'fa.manage', AuthorizationScope::branch((string) $row->entity_id, (string) $row->branch_id));
        $disposal = DB::table('asset_disposals')->where('asset_id', $asset)->first();
        $journals = array_values(DB::table('journal_lines')->whereRaw("dims_ext->>'asset' = ?", [$asset])->distinct()->pluck('journal_id')->map(fn (mixed $j): string => (string) $j)->all());
        $subjects = [['fixed_asset', $asset]];

        return Inertia::render('fixedAssets/Show', [
            'asset' => ['id' => (string) $row->id, 'number' => (string) $row->number, 'description' => (string) $row->description, 'class' => "{$row->class_code} · {$row->class_name}",
                'branch' => "{$row->branch_code} · {$row->branch_name}", 'branch_id' => (string) $row->branch_id, 'location' => $row->location, 'custodian' => $row->custodian, 'serial_no' => $row->serial_no,
                'supplier' => $row->supplier, 'invoice_ref' => $row->invoice_ref, 'acquired_on' => (string) $row->acquired_on, 'status' => (string) $row->status,
                'method' => $row->method === 'straight_line' ? "Straight line over {$row->useful_life_months} months" : 'Reducing balance at '.PageSupport::percent((int) $row->rate_bp).'% a year',
                'cost' => $money((int) $row->cost_minor), 'residual' => $money((int) $row->residual_minor), 'accumulated' => $money($accumulated), 'nbv' => $money((int) $row->cost_minor - $accumulated),
                'source' => $row->source_type === 'opening' ? 'Brought in with the opening balances on '.$row->opening_as_of : ($row->paid_via === 'bank' ? 'Paid from the bank' : 'Bought on credit')],
            'schedule' => array_merge(
                (int) $row->opening_accumulated_minor > 0 ? [['period' => (string) $row->opening_as_of, 'amount' => $money((int) $row->opening_accumulated_minor), 'accumulated' => $money((int) $row->opening_accumulated_minor),
                    'nbv' => $money((int) $row->cost_minor - (int) $row->opening_accumulated_minor), 'opening' => true]] : [],
                DB::table('asset_depreciation')->where('asset_id', $asset)->orderBy('period_ends')->get(['period_ends', 'amount_minor', 'accumulated_minor', 'nbv_minor'])
                    ->map(fn (object $d): array => ['period' => (string) $d->period_ends, 'amount' => $money((int) $d->amount_minor), 'accumulated' => $money((int) $d->accumulated_minor), 'nbv' => $money((int) $d->nbv_minor), 'opening' => false])->all()),
            'movements' => DB::table('asset_movements as m')->join('branches as fb', 'fb.id', '=', 'm.from_branch_id')->join('branches as tb', 'tb.id', '=', 'm.to_branch_id')->where('m.asset_id', $asset)->orderBy('m.moved_on')
                ->get(['m.moved_on', 'fb.code as from_code', 'tb.code as to_code', 'm.to_location', 'm.reason'])
                ->map(fn (object $m): array => ['moved_on' => (string) $m->moved_on, 'from' => (string) $m->from_code, 'to' => (string) $m->to_code, 'location' => $m->to_location, 'reason' => (string) $m->reason])->values()->all(),
            'disposal' => $disposal === null ? null : ['number' => (string) $disposal->number, 'date' => (string) $disposal->disposal_date, 'kind' => (string) $disposal->kind, 'proceeds' => $money((int) $disposal->proceeds_minor),
                'nbv' => $money((int) $disposal->nbv_minor), 'gain_loss' => $money((int) $disposal->gain_loss_minor), 'reason' => (string) $disposal->reason],
            'branches' => $this->branches($actor, (string) $row->entity_id, ['fa.manage']),
            'bankAccounts' => self::bankAccounts((string) $row->entity_id),
            'can' => ['manage' => $canManage && $row->status !== 'disposed'],
            'timeline' => $history->timeline($subjects),
            'accounting' => Inertia::defer(fn (): array => $history->accounting($journals), 'history'),
            'audit' => Inertia::defer(fn (): array => $history->audit($subjects), 'history'),
            'documents' => Inertia::defer(fn (): array => $documents->forPage('fixed_asset', $asset, "/fixed-assets/{$asset}"), 'history'),
            'documentUpload' => $canManage ? "/fixed-assets/{$asset}/documents" : null,
        ]);
    }

    public function transfer(Request $request, string $asset, FixedAssetService $assets): RedirectResponse
    {
        /** @var array{to_branch_id: string, moved_on: string, location?: string|null, reason: string} $data */
        $data = $request->validate(['to_branch_id' => ['required', 'uuid'], 'moved_on' => ['required', 'date_format:Y-m-d'], 'location' => ['nullable', 'string', 'max:255'], 'reason' => ['required', 'string', 'max:500']]);
        $assets->transfer($asset, $data['to_branch_id'], CarbonImmutable::parse($data['moved_on']), $data['location'] ?? null, $data['reason'], PageSupport::actor($request));

        return redirect("/fixed-assets/{$asset}")->with('status', 'Asset moved to the new branch.');
    }

    public function dispose(Request $request, string $asset, FixedAssetService $assets): RedirectResponse
    {
        /** @var array{kind: string, disposal_date: string, proceeds?: string|null, bank_account_id?: string|null, reason: string} $data */
        $data = $request->validate(['kind' => ['required', Rule::in(['sale', 'write_off'])], 'disposal_date' => ['required', 'date_format:Y-m-d'], 'proceeds' => ['nullable', 'string'],
            'bank_account_id' => ['nullable', 'uuid'], 'reason' => ['required', 'string', 'max:500']]);
        $proceeds = $data['kind'] === 'sale' && ($data['proceeds'] ?? '') !== '' ? PageSupport::minor('proceeds', $data['proceeds'], PageSupport::entity()['currency']) : 0;
        $assets->dispose($asset, $data['kind'], CarbonImmutable::parse($data['disposal_date']), $proceeds, $data['bank_account_id'] ?? null, $data['reason'], PageSupport::actor($request));

        return redirect("/fixed-assets/{$asset}")->with('status', $data['kind'] === 'sale' ? 'Asset sold; the gain or loss is posted.' : 'Asset written off.');
    }

    public function attachDocument(Request $request, string $asset, ObjectDocuments $documents): RedirectResponse
    {
        $row = DB::table('fixed_assets')->where('id', $asset)->first(['id', 'entity_id', 'branch_id']) ?? abort(404);
        $this->permissions->authorize(PageSupport::actor($request), 'fa.manage', AuthorizationScope::branch((string) $row->entity_id, (string) $row->branch_id));

        return $documents->attach($request, 'fixed_asset', $asset, "/fixed-assets/{$asset}");
    }

    public function downloadDocument(Request $request, string $asset, string $document, ObjectDocuments $documents): StreamedResponse
    {
        $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);

        return $documents->download($request, 'fixed_asset', $asset, $document);
    }

    public function classes(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeArea($actor, self::AREA);
        $entity = PageSupport::entity();
        $accounts = DB::table('accounts')->where('entity_id', $entity['id'])->whereIn('type', ['asset', 'expense', 'income'])->where('is_postable', true)->where('status', 'active')
            ->orderBy('code')->get(['id', 'code', 'name', 'type']);

        return Inertia::render('fixedAssets/Classes', [
            'classes' => DB::table('asset_classes as c')->join('accounts as ca', 'ca.id', '=', 'c.cost_account_id')->join('accounts as aa', 'aa.id', '=', 'c.accumulated_account_id')
                ->join('accounts as ea', 'ea.id', '=', 'c.expense_account_id')->where('c.entity_id', $entity['id'])->orderBy('c.code')
                ->get(['c.*', 'ca.code as cost_code', 'aa.code as accumulated_code', 'ea.code as expense_code'])
                ->map(fn (object $c): array => ['id' => (string) $c->id, 'code' => (string) $c->code, 'name' => (string) $c->name, 'method' => (string) $c->method,
                    'useful_life_months' => $c->useful_life_months === null ? '' : (string) $c->useful_life_months, 'rate' => $c->rate_bp === null ? '' : PageSupport::percent((int) $c->rate_bp),
                    'residual' => PageSupport::percent((int) $c->residual_bp), 'threshold' => PageSupport::money((int) $c->capitalisation_threshold_minor, $entity['currency']),
                    'cost_account_id' => (string) $c->cost_account_id, 'accumulated_account_id' => (string) $c->accumulated_account_id, 'expense_account_id' => (string) $c->expense_account_id,
                    'disposal_account_id' => $c->disposal_account_id === null ? '' : (string) $c->disposal_account_id,
                    'accounts' => "{$c->cost_code} / {$c->accumulated_code} / {$c->expense_code}", 'assets' => DB::table('fixed_assets')->where('class_id', $c->id)->count()])->values()->all(),
            'accounts' => $accounts->map(fn (object $a): array => ['id' => (string) $a->id, 'label' => "{$a->code} · {$a->name}", 'type' => (string) $a->type])->values()->all(),
            'can' => ['manage' => $this->permissions->has($actor, 'fa.manage', AuthorizationScope::entity($entity['id']))],
        ]);
    }

    public function saveClass(Request $request, FixedAssetService $assets, ?string $class = null): RedirectResponse
    {
        /** @var array{code: string, name: string, method: string, useful_life_months?: string|null, rate?: string|null, residual: string, threshold: string, cost_account_id: string, accumulated_account_id: string, expense_account_id: string, disposal_account_id?: string|null} $data */
        $data = $request->validate(['code' => ['required', 'string', 'max:32'], 'name' => ['required', 'string', 'max:255'], 'method' => ['required', Rule::in(['straight_line', 'reducing_balance'])],
            'useful_life_months' => ['nullable', 'required_if:method,straight_line', 'integer', 'min:1', 'max:1200'], 'rate' => ['nullable', 'required_if:method,reducing_balance', 'string'],
            'residual' => ['required', 'string'], 'threshold' => ['required', 'string'], 'cost_account_id' => ['required', 'uuid'], 'accumulated_account_id' => ['required', 'uuid'],
            'expense_account_id' => ['required', 'uuid'], 'disposal_account_id' => ['nullable', 'uuid']]);
        $entity = PageSupport::entity();
        $assets->saveClass($entity['id'], ['code' => $data['code'], 'name' => $data['name'], 'method' => $data['method'],
            'useful_life_months' => $data['method'] === 'straight_line' ? (int) ($data['useful_life_months'] ?? 0) : null,
            'rate_bp' => $data['method'] === 'reducing_balance' ? PageSupport::basisPoints('rate', $data['rate'] ?? '') : null, 'residual_bp' => PageSupport::basisPoints('residual', $data['residual']),
            'capitalisation_threshold_minor' => PageSupport::minor('threshold', $data['threshold'], $entity['currency']), 'cost_account_id' => $data['cost_account_id'],
            'accumulated_account_id' => $data['accumulated_account_id'], 'expense_account_id' => $data['expense_account_id'], 'disposal_account_id' => ($data['disposal_account_id'] ?? '') === '' ? null : $data['disposal_account_id']],
            PageSupport::actor($request), $class);

        return redirect('/fixed-assets/classes')->with('status', 'Asset class saved.');
    }

    public function defaultClasses(Request $request, FixedAssetService $assets): RedirectResponse
    {
        $added = $assets->addDefaultClasses(PageSupport::entity()['id'], PageSupport::actor($request));

        return redirect('/fixed-assets/classes')->with('status', $added === 0 ? 'The default classes are already there.' : "{$added} default class(es) added. Check their rates before capitalising assets.");
    }

    public function depreciation(Request $request, DepreciationRun $run, FiscalPeriodQuery $periods): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeArea($actor, self::AREA);
        $entity = PageSupport::entity();
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);
        $options = DB::table('fiscal_periods as p')->join('books as k', 'k.id', '=', 'p.book_id')->where('k.is_primary', true)->where('p.entity_id', $entity['id'])
            ->orderBy('p.starts')->get(['p.id', 'p.starts', 'p.ends', 'p.status']);
        $today = app(BusinessClock::class)->today();
        $requested = $request->query('period');
        // The month to depreciate by default: the earliest open month with depreciation still to post, else the current month.
        $selected = is_string($requested) ? $periods->find($requested) : null;
        if ($selected === null) {
            foreach ($options as $option) {
                $view = $periods->find((string) $option->id);
                if ($view !== null && $view->isOpen() && $view->starts->lessThanOrEqualTo($today) && $run->preview($view) !== []) {
                    $selected = $view;
                    break;
                }
            }
            $selected ??= $periods->containing($entity['id'], $today);
        }
        $preview = $selected === null ? [] : $run->preview($selected);

        return Inertia::render('fixedAssets/Depreciation', [
            'periods' => $options->map(fn (object $p): array => ['id' => (string) $p->id, 'label' => CarbonImmutable::parse((string) $p->starts)->format('F Y'), 'status' => (string) $p->status])->values()->all(),
            'period' => $selected === null ? null : ['id' => $selected->id, 'label' => $selected->starts->format('F Y'), 'status' => $selected->status, 'ends' => $selected->ends->toDateString()],
            'preview' => array_map(fn (array $r): array => ['asset_id' => $r['asset_id'], 'number' => $r['number'], 'description' => $r['description'], 'class' => $r['class_code'], 'branch' => $r['branch_code'],
                'amount' => $money($r['amount_minor']), 'accumulated' => $money($r['accumulated_minor']), 'nbv' => $money($r['nbv_minor'])], $preview),
            'total' => $money(array_sum(array_column($preview, 'amount_minor'))),
            'runs' => DB::table('asset_depreciation_runs as r')->leftJoin('users as u', 'u.id', '=', 'r.posted_by')->where('r.entity_id', $entity['id'])->orderByDesc('r.posted_at')->limit(24)
                ->get(['r.period_id', 'r.period_ends', 'r.assets_count', 'r.total_minor', 'r.posted_at', 'u.name'])
                ->map(fn (object $r): array => ['period' => CarbonImmutable::parse((string) $r->period_ends)->format('F Y'), 'period_id' => (string) $r->period_id, 'assets' => (int) $r->assets_count,
                    'total' => $money((int) $r->total_minor), 'posted_at' => (string) $r->posted_at, 'posted_by' => (string) ($r->name ?? '')])->values()->all(),
            'can' => ['post' => $this->permissions->has($actor, 'fa.post_depreciation', AuthorizationScope::entity($entity['id']))],
        ]);
    }

    public function postDepreciation(Request $request, string $period, DepreciationRun $run): RedirectResponse
    {
        $count = $run->post($period, PageSupport::actor($request));

        return redirect("/fixed-assets/depreciation?period={$period}")->with('status', $count === 0 ? 'Nothing left to depreciate for this month.' : "Depreciation posted for {$count} asset(s).");
    }

    /** The fixed asset register report as at a date, with totals by class and branch and the reconciliation to the ledger (reports/Show). */
    public function register(Request $request, FixedAssetRegisterQuery $register, FixedAssetReconciliation $reconciliation, FiscalPeriodQuery $periods): Response
    {
        $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);

        return Inertia::render('reports/Show', $this->registerTable($request, $register, $reconciliation, $periods) + ['report' => 'fixed-asset-register', 'baseUrl' => '/fixed-assets/register']);
    }

    public function export(Request $request, FixedAssetRegisterQuery $register, FixedAssetReconciliation $reconciliation, FiscalPeriodQuery $periods): StreamedResponse
    {
        $this->permissions->authorizeArea(PageSupport::actor($request), self::AREA);
        $format = $request->query('format') === 'xlsx' ? 'xlsx' : 'csv';
        $page = $this->registerTable($request, $register, $reconciliation, $periods);

        return self::download($page['title'], $page['columns'], $page['rows'], "fixed-asset-register-{$page['filters']['as_of']}", $format);
    }

    /**
     * @param list<array{key: string, label: string, align: string}> $columns
     * @param list<array{cells: array<string, mixed>}> $rows
     */
    public static function download(string $title, array $columns, array $rows, string $name, string $format): StreamedResponse
    {
        $header = array_column($columns, 'label');
        $table = array_map(fn (array $row): array => array_map(fn (array $c): string => is_scalar($row['cells'][$c['key']] ?? null) ? (string) $row['cells'][$c['key']] : '', $columns), $rows);
        if ($format === 'xlsx') {
            $body = XlsxWriter::workbook(mb_substr($title, 0, 31), $header, $table);
            $type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        } else {
            $stream = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Cannot open a temporary stream for the export.');
            foreach ([$header, ...$table] as $line) {
                fputcsv($stream, $line, escape: '');
            }
            rewind($stream);
            $body = (string) stream_get_contents($stream);
            fclose($stream);
            $type = 'text/csv; charset=UTF-8';
        }

        return response()->streamDownload(function () use ($body): void {
            echo $body;
        }, "{$name}.{$format}", ['Content-Type' => $type]);
    }

    /** @return array{title: string, filter: string, filters: array{from: string, to: string, as_of: string, by: string}, columns: list<array{key: string, label: string, align: string}>, rows: list<array{cells: array<string, mixed>, link: string|null}>, totals: array<string, string>, summaries: list<array<string, mixed>>} */
    private function registerTable(Request $request, FixedAssetRegisterQuery $register, FixedAssetReconciliation $reconciliation, FiscalPeriodQuery $periods): array
    {
        $entity = PageSupport::entity();
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);
        $value = $request->query('as_of');
        $asOf = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::parse($value) : app(BusinessClock::class)->today();
        $rows = $register->register($entity['id'], $asOf);
        $summary = fn (string $title, string $label, string $key): array => ['title' => $title, 'columns' => [['key' => 'group', 'label' => $label], ['key' => 'assets', 'label' => 'Assets'],
            ['key' => 'cost', 'label' => 'Cost'], ['key' => 'accumulated', 'label' => 'Accumulated depreciation'], ['key' => 'nbv', 'label' => 'Net book value']],
            'rows' => array_map(fn (array $g): array => ['cells' => ['group' => $g['group'], 'assets' => $g['assets'], 'cost' => $money($g['cost_minor']), 'accumulated' => $money($g['accumulated_minor']),
                'nbv' => $money($g['nbv_minor'])], 'link' => null], FixedAssetRegisterQuery::totalsBy($rows, $key))];
        $period = $periods->containing($entity['id'], $asOf);
        $book = $period === null ? (string) DB::table('books')->where('is_primary', true)->value('id') : $period->bookId;
        $reconciled = ['title' => 'Reconciliation to the ledger', 'columns' => [['key' => 'account', 'label' => 'Account'], ['key' => 'register', 'label' => 'Register'], ['key' => 'gl', 'label' => 'Ledger'], ['key' => 'variance', 'label' => 'Difference']],
            'rows' => array_map(fn (array $l): array => ['cells' => ['account' => "{$l['code']} {$l['name']}", 'register' => $money($l['register_minor']), 'gl' => $money($l['gl_minor']), 'variance' => $money($l['variance_minor'])],
                'link' => "/reports/account-activity?account_id={$l['account_id']}&to={$asOf->toDateString()}"], $reconciliation->lines($entity['id'], $book, $asOf))];

        return ['title' => 'Fixed asset register', 'filter' => 'as_of', 'filters' => ['from' => $asOf->startOfMonth()->toDateString(), 'to' => $asOf->toDateString(), 'as_of' => $asOf->toDateString(), 'by' => 'class'],
            'columns' => array_map(fn (array $c): array => ['key' => $c[0], 'label' => $c[1], 'align' => $c[2] ?? 'left'], [['number', 'Asset'], ['description', 'Description'], ['class', 'Class'], ['branch', 'Branch'],
                ['location', 'Location'], ['acquired_on', 'Acquired'], ['cost', 'Cost', 'right'], ['accumulated', 'Accumulated depreciation', 'right'], ['nbv', 'Net book value', 'right']]),
            'rows' => array_map(fn (array $r): array => ['cells' => ['number' => $r['number'], 'description' => $r['description'], 'class' => $r['class_name'], 'branch' => $r['branch_code'], 'location' => $r['location'],
                'acquired_on' => $r['acquired_on'], 'cost' => $money($r['cost_minor']), 'accumulated' => $money($r['accumulated_minor']), 'nbv' => $money($r['nbv_minor'])], 'link' => "/fixed-assets/{$r['id']}"], $rows),
            'totals' => ['cost' => $money(array_sum(array_column($rows, 'cost_minor'))), 'accumulated_depreciation' => $money(array_sum(array_column($rows, 'accumulated_minor'))),
                'net_book_value' => $money(array_sum(array_column($rows, 'nbv_minor')))],
            'summaries' => [$summary('Totals by class', 'Class', 'class_name'), $summary('Totals by branch', 'Branch', 'branch_code'), $reconciled]];
    }

    /**
     * @param list<string> $permissions
     * @return list<array{id: string, label: string}>
     */
    private function branches(string $actor, string $entityId, array $permissions): array
    {
        return array_values($this->permissions->reach($actor, $permissions)->constrain(DB::table('branches'), 'entity_id', 'id')->where('entity_id', $entityId)->where('status', 'active')->orderBy('code')
            ->get(['id', 'code', 'name'])->map(fn (object $b): array => ['id' => (string) $b->id, 'label' => "{$b->code} · {$b->name}"])->all());
    }

    /** @return list<array{id: string, label: string}> */
    public static function bankAccounts(string $entityId): array
    {
        return array_values(DB::table('bank_accounts')->where('entity_id', $entityId)->where('status', 'active')->orderBy('bank_name')->get(['id', 'bank_name', 'account_no_masked'])
            ->map(fn (object $b): array => ['id' => (string) $b->id, 'label' => "{$b->bank_name} {$b->account_no_masked}"])->all());
    }
}
