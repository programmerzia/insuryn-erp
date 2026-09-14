<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Http\Documents\GeneratedDocumentsController;
use App\Modules\Insurance\Claims\Http\Controllers\ClaimPageController;
use App\Modules\Insurance\Collections\Http\Controllers\CollectionsPageController;
use App\Modules\Insurance\Policy\Http\Controllers\PolicyPageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Object pages (UX brief §6.2) composed at the app layer: each module's page plus the timeline (immediately) and, loaded after first paint,
 * the object's journals, audit rows and attached documents (Inertia deferred group `history`).
 */
final class ObjectPageController
{
    public function policy(Request $request, string $policy, ObjectHistory $history, ObjectDocuments $documents, GeneratedDocumentsController $generated): Response
    {
        $subjects = [['policy', $policy]];

        return app(PolicyPageController::class)->show($request, $policy)->with([
            'timeline' => $history->timeline($subjects),
            'accounting' => Inertia::defer(fn (): array => $history->accounting($history->journalsOnDimension('dim_policy', $policy)), 'history'),
            'audit' => Inertia::defer(fn (): array => $history->audit($subjects), 'history'),
            'documents' => Inertia::defer(fn (): array => $documents->forPage('policy', $policy, "/policies/{$policy}"), 'history'),
            'documentGeneration' => Inertia::defer(fn (): array => $generated->forPolicy(PageSupport::actor($request), $policy), 'history'), // slice R8
        ]);
    }

    public function claim(Request $request, string $claim, ObjectHistory $history, ObjectDocuments $documents, GeneratedDocumentsController $generated): Response
    {
        $subjects = array_values([['claim', $claim], ...array_map(fn (string $id): array => ['claim_payment', $id], DB::table('claim_payments')->where('claim_id', $claim)->pluck('id')->map(fn ($id): string => (string) $id)->all())]);

        return app(ClaimPageController::class)->show($request, $claim)->with([
            'timeline' => $history->timeline($subjects),
            'accounting' => Inertia::defer(fn (): array => $history->accounting($history->journalsOnDimension('dim_claim', $claim)), 'history'),
            'audit' => Inertia::defer(fn (): array => $history->audit($subjects), 'history'),
            'documents' => Inertia::defer(fn (): array => $documents->forPage('claim', $claim, "/claims/{$claim}"), 'history'),
            'documentGeneration' => Inertia::defer(fn (): array => $generated->forClaim(PageSupport::actor($request), $claim), 'history'), // gap audit GA-41
        ]);
    }

    public function receipt(Request $request, string $receipt, ObjectHistory $history, ObjectDocuments $documents, GeneratedDocumentsController $generated): Response
    {
        $subjects = array_values([['receipt', $receipt], ...array_map(fn (string $id): array => ['suspense_item', $id], DB::table('suspense_items')->where('receipt_id', $receipt)->pluck('id')->map(fn ($id): string => (string) $id)->all())]);

        return app(CollectionsPageController::class)->show($request, $receipt)->with([
            'timeline' => $history->timeline($subjects),
            'accounting' => Inertia::defer(fn (): array => $history->accounting($history->journalsForReceipt($receipt)), 'history'),
            'audit' => Inertia::defer(fn (): array => $history->audit($subjects), 'history'),
            'documents' => Inertia::defer(fn (): array => $documents->forPage('receipt', $receipt, "/receipts/{$receipt}"), 'history'),
            'documentGeneration' => Inertia::defer(fn (): array => $generated->forReceipt(PageSupport::actor($request), $receipt), 'history'), // slice R8
        ]);
    }
}
