<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Http\Controllers;

use App\Modules\Insurance\Collections\Application\AgentCashPositionQuery;
use App\Modules\Insurance\Reports\Application\ClaimsPaidRegisterQuery;
use App\Modules\Insurance\Reports\Application\CommissionStatementReport;
use App\Modules\Insurance\Reports\Application\LossRatioQuery;
use App\Modules\Insurance\Reports\Application\OutstandingClaimsQuery;
use App\Modules\Insurance\Reports\Application\PremiumRegisterQuery;
use App\Modules\Insurance\Reports\Application\ReceivableAgeingQuery;
use App\Modules\Insurance\Reports\Application\SuspenseAgeingReport;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only insurance reports (reports.financial). */
final class InsuranceReportController
{
    public function __construct(private readonly PermissionChecker $permissions) {}

    public function premiumRegister(Request $request, PremiumRegisterQuery $register): JsonResponse
    {
        /** @var array{entity_id: string, from: string, to: string} $data */
        $data = $request->validate(['entity_id' => ['required', 'uuid'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $this->authorize($request, AuthorizationScope::entity($data['entity_id']));

        return response()->json(['data' => $register->register($data['entity_id'], CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']))]);
    }

    public function receivableAgeing(Request $request, ReceivableAgeingQuery $ageing): JsonResponse
    {
        /** @var array{entity_id: string, as_of: string} $data */
        $data = $request->validate(['entity_id' => ['required', 'uuid'], 'as_of' => ['required', 'date_format:Y-m-d']]);
        $this->authorize($request, AuthorizationScope::entity($data['entity_id']));

        return response()->json(['data' => $ageing->ageing($data['entity_id'], CarbonImmutable::parse($data['as_of']))]);
    }

    public function suspenseAgeing(Request $request, SuspenseAgeingReport $ageing): JsonResponse
    {
        /** @var array{entity_id: string, as_of: string} $data */
        $data = $request->validate(['entity_id' => ['required', 'uuid'], 'as_of' => ['required', 'date_format:Y-m-d']]);
        $this->authorize($request, AuthorizationScope::entity($data['entity_id']));

        return response()->json(['data' => $ageing->ageing($data['entity_id'], CarbonImmutable::parse($data['as_of']))]);
    }

    public function commissionStatement(Request $request, CommissionStatementReport $statements): JsonResponse
    {
        /** @var array{agent_id: string, from: string, to: string} $data */
        $data = $request->validate(['agent_id' => ['required', 'uuid'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $this->authorize($request, null);

        return response()->json(['data' => $statements->statement($data['agent_id'], CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']))]);
    }

    public function agentCash(Request $request, AgentCashPositionQuery $position): JsonResponse
    {
        /** @var array{entity_id: string, as_of: string} $data */
        $data = $request->validate(['entity_id' => ['required', 'uuid'], 'as_of' => ['required', 'date_format:Y-m-d']]);
        $this->authorize($request, AuthorizationScope::entity($data['entity_id']));

        return response()->json(['data' => $position->position($data['entity_id'], CarbonImmutable::parse($data['as_of']))]);
    }

    public function outstandingClaims(Request $request, OutstandingClaimsQuery $claims): JsonResponse
    {
        /** @var array{entity_id: string, as_of: string} $data */
        $data = $request->validate(['entity_id' => ['required', 'uuid'], 'as_of' => ['required', 'date_format:Y-m-d']]);
        $this->authorize($request, AuthorizationScope::entity($data['entity_id']));

        return response()->json(['data' => $claims->outstanding($data['entity_id'], CarbonImmutable::parse($data['as_of']))]);
    }

    public function lossRatio(Request $request, LossRatioQuery $lossRatio): JsonResponse
    {
        /** @var array{entity_id: string, from: string, to: string, by: string} $data */
        $data = $request->validate(['entity_id' => ['required', 'uuid'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'by' => ['required', 'in:'.implode(',', LossRatioQuery::DIMENSIONS)]]);
        $this->authorize($request, AuthorizationScope::entity($data['entity_id']));

        return response()->json(['data' => $lossRatio->lossRatio($data['entity_id'], CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']), $data['by'])]);
    }

    public function claimsPaid(Request $request, ClaimsPaidRegisterQuery $register): JsonResponse
    {
        /** @var array{entity_id: string, from: string, to: string} $data */
        $data = $request->validate(['entity_id' => ['required', 'uuid'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $this->authorize($request, AuthorizationScope::entity($data['entity_id']));

        return response()->json(['data' => $register->register($data['entity_id'], CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']))]);
    }

    private function authorize(Request $request, ?AuthorizationScope $scope): void
    {
        $this->permissions->authorize((string) $request->user()?->getAuthIdentifier(), 'reports.financial', $scope);
    }
}
