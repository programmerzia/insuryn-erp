<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Modules\Accounting\Application\Close\CloseRunQuery;
use App\Modules\Accounting\Application\Close\PeriodCloseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PeriodCloseController
{
    public function __construct(
        private readonly PeriodCloseService $close,
        private readonly CloseRunQuery $runs,
    ) {}

    public function start(Request $request, string $period): JsonResponse
    {
        return response()->json(['data' => $this->runs->find($this->close->start($period, self::actor($request)))], 201);
    }

    public function show(string $run): JsonResponse
    {
        return response()->json(['data' => $this->runs->find($run) ?? abort(404)]);
    }

    public function execute(Request $request, string $task): JsonResponse
    {
        /** @var array{note?: string|null} $data */
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => ['id' => $task, 'status' => $this->close->execute($task, self::actor($request), $data['note'] ?? null)]]);
    }

    public function skip(Request $request, string $task): JsonResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => ['id' => $task, 'status' => $this->close->skip($task, $data['reason'], self::actor($request))]]);
    }

    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
