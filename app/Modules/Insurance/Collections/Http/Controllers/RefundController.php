<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Http\Controllers;

use App\Modules\Insurance\Collections\Application\RefundService;
use App\Modules\Insurance\Collections\Domain\Models\Refund;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RefundController
{
    public function __construct(private readonly RefundService $refunds) {}

    public function store(Request $request, string $policy): JsonResponse
    {
        /** @var array{amount_minor: int, reason: string} $data */
        $data = $request->validate(['amount_minor' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => self::present($this->refunds->request($policy, (int) $data['amount_minor'], $data['reason'], self::actor($request)))], 201);
    }

    public function release(Request $request, string $refund): JsonResponse
    {
        /** @var array{paid_on?: string} $data */
        $data = $request->validate(['paid_on' => ['sometimes', 'date_format:Y-m-d']]);
        $paidOn = isset($data['paid_on']) ? CarbonImmutable::parse($data['paid_on']) : app(BusinessClock::class)->today();

        return response()->json(['data' => self::present($this->refunds->release($refund, self::actor($request), $paidOn))]);
    }

    public function reject(Request $request, string $refund): JsonResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => self::present($this->refunds->reject($refund, $data['reason'], self::actor($request)))]);
    }

    /** @return array<string, mixed> */
    private static function present(Refund $refund): array
    {
        return ['id' => $refund->id, 'policy_id' => $refund->policy_id, 'amount_minor' => $refund->amount_minor, 'currency' => $refund->currency,
            'status' => $refund->status->value, 'reason' => $refund->reason, 'decision_reason' => $refund->decision_reason];
    }

    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
