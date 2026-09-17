<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers\Ledger;

use App\Http\Ledger\OpenApi\LedgerOperation;
use App\Modules\Accounting\Application\Integration\ExternalEventValidator;
use App\Modules\Accounting\Application\Integration\LedgerScope;
use App\Modules\Accounting\Application\Integration\PreviewExternalEvent;
use App\Modules\Accounting\Application\Integration\ReplayEventQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/v1/events/preview and /api/v1/events/{event}/replay */
final class PreviewController
{
    #[LedgerOperation('Preview journal lines without posting', 'EventPreview', request: 'EventBody', status: 200)]
    public function preview(Request $request, PreviewExternalEvent $preview, ExternalEventValidator $validator): JsonResponse
    {
        /** @var array{event_type: string, transaction_date: string, effective_date?: string, currency: string, payload: array<string, mixed>, dimensions: array<string, mixed>} $data */
        $data = $request->validate([
            'event_type' => ['required', 'string', 'max:64'],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'effective_date' => ['sometimes', 'date_format:Y-m-d'],
            'currency' => ['required', 'string', 'size:3'],
            'payload' => ['present', 'array'],
            'dimensions' => ['required', 'array'],
        ]);
        $scope = LedgerScope::resolve();
        $effective = CarbonImmutable::parse($data['effective_date'] ?? $data['transaction_date']);
        $currency = strtoupper($data['currency']);
        // Same boundary checks as a submit, minus the period: a preview is a dry run and may look at any date.
        $validated = $validator->validate($scope->entityId, $data['event_type'], CarbonImmutable::parse($data['transaction_date']), $effective, $currency, $data['payload'], $data['dimensions'], checkPeriod: false);

        return response()->json(['data' => $preview->preview(
            $scope->entityId,
            $data['event_type'],
            $effective,
            $currency,
            $data['payload'],
            $validated->dimensions,
        )]);
    }

    #[LedgerOperation('Replay rules against a posted event', 'EventReplay', status: 200)]
    public function replay(string $event, ReplayEventQuery $replay): JsonResponse
    {
        return response()->json(['data' => $replay->replay($event)]);
    }
}
