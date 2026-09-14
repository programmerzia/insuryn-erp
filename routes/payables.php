<?php

declare(strict_types=1);

use App\Modules\Finance\Payables\Http\Controllers\BillsPageController;
use App\Modules\Finance\Payables\Http\Controllers\PaymentRunsPageController;
use App\Modules\Finance\Payables\Http\Controllers\SuppliersPageController;
use Illuminate\Support\Facades\Route;

// Slices 2.3/2.4 accounts payable (addendum v2 §B.4): suppliers, supplier bills and payment runs. Services authorize every action (ap.*); actions that
// post to the ledger are `moves-money`, so their forms show the journal before confirming.
Route::middleware('auth')->prefix('payables')->group(function (): void {
    Route::get('suppliers', [SuppliersPageController::class, 'index']);
    Route::post('suppliers', [SuppliersPageController::class, 'store']);
    Route::get('suppliers/{supplier}', [SuppliersPageController::class, 'show'])->whereUuid('supplier');
    Route::put('suppliers/{supplier}', [SuppliersPageController::class, 'update'])->whereUuid('supplier');

    Route::get('bills', [BillsPageController::class, 'index']);
    Route::get('bills/create', [BillsPageController::class, 'create']);
    Route::post('bills', [BillsPageController::class, 'store']);
    Route::get('bills/{bill}', [BillsPageController::class, 'show'])->whereUuid('bill');
    Route::post('bills/{bill}/submit', [BillsPageController::class, 'submit'])->whereUuid('bill');
    Route::post('bills/{bill}/approve', [BillsPageController::class, 'approve'])->whereUuid('bill')->middleware('moves-money');
    Route::post('bills/{bill}/reject', [BillsPageController::class, 'reject'])->whereUuid('bill');
    Route::post('bills/{bill}/cancel', [BillsPageController::class, 'cancel'])->whereUuid('bill')->middleware('moves-money');
    Route::post('bills/{bill}/documents', [BillsPageController::class, 'attachDocument'])->whereUuid('bill');
    Route::get('bills/{bill}/documents/{document}', [BillsPageController::class, 'downloadDocument'])->whereUuid(['bill', 'document']);

    Route::get('payment-runs', [PaymentRunsPageController::class, 'index']);
    Route::get('payment-runs/create', [PaymentRunsPageController::class, 'create']);
    Route::post('payment-runs', [PaymentRunsPageController::class, 'store']);
    Route::get('payment-runs/{run}', [PaymentRunsPageController::class, 'show'])->whereUuid('run');
    Route::post('payment-runs/{run}/submit', [PaymentRunsPageController::class, 'submit'])->whereUuid('run');
    Route::post('payment-runs/{run}/approve', [PaymentRunsPageController::class, 'approve'])->whereUuid('run');
    Route::post('payment-runs/{run}/reject', [PaymentRunsPageController::class, 'reject'])->whereUuid('run');
    Route::post('payment-runs/{run}/release', [PaymentRunsPageController::class, 'release'])->whereUuid('run')->middleware('moves-money');
    Route::post('payment-runs/{run}/cancel', [PaymentRunsPageController::class, 'cancel'])->whereUuid('run');
    Route::get('payment-runs/{run}/bank-file', [PaymentRunsPageController::class, 'bankFile'])->whereUuid('run');
});
