<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Http\Controllers;

use App\Modules\Insurance\Commission\Application\CommissionPayoutService;
use App\Modules\Insurance\Commission\Application\CommissionPlanService;
use App\Modules\Insurance\Commission\Application\CommissionStatementRun;
use App\Modules\Insurance\Commission\Domain\Models\CommissionStatement;
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

    /**
     * GA-10 (D-75): the monthly statement run is the only way to approve commission. Prepares the draft statements of a legal entity's month and returns them;
     * each is approved with `approve`, then paid by someone else with `pay`.
     */
    public function prepare(Request $request, CommissionStatementRun $run): JsonResponse
    {
        /** @var array{entity_id: string, period_end: string} $data */
        $data = $request->validate(['entity_id' => ['required', 'uuid'], 'period_end' => ['required', 'date_format:Y-m-d']]);
        $ids = $run->prepare($data['entity_id'], CarbonImmutable::parse($data['period_end'])->endOfMonth(), self::actor($request));

        return response()->json(['data' => CommissionStatement::query()->whereKey($ids)->orderBy('created_at')->get()->map(fn (CommissionStatement $s): array => self::presentStatement($s))->values()->all()], 201);
    }

    public function approve(Request $request, string $statement, CommissionStatementRun $run): JsonResponse
    {
        /** @var array{on: string} $data */
        $data = $request->validate(['on' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => self::presentStatement($run->approve($statement, self::actor($request), CarbonImmutable::parse($data['on'])))]);
    }

    public function pay(Request $request, string $statement, CommissionPayoutService $payouts): JsonResponse
    {
        /** @var array{paid_on: string, bank_account_id?: string|null} $data */
        $data = $request->validate(['paid_on' => ['required', 'date_format:Y-m-d'], 'bank_account_id' => ['nullable', 'uuid']]);

        return response()->json(['data' => self::presentStatement($payouts->pay($statement, $data['bank_account_id'] ?? null, self::actor($request), CarbonImmutable::parse($data['paid_on'])))]);
    }

    /** @return array<string, mixed> */
    private static function presentStatement(CommissionStatement $statement): array
    {
        return ['id' => $statement->id, 'number' => $statement->number, 'agent_id' => $statement->agent_id, 'up_to' => $statement->up_to->toDateString(),
            'gross_minor' => $statement->gross_minor, 'withholding_minor' => $statement->withholding_minor, 'net_minor' => $statement->net_minor,
            'status' => $statement->status, 'paid_via' => $statement->paid_via, 'paid_on' => $statement->paid_on?->toDateString()];
    }

    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
