<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Http\Controllers;

use App\Modules\Distribution\Application\Compensation\CompensationRuleRequest;
use App\Modules\Distribution\Application\Compensation\CompensationSchemeDirectory;
use App\Modules\Distribution\Application\Compensation\CompensationSchemeService;
use App\Modules\Distribution\Application\Hierarchy\HierarchyService;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Compensation schemes API (slice D4): list, create, describe, compliance profile, levels, rules. Writes need commission.manage_plans. */
final class CompensationSchemeController
{
    public function __construct(
        private readonly CompensationSchemeService $schemes,
        private readonly CompensationSchemeDirectory $directory,
        private readonly PermissionChecker $permissions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissions->authorizeAny(self::actor($request), ['commission.manage_plans', 'commission.approve', 'reports.financial']);

        return response()->json(['data' => array_values(DB::table('compensation_schemes')->orderBy('code')->get(['id', 'code', 'name', 'mode', 'effective_from', 'effective_to'])
            ->map(fn (\stdClass $s): array => (array) $s)->all())]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{code: string, name: string, mode: string, effective_from: string, effective_to?: string|null, compliance_profile?: array<mixed>, withholding_jurisdiction?: string|null, withholding_tax_type?: string|null} $data */
        $data = $request->validate(['code' => ['required', 'string', 'max:32'], 'name' => ['required', 'string', 'max:255'], 'mode' => ['required', 'string'],
            'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d'], 'compliance_profile' => ['sometimes', 'array'],
            'withholding_jurisdiction' => ['nullable', 'string', 'max:8'], 'withholding_tax_type' => ['nullable', 'string', 'max:16']]);
        $id = $this->schemes->createScheme($data['code'], $data['name'], $data['mode'], CarbonImmutable::parse($data['effective_from']),
            isset($data['effective_to']) ? CarbonImmutable::parse($data['effective_to']) : null, $data['compliance_profile'] ?? [], self::actor($request),
            $data['withholding_jurisdiction'] ?? null, $data['withholding_tax_type'] ?? null);

        return response()->json(['data' => $this->directory->describe($id)], 201);
    }

    public function show(Request $request, string $scheme): JsonResponse
    {
        $this->permissions->authorizeAny(self::actor($request), ['commission.manage_plans', 'commission.approve', 'reports.financial']);

        return response()->json(['data' => $this->directory->describe($scheme) ?? abort(404)]);
    }

    public function updateProfile(Request $request, string $scheme): JsonResponse
    {
        /** @var array{compliance_profile: array<mixed>} $data */
        $data = $request->validate(['compliance_profile' => ['present', 'array']]);
        $this->schemes->updateComplianceProfile($scheme, $data['compliance_profile'], self::actor($request));

        return response()->json(['data' => $this->directory->describe($scheme)]);
    }

    public function defineLevels(Request $request, string $scheme, HierarchyService $hierarchy): JsonResponse
    {
        /** @var array{levels: list<array{code: string, rank: int, label: string}>} $data */
        $data = $request->validate(['levels' => ['required', 'array', 'min:1'], 'levels.*.code' => ['required', 'string', 'max:32'], 'levels.*.rank' => ['required', 'integer', 'min:1'],
            'levels.*.label' => ['required', 'string', 'max:255']]);
        $hierarchy->defineLevels($scheme, array_map(fn (array $l): array => ['code' => (string) $l['code'], 'rank' => (int) $l['rank'], 'label' => (string) $l['label']], $data['levels']),
            self::actor($request));

        return response()->json(['data' => $this->directory->describe($scheme)]);
    }

    public function storeRule(Request $request, string $scheme): JsonResponse
    {
        $data = $request->validate(['product_id' => ['nullable', 'uuid'], 'producer_type' => ['nullable', 'string'], 'level_code' => ['nullable', 'string', 'max:32'],
            'basis' => ['required', 'string'], 'policy_year_from' => ['required', 'integer'], 'policy_year_to' => ['required', 'integer'], 'rate_bp' => ['sometimes', 'integer', 'min:0'],
            'override_rate_bp' => ['sometimes', 'integer', 'min:0'], 'cap_bp' => ['nullable', 'integer', 'min:0'], 'min_persistency_bp' => ['nullable', 'integer', 'min:0'],
            'renewal_requires_valid_licence' => ['sometimes', 'boolean'], 'pays_after_termination' => ['sometimes', 'boolean'],
            'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d']]);
        $id = $this->schemes->addRule($scheme, CompensationRuleRequest::fromArray($data), self::actor($request));

        return response()->json(['data' => ['id' => $id, 'scheme' => $this->directory->describe($scheme)]], 201);
    }

    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
