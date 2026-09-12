<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Http\Controllers;

use App\Modules\Insurance\Collections\Application\AllocationLine;
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
        /** @var array{branch_id: string, party_id?: string|null, channel: string, amount_minor: int, value_date: string, bank_account_id?: string|null, reference?: string|null, allocations?: list<array{installment_id: string, amount_minor: int}>} $data */
        $data = $request->validate([
            'branch_id' => ['required', 'uuid'], 'party_id' => ['nullable', 'uuid'],
            'channel' => ['required', 'in:bank_transfer,cash,cheque,card,mobile_money'], 'amount_minor' => ['required', 'integer', 'min:1'],
            'value_date' => ['required', 'date_format:Y-m-d'], 'bank_account_id' => ['nullable', 'uuid'], 'reference' => ['nullable', 'string', 'max:255'],
            'allocations' => ['sometimes', 'array'], 'allocations.*.installment_id' => ['required', 'uuid'], 'allocations.*.amount_minor' => ['required', 'integer', 'min:1'],
        ]);
        $entityId = (string) DB::table('branches')->where('id', $data['branch_id'])->value('entity_id');
        $currency = (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency');
        $allocations = array_map(fn (array $a): AllocationLine => new AllocationLine($a['installment_id'], (int) $a['amount_minor']), $data['allocations'] ?? []);

        $receipt = $this->receipts->record(new RecordReceiptRequest($entityId, $data['branch_id'], $data['party_id'] ?? null, $data['channel'], (int) $data['amount_minor'],
            $currency, CarbonImmutable::parse($data['value_date']), $data['bank_account_id'] ?? null, $data['reference'] ?? null, $allocations), self::actor($request));

        return response()->json(['data' => self::present($receipt)], 201);
    }

    public function show(string $receipt): JsonResponse
    {
        return response()->json(['data' => self::present(Receipt::query()->findOrFail($receipt))]);
    }

    /** @return array<string, mixed> */
    private static function present(Receipt $receipt): array
    {
        return ['id' => $receipt->id, 'number' => $receipt->number, 'status' => $receipt->status->value, 'branch_id' => $receipt->branch_id,
            'party_id' => $receipt->party_id, 'channel' => $receipt->channel, 'amount_minor' => $receipt->amount_minor, 'currency' => $receipt->currency,
            'value_date' => $receipt->value_date->toDateString(), 'reference' => $receipt->reference, 'bank_account_id' => $receipt->bank_account_id];
    }

    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
