<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Http\Controllers;

use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ChequeBounceService;
use App\Modules\Insurance\Collections\Application\ChequeDetails;
use App\Modules\Insurance\Collections\Application\ChequeRegisterQuery;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Collections\Domain\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ReceiptController
{
    public function __construct(private readonly ReceiptService $receipts) {}

    public function store(Request $request): JsonResponse
    {
        /** @var array{branch_id: string, party_id?: string|null, channel: string, amount_minor: int, value_date: string, bank_account_id?: string|null, reference?: string|null, allocations?: list<array{installment_id: string, amount_minor: int}>, cheque_no?: string|null, cheque_bank?: string|null, cheque_date?: string|null} $data */
        $data = $request->validate([
            'branch_id' => ['required', 'uuid'], 'party_id' => ['nullable', 'uuid'],
            'channel' => ['required', 'in:bank_transfer,cash,cheque,card,mobile_money'], 'amount_minor' => ['required', 'integer', 'min:1'],
            'value_date' => ['required', 'date_format:Y-m-d'], 'bank_account_id' => ['nullable', 'uuid'], 'reference' => ['nullable', 'string', 'max:255'],
            'allocations' => ['sometimes', 'array'], 'allocations.*.installment_id' => ['required', 'uuid'], 'allocations.*.amount_minor' => ['required', 'integer', 'min:1'],
            'cheque_no' => ['nullable', 'string', 'max:64'], 'cheque_bank' => ['nullable', 'string', 'max:255'], 'cheque_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $entityId = (string) DB::table('branches')->where('id', $data['branch_id'])->value('entity_id');
        $currency = (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency');
        $allocations = array_map(fn (array $a): AllocationLine => new AllocationLine($a['installment_id'], (int) $a['amount_minor']), $data['allocations'] ?? []);

        $receipt = $this->receipts->record(new RecordReceiptRequest($entityId, $data['branch_id'], $data['party_id'] ?? null, $data['channel'], (int) $data['amount_minor'],
            $currency, CarbonImmutable::parse($data['value_date']), $data['bank_account_id'] ?? null, $data['reference'] ?? null, $allocations,
            isset($data['cheque_no'], $data['cheque_bank'], $data['cheque_date']) ? new ChequeDetails($data['cheque_no'], $data['cheque_bank'], CarbonImmutable::parse($data['cheque_date'])) : null), self::actor($request));

        return response()->json(['data' => self::present($receipt)], 201);
    }

    public function show(string $receipt): JsonResponse
    {
        return response()->json(['data' => self::present(Receipt::query()->findOrFail($receipt))]);
    }

    public function bounce(Request $request, string $receipt, ChequeBounceService $bounces): JsonResponse
    {
        /** @var array{bounced_on: string, reason: string} $data */
        $data = $request->validate(['bounced_on' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => self::present($bounces->bounce($receipt, $data['reason'], self::actor($request), CarbonImmutable::parse($data['bounced_on'])))]);
    }

    public function cheques(Request $request, ChequeRegisterQuery $register, PermissionChecker $permissions): JsonResponse
    {
        /** @var array{entity_id: string, from: string, to: string} $data */
        $data = $request->validate(['entity_id' => ['required', 'uuid'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $permissions->authorize(self::actor($request), 'receipt.create', AuthorizationScope::entity($data['entity_id']));

        return response()->json(['data' => $register->register($data['entity_id'], CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']))]);
    }

    /** @return array<string, mixed> */
    private static function present(Receipt $receipt): array
    {
        return ['id' => $receipt->id, 'number' => $receipt->number, 'status' => $receipt->status->value, 'branch_id' => $receipt->branch_id,
            'party_id' => $receipt->party_id, 'channel' => $receipt->channel, 'amount_minor' => $receipt->amount_minor, 'currency' => $receipt->currency,
            'value_date' => $receipt->value_date->toDateString(), 'reference' => $receipt->reference, 'bank_account_id' => $receipt->bank_account_id,
            'cheque_no' => $receipt->cheque_no, 'cheque_bank' => $receipt->cheque_bank, 'bounced_on' => $receipt->bounced_on?->toDateString()];
    }

    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
