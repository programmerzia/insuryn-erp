<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Modules\Accounting\Application\Imports\ChartOfAccountsImport;
use App\Modules\Accounting\Application\Imports\ImportMode;
use App\Modules\Accounting\Application\Imports\ImportOutcome;
use App\Modules\Accounting\Application\Imports\OpeningBalancesImport;
use App\Modules\Platform\Authorization\PermissionDenied;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Import wizard (spec §7): one POST per stage, `mode` = validate | dry_run | commit. The JSON API
 * (`/api/accounting/imports/{type}`) and the page (`/accounting/imports`) share the same handling.
 */
final class ImportController
{
    public function __construct(
        private readonly ChartOfAccountsImport $chartOfAccounts,
        private readonly OpeningBalancesImport $openingBalances,
    ) {}

    public function page(): Response
    {
        return Inertia::render('accounting/Imports', ['result' => null]);
    }

    public function api(Request $request, string $type): JsonResponse
    {
        $outcome = $this->run($request, $type);

        return response()->json($outcome->toArray(), $outcome->hasErrors() ? 422 : 200);
    }

    public function submit(Request $request, string $type): Response
    {
        return Inertia::render('accounting/Imports', ['result' => $this->run($request, $type)->toArray()]);
    }

    private function run(Request $request, string $type): ImportOutcome
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'mode' => ['required', 'in:validate,dry_run,commit'],
            'opening_date' => [$type === OpeningBalancesImport::TYPE ? 'required' : 'nullable', 'date_format:Y-m-d'],
        ]);
        /** @var UploadedFile $file */
        $file = $validated['file'];
        $csv = (string) file_get_contents($file->getRealPath());
        $mode = ImportMode::from((string) $validated['mode']);
        $scope = ReportingScope::fromRequest($request);
        $actorId = (string) $request->user()?->getAuthIdentifier();

        try {
            return match ($type) {
                ChartOfAccountsImport::TYPE => $this->chartOfAccounts->run($csv, $scope->entityId, $mode, $actorId),
                OpeningBalancesImport::TYPE => $this->openingBalances->run($csv, $scope->entityId,
                    CarbonImmutable::createFromFormat('Y-m-d', (string) $validated['opening_date']) ?: CarbonImmutable::today(), $mode, $actorId),
                default => throw new NotFoundHttpException("Unknown import type {$type}."),
            };
        } catch (PermissionDenied $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }
    }
}
