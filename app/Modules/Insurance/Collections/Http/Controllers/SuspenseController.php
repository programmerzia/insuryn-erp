<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Http\Controllers;

use App\Modules\Insurance\Collections\Application\SuspenseQuery;
use App\Modules\Insurance\Collections\Application\SuspenseService;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SuspenseController
{
    public function __construct(
        private readonly SuspenseService $suspense,
        private readonly SuspenseQuery $query,
        private readonly PermissionChecker $permissions,
    ) {}

    public function allocate(Request $request, string $suspenseItem): JsonResponse
    {
        /** @var array{installment_id: string, amount_minor: int, on?: string} $data */
        $data = $request->validate(['installment_id' => ['required', 'uuid'], 'amount_minor' => ['required', 'integer', 'min:1'], 'on' => ['sometimes', 'date_format:Y-m-d']]);
        $allocation = $this->suspense->allocate($suspenseItem, $data['installment_id'], (int) $data['amount_minor'], self::actor($request),
            isset($data['on']) ? CarbonImmutable::parse($data['on']) : CarbonImmutable::today());

        return response()->json(['data' => ['id' => $allocation->id, 'receipt_id' => $allocation->receipt_id, 'policy_id' => $allocation->policy_id, 'amount_minor' => $allocation->amount_minor]]);
    }

    /** The allocation queue with ageing; open to whoever may allocate suspense. */
    public function ageing(Request $request): JsonResponse
    {
        /** @var array{as_of?: string, entity_id?: string} $data */
        $data = $request->validate(['as_of' => ['sometimes', 'date_format:Y-m-d'], 'entity_id' => ['sometimes', 'uuid']]);
        $this->permissions->authorize(self::actor($request), 'receipt.allocate');

        return response()->json(['data' => $this->query->ageing($data['entity_id'] ?? null, isset($data['as_of']) ? CarbonImmutable::parse($data['as_of']) : CarbonImmutable::today())]);
    }

    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
