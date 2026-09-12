<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Http\Controllers;

use App\Modules\Insurance\Commission\Application\CommissionPlanService;
use App\Modules\Insurance\Commission\Application\CommissionStatementQuery;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommissionController
{
    public function __construct(
        private readonly CommissionPlanService $plans,
        private readonly CommissionStatementQuery $statements,
        private readonly PermissionChecker $permissions,
    ) {}

    public function storePlan(Request $request): JsonResponse
    {
        /** @var array{code: string, name: string, rate_bp: int, withholding_jurisdiction?: string|null, withholding_tax_type?: string|null} $data */
        $data = $request->validate(['code' => ['required', 'string', 'max:64'], 'name' => ['required', 'string', 'max:255'], 'rate_bp' => ['required', 'integer'],
            'withholding_jurisdiction' => ['nullable', 'string', 'max:16'], 'withholding_tax_type' => ['nullable', 'string', 'max:64']]);
        $plan = $this->plans->create($data['code'], $data['name'], (int) $data['rate_bp'], $data['withholding_jurisdiction'] ?? null, $data['withholding_tax_type'] ?? null, self::actor($request));

        return response()->json(['data' => $plan->only(['id', 'code', 'name', 'rate_bp', 'withholding_jurisdiction', 'withholding_tax_type', 'status'])], 201);
    }

    public function statement(Request $request, string $agent): JsonResponse
    {
        /** @var array{from: string, to: string} $data */
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $this->permissions->authorize(self::actor($request), 'reports.financial');

        return response()->json(['data' => $this->statements->statement($agent, CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']))]);
    }

    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
