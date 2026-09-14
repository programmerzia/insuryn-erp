<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reinsurance\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Insurance\Reinsurance\Application\CessionEngine;
use App\Modules\Insurance\Reinsurance\Application\FacultativeService;
use App\Modules\Insurance\Reinsurance\Application\ReinsurerStatements;
use App\Modules\Insurance\Reinsurance\Application\TreatyService;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Reinsurance screens: treaties and reinsurers, the cessions queue, facultative placement from the policy page, and reinsurer statements. */
final class ReinsurancePageController
{
    public const AREA = ['ri.view', 'ri.manage_treaties', 'ri.place_facultative'];

    private const KINDS = ['sbc' => 'SBC compulsory', 'quota_share' => 'Quota share', 'surplus' => 'Surplus', 'facultative' => 'Facultative'];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly TreatyService $treaties,
        private readonly FacultativeService $facultative,
        private readonly ReinsurerStatements $statements,
    ) {}

    public function treaties(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $entity = PageSupport::entity();
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);
        $participants = DB::table('ri_treaty_participants as tp')->join('reinsurers as r', 'r.id', '=', 'tp.reinsurer_id')->orderByDesc('tp.share_bp')
            ->get(['tp.treaty_id', 'r.code', 'tp.share_bp'])->groupBy('treaty_id');

        return Inertia::render('reinsurance/treaties/Index', [
            'currency' => $entity['currency'],
            'canManage' => $this->permissions->has($actor, TreatyService::MANAGE),
            'treaties' => DB::table('ri_treaties as t')->leftJoin('product_classes as c', 'c.code', '=', 't.class_code')->where('t.entity_id', $entity['id'])
                ->orderByDesc('t.underwriting_year')->orderBy('t.class_code')->get(['t.*', 'c.name_en as class_name'])
                ->map(fn (object $t): array => ['id' => (string) $t->id, 'code' => (string) $t->code, 'name' => (string) $t->name, 'class' => (string) ($t->class_name ?? $t->class_code),
                    'year' => (int) $t->underwriting_year, 'period' => "{$t->period_from} to {$t->period_to}", 'type' => (string) $t->type, 'status' => (string) $t->status,
                    'terms' => $t->type === 'quota_share' ? 'Cedes '.PageSupport::percent((int) $t->cession_bp).'%' : 'Retention '.$money((int) $t->retention_minor)." × {$t->lines} lines",
                    'commission' => PageSupport::percent((int) $t->commission_bp), 'sbc_share' => PageSupport::percent((int) $t->sbc_share_bp),
                    'participants' => ($participants[$t->id] ?? collect())->map(fn (object $p): string => "{$p->code} ".PageSupport::percent((int) $p->share_bp).'%')->implode(', ')])->values()->all(),
            'reinsurers' => $this->reinsurerRows(),
        ]);
    }

    public function createTreaty(Request $request): Response
    {
        $this->permissions->authorize(PageSupport::actor($request), TreatyService::MANAGE);

        return $this->editor(null);
    }

    public function editTreaty(Request $request, string $treaty): Response
    {
        $this->permissions->authorize(PageSupport::actor($request), TreatyService::MANAGE);

        return $this->editor($treaty);
    }

    public function storeTreaty(Request $request): RedirectResponse
    {
        [$terms, $participants] = $this->treatyInput($request);
        $this->treaties->save(null, $terms, $participants, PageSupport::actor($request));

        return redirect('/reinsurance/treaties')->with('status', "Treaty {$terms['code']} saved.");
    }

    public function updateTreaty(Request $request, string $treaty): RedirectResponse
    {
        [$terms, $participants] = $this->treatyInput($request);
        $this->treaties->save($treaty, $terms, $participants, PageSupport::actor($request));

        return redirect('/reinsurance/treaties')->with('status', "Treaty {$terms['code']} saved.");
    }

    public function storeReinsurer(Request $request): RedirectResponse
    {
        /** @var array{name: string, code: string, rating: string|null, rating_agency: string|null, country: string, is_state_reinsurer: bool|null} $data */
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9-]+$/'], 'rating' => ['nullable', 'string', 'max:16'],
            'rating_agency' => ['nullable', 'string', 'max:32'], 'country' => ['required', 'string', 'size:2'], 'is_state_reinsurer' => ['nullable', 'boolean']]);
        $this->treaties->registerReinsurer($data['name'], $data['code'], $data['rating'] ?? null, $data['rating_agency'] ?? null, $data['country'], (bool) ($data['is_state_reinsurer'] ?? false),
            PageSupport::actor($request));

        return redirect('/reinsurance/treaties')->with('status', "Reinsurer {$data['name']} added.");
    }

    public function cessions(Request $request): Response
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);
        $entity = PageSupport::entity();
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);

        return Inertia::render('reinsurance/cessions/Index', [
            'currency' => $entity['currency'],
            'cessions' => DB::table('ri_cessions as c')->join('reinsurers as r', 'r.id', '=', 'c.reinsurer_id')->join('parties as rp', 'rp.id', '=', 'r.party_id')
                ->join('policies as p', 'p.id', '=', 'c.policy_id')->join('parties as h', 'h.id', '=', 'p.policyholder_party_id')->leftJoin('ri_treaties as t', 't.id', '=', 'c.treaty_id')
                ->where('c.entity_id', $entity['id'])->orderByDesc('c.accounting_date')->orderByDesc('c.created_at')->limit(PageSupport::LIST_PAGE_SIZE)
                ->get(['c.id', 'c.kind', 'c.movement', 'c.accounting_date', 'c.share_bp', 'c.ceded_sum_insured_minor', 'c.premium_minor', 'c.commission_minor', 'p.id as policy_id',
                    'p.number as policy_number', 'h.display_name as insured', 'r.code as reinsurer_code', 'rp.display_name as reinsurer', 't.code as treaty_code'])
                ->map(fn (object $c): array => ['id' => (string) $c->id, 'kind' => self::KINDS[(string) $c->kind] ?? (string) $c->kind, 'movement' => ucfirst((string) $c->movement),
                    'date' => (string) $c->accounting_date, 'share' => PageSupport::percent((int) $c->share_bp), 'ceded_sum_insured' => $money((int) $c->ceded_sum_insured_minor),
                    'premium' => $money((int) $c->premium_minor), 'commission' => $money((int) $c->commission_minor), 'policy_id' => (string) $c->policy_id,
                    'policy_number' => (string) $c->policy_number, 'insured' => (string) $c->insured, 'reinsurer' => "{$c->reinsurer_code} · {$c->reinsurer}", 'treaty' => $c->treaty_code])->values()->all(),
            // Policies with risk above treaty capacity not yet placed facultatively.
            'aboveCapacity' => DB::table('ri_policy_positions as pos')->join('policies as p', 'p.id', '=', 'pos.policy_id')->join('parties as h', 'h.id', '=', 'p.policyholder_party_id')
                ->where('p.entity_id', $entity['id'])->where('pos.above_capacity_minor', '>', 0)->whereIn('p.status', ['issued', 'active'])->orderByDesc('pos.above_capacity_minor')
                ->get(['p.id', 'p.number', 'h.display_name', 'pos.sum_insured_minor', 'pos.above_capacity_minor'])
                ->map(fn (object $p): array => ['policy_id' => (string) $p->id, 'policy_number' => (string) $p->number, 'insured' => (string) $p->display_name,
                    'sum_insured' => $money((int) $p->sum_insured_minor), 'above_capacity' => $money((int) $p->above_capacity_minor)])->values()->all(),
        ]);
    }

    public function statements(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $entity = PageSupport::entity();
        $money = fn (int $minor): string => PageSupport::money($minor, $entity['currency']);
        $today = app(BusinessClock::class)->today();

        return Inertia::render('reinsurance/statements/Index', [
            'currency' => $entity['currency'],
            'canPrepare' => $this->permissions->has($actor, TreatyService::MANAGE),
            'defaults' => ['year' => $today->year, 'quarter' => intdiv($today->month - 1, 3) + 1],
            'reinsurers' => array_map(fn (array $r): array => ['value' => $r['id'], 'label' => "{$r['code']} · {$r['name']}"], $this->reinsurerRows()),
            'statements' => DB::table('ri_statements as s')->join('reinsurers as r', 'r.id', '=', 's.reinsurer_id')->join('parties as rp', 'rp.id', '=', 'r.party_id')
                ->where('s.entity_id', $entity['id'])->orderByDesc('s.year')->orderByDesc('s.quarter')->orderBy('r.code')
                ->get(['s.*', 'r.code as reinsurer_code', 'rp.display_name as reinsurer'])
                ->map(fn (object $s): array => ['id' => (string) $s->id, 'number' => (string) $s->number, 'reinsurer' => "{$s->reinsurer_code} · {$s->reinsurer}", 'quarter' => "Q{$s->quarter} {$s->year}",
                    'period_from' => (string) $s->period_from, 'period_to' => (string) $s->period_to, 'opening' => $money((int) $s->opening_balance_minor), 'premium' => $money((int) $s->premium_minor),
                    'commission' => $money((int) $s->commission_minor), 'claims_recoverable' => $money((int) $s->claims_recoverable_minor), 'closing' => $money((int) $s->closing_balance_minor),
                    'outstanding_claims_share' => $money((int) $s->outstanding_claims_share_minor), 'due_to_reinsurer' => (int) $s->closing_balance_minor >= 0,
                    'bordereau' => '/reports/ri-premium-bordereau?'.http_build_query(['from' => $s->period_from, 'to' => $s->period_to])])->values()->all(),
        ]);
    }

    public function prepareStatement(Request $request): RedirectResponse
    {
        /** @var array{reinsurer_id: string, year: int|string, quarter: int|string} $data */
        $data = $request->validate(['reinsurer_id' => ['required', 'uuid'], 'year' => ['required', 'integer', 'between:2000,2100'], 'quarter' => ['required', 'integer', 'between:1,4']]);
        $this->statements->prepare(PageSupport::entity()['id'], $data['reinsurer_id'], (int) $data['year'], (int) $data['quarter'], PageSupport::actor($request));

        return redirect('/reinsurance/statements')->with('status', "Statement for Q{$data['quarter']} {$data['year']} prepared.");
    }

    public function placeFacultative(Request $request, string $policy): RedirectResponse
    {
        $model = Policy::query()->findOrFail($policy);
        /** @var array{reinsurer_id: string, share_percent: string, ceded_sum_insured: string|null, premium: string, commission_percent: string, slip_reference: string|null, placed_on: string} $data */
        $data = $request->validate(['reinsurer_id' => ['required', 'uuid'], 'share_percent' => ['required', 'string'], 'ceded_sum_insured' => ['nullable', 'string'], 'premium' => ['required', 'string'],
            'commission_percent' => ['required', 'string'], 'slip_reference' => ['nullable', 'string', 'max:64'], 'placed_on' => ['required', 'date_format:Y-m-d']]);
        $cededSi = ($data['ceded_sum_insured'] ?? '') === '' ? null : PageSupport::minor('ceded_sum_insured', $data['ceded_sum_insured'], $model->currency);
        $this->facultative->place($model->id, $data['reinsurer_id'], PageSupport::basisPoints('share_percent', $data['share_percent']), $cededSi,
            PageSupport::minor('premium', $data['premium'], $model->currency), PageSupport::basisPoints('commission_percent', $data['commission_percent']), $data['slip_reference'] ?? null,
            CarbonImmutable::parse($data['placed_on']), PageSupport::actor($request));

        return redirect("/policies/{$model->id}?tab=reinsurance")->with('status', 'Facultative placement recorded and ceded.');
    }

    /**
     * The policy page's Reinsurance tab (null for someone without a reinsurance permission on the policy's branch).
     *
     * @return array<string, mixed>|null
     */
    public static function policyTab(string $actor, Policy $policy): ?array
    {
        $permissions = app(PermissionChecker::class);
        $scope = AuthorizationScope::branch($policy->entity_id, $policy->branch_id);
        if (! array_any(self::AREA, fn (string $p): bool => $permissions->has($actor, $p, $scope))) {
            return null;
        }
        $money = fn (int $minor): string => PageSupport::money($minor, $policy->currency);
        $position = DB::table('ri_policy_positions as pos')->leftJoin('ri_treaties as t', 't.id', '=', 'pos.treaty_id')->where('pos.policy_id', $policy->id)
            ->first(['pos.*', 't.code as treaty_code', 't.name as treaty_name', 't.type as treaty_type']);
        $byReinsurer = DB::table('ri_cessions as c')->join('reinsurers as r', 'r.id', '=', 'c.reinsurer_id')->join('parties as rp', 'rp.id', '=', 'r.party_id')->where('c.policy_id', $policy->id)
            ->groupBy('c.kind', 'r.code', 'rp.display_name')->orderBy('c.kind')->orderBy('r.code')
            ->selectRaw('c.kind, r.code, rp.display_name as name, sum(c.ceded_sum_insured_minor) as si, sum(c.premium_minor) as premium, sum(c.commission_minor) as commission')->get();
        $sumInsured = CessionEngine::sumInsuredOf($policy);

        return [
            'position' => $position === null ? null : ['treaty' => $position->treaty_code === null ? null : "{$position->treaty_code} · {$position->treaty_name}",
                'treaty_type' => $position->treaty_type === null ? null : (self::KINDS[(string) $position->treaty_type] ?? null),
                'sum_insured' => $money((int) $position->sum_insured_minor), 'net_premium' => $money((int) $position->net_premium_minor), 'sbc' => $money((int) $position->sbc_sum_insured_minor),
                'treaty_ceded' => $money((int) $position->treaty_sum_insured_minor), 'retained' => $money((int) $position->retained_sum_insured_minor),
                'above_capacity' => $money((int) $position->above_capacity_minor), 'above_capacity_minor' => (int) $position->above_capacity_minor, 'note' => $position->note],
            'shares' => $byReinsurer->map(fn (object $r): array => ['basis' => self::KINDS[(string) $r->kind] ?? (string) $r->kind, 'reinsurer' => "{$r->code} · {$r->name}",
                'share' => PageSupport::percent($sumInsured > 0 ? \App\Modules\Insurance\Reinsurance\Domain\RiMath::shareBp((int) $r->si, $sumInsured) : \App\Modules\Insurance\Reinsurance\Domain\RiMath::shareBp((int) $r->premium, $policy->net_premium_minor)),
                'ceded_sum_insured' => $money((int) $r->si), 'premium' => $money((int) $r->premium), 'commission' => $money((int) $r->commission)])->values()->all(),
            'movements' => DB::table('ri_cessions as c')->join('reinsurers as r', 'r.id', '=', 'c.reinsurer_id')->where('c.policy_id', $policy->id)->orderBy('c.created_at')
                ->get(['c.id', 'c.kind', 'c.movement', 'c.accounting_date', 'r.code', 'c.ceded_sum_insured_minor', 'c.premium_minor', 'c.commission_minor'])
                ->map(fn (object $c): array => ['id' => (string) $c->id, 'date' => (string) $c->accounting_date, 'movement' => ucfirst((string) $c->movement), 'basis' => self::KINDS[(string) $c->kind] ?? (string) $c->kind,
                    'reinsurer' => (string) $c->code, 'ceded_sum_insured' => $money((int) $c->ceded_sum_insured_minor), 'premium' => $money((int) $c->premium_minor), 'commission' => $money((int) $c->commission_minor)])->values()->all(),
            'placements' => DB::table('ri_facultative_placements as f')->join('reinsurers as r', 'r.id', '=', 'f.reinsurer_id')->where('f.policy_id', $policy->id)->orderBy('f.placed_on')
                ->get(['f.id', 'r.code', 'f.slip_reference', 'f.share_bp', 'f.ceded_sum_insured_minor', 'f.premium_minor', 'f.commission_minor', 'f.placed_on'])
                ->map(fn (object $f): array => ['id' => (string) $f->id, 'reinsurer' => (string) $f->code, 'slip_reference' => $f->slip_reference, 'share' => PageSupport::percent((int) $f->share_bp),
                    'ceded_sum_insured' => $money((int) $f->ceded_sum_insured_minor), 'premium' => $money((int) $f->premium_minor), 'commission' => $money((int) $f->commission_minor), 'placed_on' => (string) $f->placed_on])->values()->all(),
            'claims' => DB::table('ri_claim_shares as s')->join('claims as c', 'c.id', '=', 's.claim_id')->join('reinsurers as r', 'r.id', '=', 's.reinsurer_id')->where('s.policy_id', $policy->id)
                ->groupBy('c.id', 'c.number', 'r.code')->orderBy('c.number')->orderBy('r.code')
                ->selectRaw("c.id, c.number, r.code, sum(case when s.kind = 'reserve' then s.amount_minor else -s.from_reserve_minor end) as outstanding, sum(case when s.kind = 'recoverable' then s.amount_minor else 0 end) as recoverable")
                ->get()->map(fn (object $s): array => ['claim_id' => (string) $s->id, 'claim_number' => (string) $s->number, 'reinsurer' => (string) $s->code,
                    'outstanding' => $money((int) $s->outstanding), 'recoverable' => $money((int) $s->recoverable)])->values()->all(),
            'canPlace' => $permissions->has($actor, FacultativeService::PERMISSION, $scope) && in_array($policy->status->value, ['issued', 'active'], true),
            'reinsurers' => DB::table('reinsurers as r')->join('parties as p', 'p.id', '=', 'r.party_id')->where('r.status', 'active')->where('r.is_state_reinsurer', false)->orderBy('r.code')
                ->get(['r.id', 'r.code', 'p.display_name'])->map(fn (object $r): array => ['value' => (string) $r->id, 'label' => "{$r->code} · {$r->display_name}"])->values()->all(),
            'today' => app(BusinessClock::class)->today()->toDateString(),
        ];
    }

    private function editor(?string $treatyId): Response
    {
        $entity = PageSupport::entity();
        $treaty = $treatyId === null ? null : DB::table('ri_treaties')->where('id', $treatyId)->first();
        abort_if($treatyId !== null && $treaty === null, 404);
        $today = app(BusinessClock::class)->today();

        return Inertia::render('reinsurance/treaties/Edit', [
            'currency' => $entity['currency'],
            'treaty' => $treaty === null ? null : ['id' => (string) $treaty->id, 'code' => (string) $treaty->code, 'name' => (string) $treaty->name, 'class_code' => (string) $treaty->class_code,
                'underwriting_year' => (int) $treaty->underwriting_year, 'period_from' => (string) $treaty->period_from, 'period_to' => (string) $treaty->period_to, 'type' => (string) $treaty->type,
                'cession_percent' => $treaty->cession_bp === null ? '' : PageSupport::percent((int) $treaty->cession_bp),
                'retention' => $treaty->retention_minor === null ? '' : PageSupport::money((int) $treaty->retention_minor, $entity['currency']), 'lines' => $treaty->lines === null ? '' : (string) $treaty->lines,
                'commission_percent' => PageSupport::percent((int) $treaty->commission_bp), 'sbc_share_percent' => PageSupport::percent((int) $treaty->sbc_share_bp), 'status' => (string) $treaty->status,
                'participants' => DB::table('ri_treaty_participants')->where('treaty_id', $treaty->id)->orderByDesc('share_bp')->get(['reinsurer_id', 'share_bp'])
                    ->map(fn (object $p): array => ['reinsurer_id' => (string) $p->reinsurer_id, 'share_percent' => PageSupport::percent((int) $p->share_bp)])->values()->all()],
            'defaults' => ['underwriting_year' => $today->year, 'period_from' => $today->startOfYear()->toDateString(), 'period_to' => $today->endOfYear()->toDateString(),
                'sbc_share_percent' => PageSupport::percent((int) config('erp.reinsurance.sbc_share_bp', 5000))],
            'classes' => DB::table('product_classes')->where('status', 'active')->where('insurance_class', 'non_life')->orderBy('sort_order')->get(['code', 'name_en'])
                ->map(fn (object $c): array => ['value' => (string) $c->code, 'label' => (string) $c->name_en])->values()->all(),
            'reinsurers' => array_values(array_map(fn (array $r): array => ['value' => $r['id'], 'label' => "{$r['code']} · {$r['name']}"],
                array_filter($this->reinsurerRows(), fn (array $r): bool => ! $r['is_state_reinsurer'] && $r['status'] === 'active'))),
        ]);
    }

    /** @return array{0: array{entity_id: string, code: string, name: string, class_code: string, underwriting_year: int, period_from: string, period_to: string, type: string, cession_bp: int|null, retention_minor: int|null, lines: int|null, commission_bp: int, sbc_share_bp: int, currency: string, status: string}, 1: array<string, int>} */
    private function treatyInput(Request $request): array
    {
        /** @var array{code: string, name: string, class_code: string, underwriting_year: int|string, period_from: string, period_to: string, type: string, cession_percent: string|null, retention: string|null, lines: int|string|null, commission_percent: string, sbc_share_percent: string, status: string, participants: list<array{reinsurer_id: string, share_percent: string}>} $data */
        $data = $request->validate(['code' => ['required', 'string', 'max:32'], 'name' => ['required', 'string', 'max:255'], 'class_code' => ['required', 'string', 'exists:product_classes,code'],
            'underwriting_year' => ['required', 'integer', 'between:2000,2100'], 'period_from' => ['required', 'date_format:Y-m-d'], 'period_to' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(['quota_share', 'surplus'])], 'cession_percent' => ['nullable', 'string'], 'retention' => ['nullable', 'string'], 'lines' => ['nullable', 'integer', 'between:1,50'],
            'commission_percent' => ['required', 'string'], 'sbc_share_percent' => ['required', 'string'], 'status' => ['required', Rule::in(['active', 'inactive'])],
            'participants' => ['required', 'array', 'min:1'], 'participants.*.reinsurer_id' => ['required', 'uuid'], 'participants.*.share_percent' => ['required', 'string']]);
        $entity = PageSupport::entity();
        $participants = [];
        foreach ($data['participants'] as $i => $p) {
            $participants[$p['reinsurer_id']] = ($participants[$p['reinsurer_id']] ?? 0) + PageSupport::basisPoints("participants.{$i}.share_percent", $p['share_percent']);
        }
        $quota = $data['type'] === 'quota_share';

        return [['entity_id' => $entity['id'], 'code' => $data['code'], 'name' => $data['name'], 'class_code' => $data['class_code'], 'underwriting_year' => (int) $data['underwriting_year'],
            'period_from' => $data['period_from'], 'period_to' => $data['period_to'], 'type' => $data['type'],
            'cession_bp' => $quota ? PageSupport::basisPoints('cession_percent', $data['cession_percent'] ?? '') : null,
            'retention_minor' => $quota ? null : PageSupport::minor('retention', $data['retention'] ?? '', $entity['currency']),
            'lines' => $quota ? null : (int) ($data['lines'] ?? 0), 'commission_bp' => PageSupport::basisPoints('commission_percent', $data['commission_percent']),
            'sbc_share_bp' => PageSupport::basisPoints('sbc_share_percent', $data['sbc_share_percent']), 'currency' => $entity['currency'], 'status' => $data['status']], $participants];
    }

    /** @return list<array{id: string, code: string, name: string, rating: string|null, rating_agency: string|null, country: string, is_state_reinsurer: bool, status: string}> */
    private function reinsurerRows(): array
    {
        return array_values(DB::table('reinsurers as r')->join('parties as p', 'p.id', '=', 'r.party_id')->orderByDesc('r.is_state_reinsurer')->orderBy('r.code')
            ->get(['r.id', 'r.code', 'p.display_name', 'r.rating', 'r.rating_agency', 'r.country', 'r.is_state_reinsurer', 'r.status'])
            ->map(fn (object $r): array => ['id' => (string) $r->id, 'code' => (string) $r->code, 'name' => (string) $r->display_name, 'rating' => $r->rating === null ? null : (string) $r->rating,
                'rating_agency' => $r->rating_agency === null ? null : (string) $r->rating_agency, 'country' => (string) $r->country, 'is_state_reinsurer' => (bool) $r->is_state_reinsurer,
                'status' => (string) $r->status])->all());
    }
}
