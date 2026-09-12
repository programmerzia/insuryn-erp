<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Modules\Accounting\Application\Reports\FinancialStatementsQuery;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only financial statements (reports.financial). */
final class FinancialReportController
{
    public function __construct(
        private readonly FinancialStatementsQuery $statements,
        private readonly PermissionChecker $permissions,
    ) {}

    public function profitAndLoss(Request $request): JsonResponse
    {
        /** @var array{entity_id: string, from: string, to: string} $data */
        $data = $request->validate(['entity_id' => ['required', 'uuid'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $this->authorize($request, $data['entity_id']);

        return response()->json(['data' => $this->statements->profitAndLoss($data['entity_id'], CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']))]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        /** @var array{entity_id: string, as_of: string} $data */
        $data = $request->validate(['entity_id' => ['required', 'uuid'], 'as_of' => ['required', 'date_format:Y-m-d']]);
        $this->authorize($request, $data['entity_id']);

        return response()->json(['data' => $this->statements->balanceSheet($data['entity_id'], CarbonImmutable::parse($data['as_of']))]);
    }

    public function accountActivity(Request $request, string $account): JsonResponse
    {
        /** @var array{entity_id: string, from?: string, to: string} $data */
        $data = $request->validate(['entity_id' => ['required', 'uuid'], 'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d']]);
        $this->authorize($request, $data['entity_id']);

        return response()->json(['data' => $this->statements->accountActivity($data['entity_id'], $account,
            isset($data['from']) ? CarbonImmutable::parse($data['from']) : null, CarbonImmutable::parse($data['to']))]);
    }

    private function authorize(Request $request, string $entityId): void
    {
        $this->permissions->authorize((string) $request->user()?->getAuthIdentifier(), 'reports.financial', AuthorizationScope::entity($entityId));
    }
}
