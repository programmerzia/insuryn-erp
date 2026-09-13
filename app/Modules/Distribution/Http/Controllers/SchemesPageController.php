<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Distribution\Application\Compensation\CompensationRuleRequest;
use App\Modules\Distribution\Application\Compensation\CompensationSchemeDirectory;
use App\Modules\Distribution\Application\Compensation\CompensationSchemeService;
use App\Modules\Distribution\Application\Hierarchy\HierarchyService;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Distribution design note §6 "scheme & rule editor with validation against compliance profile" (slice D8). Rates are typed as percentages and
 * stored as basis points; every refusal from CompensationSchemeService comes back as the form error it names.
 */
final class SchemesPageController
{
    private const AREA = ['commission.manage_plans', 'commission.approve', 'reports.financial'];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly CompensationSchemeService $schemes,
        private readonly CompensationSchemeDirectory $directory,
    ) {}

    public function index(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $rules = DB::table('compensation_rules')->groupBy('scheme_id')->selectRaw('scheme_id, count(*) as n')->pluck('n', 'scheme_id');
        $products = DB::table('product_versions')->whereNotNull('compensation_scheme_id')->groupBy('compensation_scheme_id')->selectRaw('compensation_scheme_id, count(distinct product_id) as n')->pluck('n', 'compensation_scheme_id');

        return Inertia::render('distribution/schemes/Index', [
            'schemes' => DB::table('compensation_schemes')->orderBy('code')->get(['id', 'code', 'name', 'mode', 'effective_from', 'effective_to'])
                ->map(fn (object $s): array => [...(array) $s, 'rules' => (int) ($rules[$s->id] ?? 0), 'products' => (int) ($products[$s->id] ?? 0)])->values()->all(),
            'can' => ['manage' => $this->permissions->has($actor, 'commission.manage_plans')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var array{code: string, name: string, mode: string, effective_from: string} $data */
        $data = $request->validate(['code' => ['required', 'string', 'max:32'], 'name' => ['required', 'string', 'max:255'], 'mode' => ['required', Rule::in(['commission', 'salary_incentive', 'hybrid', 'none'])],
            'effective_from' => ['required', 'date_format:Y-m-d']]);
        $id = $this->schemes->createScheme($data['code'], $data['name'], $data['mode'], CarbonImmutable::parse($data['effective_from']), null, [], PageSupport::actor($request));

        return redirect("/distribution/schemes/{$id}")->with('status', "Scheme {$data['code']} created. Set its compliance profile before adding rules.");
    }

    public function show(Request $request, string $scheme): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $described = $this->directory->describe($scheme) ?? abort(404);
        /** @var array{allowed_producer_types?: list<string>|null, non_life_commission_allowed?: bool, caps?: list<array{product_id: string|null, policy_year_from: int, policy_year_to: int, max_total_bp: int}>} $profile */
        $profile = $described['compliance_profile'];
        $productCodes = DB::table('products')->pluck('code', 'id');
        $percent = fn (mixed $bp): ?string => $bp === null ? null : PageSupport::percent((int) $bp);

        return Inertia::render('distribution/schemes/Show', [
            'scheme' => ['id' => $described['id'], 'code' => $described['code'], 'name' => $described['name'], 'mode' => $described['mode'], 'effective_from' => $described['effective_from'],
                'effective_to' => $described['effective_to'], 'withholding' => $described['withholding_tax_type'] === null ? null : "{$described['withholding_tax_type']} ({$described['withholding_jurisdiction']})"],
            'profile' => ['allowed_producer_types' => $profile['allowed_producer_types'] ?? null, 'non_life_commission_allowed' => (bool) ($profile['non_life_commission_allowed'] ?? false),
                'caps' => array_map(fn (array $c): array => ['product_id' => $c['product_id'], 'policy_year_from' => $c['policy_year_from'], 'policy_year_to' => $c['policy_year_to'],
                    'max_total_percent' => PageSupport::percent($c['max_total_bp'])], $profile['caps'] ?? [])],
            'levels' => $described['levels'],
            'rules' => array_map(fn (array $r): array => ['id' => (string) $r['id'], 'product' => $r['product_id'] === null ? 'Every product' : (string) ($productCodes[$r['product_id']] ?? ''),
                'producer_type' => $r['producer_type'], 'level_code' => $r['level_code'], 'basis' => (string) $r['basis'], 'years' => $r['policy_year_from'] === $r['policy_year_to'] ? (string) $r['policy_year_from'] : "{$r['policy_year_from']}–{$r['policy_year_to']}",
                'rate_percent' => $percent($r['rate_bp']), 'override_rate_percent' => $percent($r['override_rate_bp']), 'cap_percent' => $percent($r['cap_bp']),
                'min_persistency_percent' => $percent($r['min_persistency_bp']), 'renewal_requires_valid_licence' => (bool) $r['renewal_requires_valid_licence'],
                'pays_after_termination' => (bool) $r['pays_after_termination'], 'effective_from' => (string) $r['effective_from'], 'effective_to' => $r['effective_to']], $described['rules']),
            'products' => DB::table('products')->orderBy('code')->get(['id', 'code', 'name', 'insurance_class'])->map(fn (object $p): array => (array) $p)->values()->all(),
            'can' => ['manage' => $this->permissions->has($actor, 'commission.manage_plans')],
        ]);
    }

    public function updateProfile(Request $request, string $scheme): RedirectResponse
    {
        /** @var array{allowed_producer_types?: list<string>|null, non_life_commission_allowed?: bool, caps?: list<array{product_id?: string|null, policy_year_from: int|string, policy_year_to: int|string, max_total_percent: string}>} $data */
        $data = $request->validate(['allowed_producer_types' => ['nullable', 'array'], 'allowed_producer_types.*' => [Rule::in(['agent', 'agency_org', 'bdo', 'broker', 'partner'])],
            'non_life_commission_allowed' => ['boolean'], 'caps' => ['array'], 'caps.*.product_id' => ['nullable', 'uuid'], 'caps.*.policy_year_from' => ['required', 'integer', 'min:1', 'max:99'],
            'caps.*.policy_year_to' => ['required', 'integer', 'min:1', 'max:99'], 'caps.*.max_total_percent' => ['required', 'string']]);
        $caps = [];
        foreach ($data['caps'] ?? [] as $i => $cap) {
            $caps[] = ['product_id' => ($cap['product_id'] ?? null) ?: null, 'policy_year_from' => (int) $cap['policy_year_from'], 'policy_year_to' => (int) $cap['policy_year_to'],
                'max_total_bp' => PageSupport::basisPoints("caps.{$i}.max_total_percent", $cap['max_total_percent'])];
        }
        $this->schemes->updateComplianceProfile($scheme, ['allowed_producer_types' => ($data['allowed_producer_types'] ?? []) === [] ? null : $data['allowed_producer_types'],
            'non_life_commission_allowed' => (bool) ($data['non_life_commission_allowed'] ?? false), 'caps' => $caps], PageSupport::actor($request));

        return back()->with('status', 'Compliance profile saved.');
    }

    public function defineLevels(Request $request, string $scheme, HierarchyService $hierarchy): RedirectResponse
    {
        /** @var array{levels: list<array{code: string, rank: int|string, label: string}>} $data */
        $data = $request->validate(['levels' => ['required', 'array', 'min:1'], 'levels.*.code' => ['required', 'string', 'max:32'], 'levels.*.rank' => ['required', 'integer', 'min:1'],
            'levels.*.label' => ['required', 'string', 'max:255']]);
        $hierarchy->defineLevels($scheme, array_map(fn (array $l): array => ['code' => (string) $l['code'], 'rank' => (int) $l['rank'], 'label' => (string) $l['label']], $data['levels']), PageSupport::actor($request));

        return back()->with('status', 'Levels saved.');
    }

    public function storeRule(Request $request, string $scheme): RedirectResponse
    {
        $data = $request->validate(['product_id' => ['nullable', 'uuid'], 'producer_type' => ['nullable', Rule::in(['agent', 'agency_org', 'bdo', 'broker', 'partner'])], 'level_code' => ['nullable', 'string', 'max:32'],
            'basis' => ['required', Rule::in(['premium_received', 'premium_written', 'net_premium'])], 'policy_year_from' => ['required', 'integer', 'min:1', 'max:99'],
            'policy_year_to' => ['required', 'integer', 'min:1', 'max:99'], 'rate_percent' => ['nullable', 'string'], 'override_rate_percent' => ['nullable', 'string'], 'cap_percent' => ['nullable', 'string'],
            'min_persistency_percent' => ['nullable', 'string'], 'renewal_requires_valid_licence' => ['boolean'], 'pays_after_termination' => ['boolean'], 'effective_from' => ['required', 'date_format:Y-m-d']]);
        $bp = fn (string $field): ?int => ($data[$field] ?? null) === null || $data[$field] === '' ? null : PageSupport::basisPoints($field, $data[$field]);
        $this->schemes->addRule($scheme, CompensationRuleRequest::fromArray([...$data, 'product_id' => ($data['product_id'] ?? null) ?: null, 'producer_type' => ($data['producer_type'] ?? null) ?: null,
            'level_code' => ($data['level_code'] ?? null) ?: null, 'rate_bp' => $bp('rate_percent') ?? 0, 'override_rate_bp' => $bp('override_rate_percent') ?? 0, 'cap_bp' => $bp('cap_percent'),
            'min_persistency_bp' => $bp('min_persistency_percent')]), PageSupport::actor($request));

        return back()->with('status', 'Rule added.');
    }

    public function endRule(Request $request, string $rule): RedirectResponse
    {
        /** @var array{effective_to: string} $data */
        $data = $request->validate(['effective_to' => ['required', 'date_format:Y-m-d']]);
        $this->schemes->endRule($rule, CarbonImmutable::parse($data['effective_to']), PageSupport::actor($request));

        return back()->with('status', 'Rule ended from '.CarbonImmutable::parse($data['effective_to'])->format('j M Y').'.');
    }
}
