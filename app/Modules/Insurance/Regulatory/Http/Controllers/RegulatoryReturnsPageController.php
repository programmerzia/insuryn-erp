<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Regulatory\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Regulatory\Application\RegulatoryPeriod;
use App\Modules\Insurance\Regulatory\Application\Returns\RegulatoryReturnService;
use App\Modules\Insurance\Regulatory\Application\Returns\ReturnExport;
use App\Modules\Insurance\Regulatory\Application\Returns\ReturnFormBuilder;
use App\Modules\Insurance\Regulatory\Application\SolvencySnapshot;
use App\Modules\Platform\Authorization\PermissionChecker;
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
 * Market gap G5 screens: the Regulatory dashboard (solvency snapshot, this quarter's returns and provisions) and Regulatory → Returns — pick a period, see its
 * forms with their status, preview a form, download the set or one form as XLSX (a sheet per form) or PDF, generate, mark reviewed and mark filed.
 * reports.regulatory views, exports, generates and reviews; regulatory.file files.
 */
final class RegulatoryReturnsPageController
{
    public const AREA = ['reports.regulatory', 'regulatory.file', 'provisions.run', 'provisions.approve'];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly RegulatoryReturnService $returns,
        private readonly BusinessClock $clock,
    ) {}

    public function dashboard(Request $request, SolvencySnapshot $solvency): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $entity = PageSupport::entity();
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);
        $today = $this->clock->today($entity['id']);
        // The quarter whose returns were worked on last (generated), otherwise the current quarter.
        $latest = DB::table('regulatory_returns')->where('entity_id', $entity['id'])->where('period_key', 'like', '%-Q%')->orderByDesc('period_end')->value('period_key');
        $quarter = $latest !== null ? RegulatoryPeriod::fromKey((string) $latest) : RegulatoryPeriod::quarterOf($today);
        $snapshot = $solvency->at($entity['id'], $today);
        $forms = $this->returns->forms($entity['id'], $quarter, withLiveFigures: false);
        $run = DB::table('technical_provision_runs')->where('entity_id', $entity['id'])->orderByDesc('quarter_end')->first(['number', 'quarter_key', 'status', 'total_ibnr_minor']);

        return Inertia::render('regulatory/Dashboard', [
            'currency' => $entity['currency'],
            'quarter' => ['key' => $quarter->key, 'label' => $quarter->label()],
            'solvency' => ['as_of' => $snapshot['as_of'], 'available' => $money($snapshot['available_minor']), 'required' => $money($snapshot['required_minor']),
                'minimum_capital' => $money($snapshot['minimum_capital_minor']), 'premium_basis' => $money($snapshot['premium_basis_minor']), 'premium_component' => $money($snapshot['premium_component_minor']),
                'claims_basis' => $money($snapshot['claims_basis_minor']), 'claims_component' => $money($snapshot['claims_component_minor']),
                'ratio' => intdiv($snapshot['ratio_bp'], 100).'.'.str_pad((string) (abs($snapshot['ratio_bp']) % 100), 2, '0', STR_PAD_LEFT).'%', 'meets' => $snapshot['meets'],
                'premium_factor' => PageSupport::percent((int) config('erp.regulatory.solvency.premium_factor_bp')).'%', 'claims_factor' => PageSupport::percent((int) config('erp.regulatory.solvency.claims_factor_bp')).'%'],
            'returns' => array_map(fn (array $f): array => ['code' => $f['code'], 'title' => $f['title'], 'status' => $f['status'], 'filed_on' => $f['filed_on'], 'filing_reference' => $f['filing_reference']], $forms),
            'provisions' => $run === null ? null : ['number' => $run->number, 'quarter' => (string) $run->quarter_key, 'status' => (string) $run->status, 'total_ibnr' => $money((int) $run->total_ibnr_minor)],
        ]);
    }

    public function index(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $entity = PageSupport::entity();
        $period = $this->period($request);
        $forms = $this->returns->forms($entity['id'], $period);
        $selected = in_array($request->query('form'), ReturnFormBuilder::codes(), true) ? (string) $request->query('form') : ($forms[0]['code'] ?? null);
        $users = DB::table('users')->whereIn('id', DB::table('regulatory_returns')->where('entity_id', $entity['id'])->where('period_key', $period->key)
            ->selectRaw('unnest(array_remove(array[generated_by, reviewed_by, filed_by], null))'))->pluck('name', 'id');
        $rows = DB::table('regulatory_returns')->where('entity_id', $entity['id'])->where('period_key', $period->key)->get()->keyBy('form_code');

        return Inertia::render('regulatory/Returns', [
            'period' => ['key' => $period->key, 'label' => $period->label(), 'start' => $period->start->toDateString(), 'end' => $period->end->toDateString()],
            'periods' => array_map(fn (string $key): array => ['value' => $key, 'label' => RegulatoryPeriod::fromKey($key)->label()], RegulatoryPeriod::choices($this->clock->today($entity['id']), including: $period->key)),
            'forms' => array_map(function (array $f) use ($rows, $users): array {
                $row = $rows[$f['code']] ?? null;

                return ['id' => $f['id'], 'code' => $f['code'], 'title' => $f['title'], 'status' => $f['status'], 'filed_on' => $f['filed_on'], 'filing_reference' => $f['filing_reference'],
                    'generated' => $row === null ? null : trim(($users[$row->generated_by] ?? '').' · '.CarbonImmutable::parse((string) $row->generated_at)->format('j M Y H:i'), ' ·'),
                    'reviewed' => $row?->reviewed_by === null ? null : trim(($users[$row->reviewed_by] ?? '').' · '.CarbonImmutable::parse((string) $row->reviewed_at)->format('j M Y H:i'), ' ·'),
                    'filed_by' => $row?->filed_by === null ? null : (string) ($users[$row->filed_by] ?? '')];
            }, $forms),
            'preview' => $selected === null ? null : self::present(array_values(array_filter($forms, fn (array $f): bool => $f['code'] === $selected))[0]['form']),
            'selected' => $selected,
            'can' => ['generate' => $this->permissions->has($actor, 'reports.regulatory'), 'review' => $this->permissions->has($actor, 'reports.regulatory') || $this->permissions->has($actor, 'regulatory.file'),
                'file' => $this->permissions->has($actor, 'regulatory.file'), 'export' => $this->permissions->has($actor, 'reports.regulatory')],
            'today' => $this->clock->today($entity['id'])->toDateString(),
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $period = $this->period($request);
        $count = $this->returns->generate(PageSupport::entity()['id'], $period, PageSupport::actor($request));

        return back()->with('status', $count === 0 ? "Every return for {$period->label()} is already filed; nothing was regenerated." : "{$count} returns generated for {$period->label()} as drafts.");
    }

    public function review(Request $request, string $return): RedirectResponse
    {
        $this->returns->review($return, PageSupport::actor($request));

        return back()->with('status', 'Return marked reviewed. It can now be filed.');
    }

    public function file(Request $request, string $return): RedirectResponse
    {
        /** @var array{filed_on: string, reference: string} $data */
        $data = $request->validate(['filed_on' => ['required', 'date_format:Y-m-d'], 'reference' => ['required', 'string', 'max:100']]);
        $this->returns->file($return, CarbonImmutable::parse($data['filed_on']), $data['reference'], PageSupport::actor($request));

        return back()->with('status', "Return marked filed with reference {$data['reference']}.");
    }

    public function export(Request $request, ReturnExport $export): StreamedResponse
    {
        $this->permissions->authorize(PageSupport::actor($request), 'reports.regulatory');
        /** @var array{format: string, form?: string|null} $data */
        $data = $request->validate(['format' => ['required', Rule::in(['xlsx', 'pdf'])], 'form' => ['nullable', Rule::in(ReturnFormBuilder::codes())]]);
        $entity = PageSupport::entity();
        $period = $this->period($request);
        $forms = $this->returns->forms($entity['id'], $period);
        if (($data['form'] ?? null) !== null) {
            $forms = array_values(array_filter($forms, fn (array $f): bool => $f['code'] === $data['form']));
        }
        [$body, $type] = $data['format'] === 'xlsx'
            ? [$export->xlsx($forms), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
            : [$export->pdf($forms), 'application/pdf'];
        $name = 'regulatory-returns-'.$period->key.(($data['form'] ?? null) !== null ? '-'.str_replace('_', '-', (string) $data['form']) : '').'.'.$data['format'];

        return response()->streamDownload(function () use ($body): void {
            echo $body;
        }, $name, ['Content-Type' => $type]);
    }

    private function period(Request $request): RegulatoryPeriod
    {
        $key = $request->input('period');

        return is_string($key) && $key !== '' ? RegulatoryPeriod::fromKey($key) : RegulatoryPeriod::quarterOf($this->clock->today(PageSupport::entity()['id']));
    }

    /**
     * A form with every cell as people read it.
     *
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private static function present(array $form): array
    {
        /** @var list<array{key: string, title: string, columns: list<array{key: string, label: string, kind: string}>, rows: list<array<string, mixed>>, totals: array<string, mixed>|null}> $sections */
        $sections = $form['sections'] ?? [];
        $currency = (string) ($form['currency'] ?? 'BDT');
        $cells = fn (array $row, array $columns): array => array_combine(array_column($columns, 'key'), array_map(fn (array $c): string => ReturnExport::cell($row[$c['key']] ?? null, $c['kind'], $currency), $columns));

        return ['title' => $form['title'] ?? '', 'period_label' => $form['period_label'] ?? '', 'entity_name' => $form['entity_name'] ?? '', 'currency' => $currency, 'notes' => $form['notes'] ?? [],
            'sections' => array_map(fn (array $s): array => ['key' => $s['key'], 'title' => $s['title'], 'columns' => array_map(fn (array $c): array => ['key' => $c['key'], 'label' => $c['label'], 'numeric' => $c['kind'] !== 'value' || is_int($s['rows'][0][$c['key']] ?? null)], $s['columns']),
                'rows' => array_map(fn (array $r): array => $cells($r, $s['columns']), $s['rows']), 'totals' => $s['totals'] === null ? null : $cells($s['totals'], $s['columns'])], $sections)];
    }
}
