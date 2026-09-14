<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Http\Controllers;

use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Claims\Domain\Models\Claim;
use App\Modules\Insurance\Claims\Domain\Models\ClaimPayment;
use App\Modules\Insurance\Claims\Domain\Models\ClaimReserve;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClaimController
{
    public function __construct(
        private readonly ClaimService $claims,
        private readonly ClaimPaymentService $payments,
    ) {}

    public function store(Request $request): JsonResponse
    {
        /** @var array{policy_id: string, loss_date: string, reported_on: string, description: string} $data */
        $data = $request->validate(['policy_id' => ['required', 'uuid'], 'loss_date' => ['required', 'date_format:Y-m-d'], 'reported_on' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:5000']]);
        $claim = $this->claims->register($data['policy_id'], CarbonImmutable::parse($data['loss_date']), $data['description'], self::actor($request), CarbonImmutable::parse($data['reported_on']));

        return response()->json(['data' => self::present($claim)], 201);
    }

    public function show(string $claim): JsonResponse
    {
        $model = Claim::query()->findOrFail($claim);

        return response()->json(['data' => self::present($model) + [
            'reserves' => ClaimReserve::query()->where('claim_id', $model->id)->orderBy('version')->get()
                ->map(fn (ClaimReserve $r): array => ['version' => $r->version, 'reserve_minor' => $r->reserve_minor, 'delta_minor' => $r->delta_minor, 'kind' => $r->kind,
                    'reason' => $r->reason, 'recorded_on' => $r->recorded_on->toDateString()])->values()->all(),
            'payments' => ClaimPayment::query()->where('claim_id', $model->id)->orderBy('created_at')->get()
                ->map(fn (ClaimPayment $p): array => self::presentPayment($p))->values()->all(),
        ]]);
    }

    public function reserve(Request $request, string $claim): JsonResponse
    {
        /** @var array{reserve_minor: int, reason: string, on: string} $data */
        $data = $request->validate(['reserve_minor' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000'], 'on' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => self::present($this->claims->reserve($claim, (int) $data['reserve_minor'], $data['reason'], self::actor($request), CarbonImmutable::parse($data['on'])))]);
    }

    public function approvePayment(Request $request, string $claim): JsonResponse
    {
        /** @var array{amount_minor: int, payee_party_id: string, on: string} $data */
        $data = $request->validate(['amount_minor' => ['required', 'integer', 'min:1'], 'payee_party_id' => ['required', 'uuid'], 'on' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => self::presentPayment($this->payments->approve($claim, (int) $data['amount_minor'], $data['payee_party_id'], self::actor($request),
            CarbonImmutable::parse($data['on'])))], 201);
    }

    public function requestRelease(Request $request, string $payment): JsonResponse
    {
        /** @var array{bank_account_id?: string|null} $data */
        $data = $request->validate(['bank_account_id' => ['nullable', 'uuid']]);

        return response()->json(['data' => self::presentPayment($this->payments->requestRelease($payment, self::actor($request), $data['bank_account_id'] ?? null))]);
    }

    public function release(Request $request, string $payment): JsonResponse
    {
        /** @var array{paid_on: string} $data */
        $data = $request->validate(['paid_on' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => self::presentPayment($this->payments->release($payment, self::actor($request), CarbonImmutable::parse($data['paid_on'])))]);
    }

    public function close(Request $request, string $claim): JsonResponse
    {
        $data = self::decision($request);

        return response()->json(['data' => self::present($this->claims->close($claim, $data['reason'], self::actor($request), CarbonImmutable::parse($data['on'])))]);
    }

    public function reject(Request $request, string $claim): JsonResponse
    {
        $data = self::decision($request);

        return response()->json(['data' => self::present($this->claims->reject($claim, $data['reason'], self::actor($request), CarbonImmutable::parse($data['on'])))]);
    }

    public function reopen(Request $request, string $claim): JsonResponse
    {
        $data = self::decision($request);
        $approvalId = $this->claims->reopen($claim, $data['reason'], self::actor($request), CarbonImmutable::parse($data['on']));

        return response()->json(['data' => self::present(Claim::query()->findOrFail($claim)) + ['approval_id' => $approvalId]]);
    }

    /** Gap fix GA-21 (D-88): a recovery is receipted by the collections duties, with its bank account and payer. */
    public function recover(Request $request, string $claim, \App\Modules\Insurance\Claims\Application\ClaimRecoveryReceipts $receipts): JsonResponse
    {
        /** @var array{type: string, amount_minor: int, received_on: string, bank_account_id: string, payer_party_id: string, reference?: string|null} $data */
        $data = $request->validate(['type' => ['required', 'in:salvage,subrogation,third_party'], 'amount_minor' => ['required', 'integer', 'min:1'],
            'received_on' => ['required', 'date_format:Y-m-d'], 'bank_account_id' => ['required', 'uuid'], 'payer_party_id' => ['required', 'uuid'], 'reference' => ['nullable', 'string', 'max:255']]);
        $recovery = $receipts->receive($claim, $data['type'], (int) $data['amount_minor'], $data['bank_account_id'], $data['payer_party_id'], $data['reference'] ?? null,
            self::actor($request), CarbonImmutable::parse($data['received_on']));

        return response()->json(['data' => ['id' => $recovery->id, 'number' => $recovery->number, 'type' => $recovery->type, 'amount_minor' => $recovery->amount_minor]], 201);
    }

    /** @return array{reason: string, on: string} */
    private static function decision(Request $request): array
    {
        /** @var array{reason: string, on: string} */
        return $request->validate(['reason' => ['required', 'string', 'max:2000'], 'on' => ['required', 'date_format:Y-m-d']]);
    }

    /** @return array<string, mixed> */
    private static function present(Claim $claim): array
    {
        return ['id' => $claim->id, 'number' => $claim->number, 'policy_id' => $claim->policy_id, 'status' => $claim->status->value, 'loss_date' => $claim->loss_date->toDateString(),
            'reported_on' => $claim->reported_on->toDateString(), 'reserve_minor' => $claim->reserve_minor, 'currency' => $claim->currency];
    }

    /** @return array<string, mixed> */
    private static function presentPayment(ClaimPayment $payment): array
    {
        return ['id' => $payment->id, 'claim_id' => $payment->claim_id, 'amount_minor' => $payment->amount_minor, 'status' => $payment->status->value,
            'paid_on' => $payment->paid_on?->toDateString()];
    }

    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
