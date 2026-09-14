<?php

declare(strict_types=1);

use App\Modules\Finance\FixedAssets\Http\Controllers\FixedAssetsPageController;
use Illuminate\Support\Facades\Route;

// Design addendum v2 §B.7 fixed assets. Controllers authorize per area and object; money movements get the journal preview (moves-money).
Route::middleware('auth')->prefix('fixed-assets')->group(function (): void {
    $assets = FixedAssetsPageController::class;
    Route::get('/', [$assets, 'index']);
    Route::post('/', [$assets, 'store'])->middleware('moves-money');
    Route::get('classes', [$assets, 'classes']);
    Route::post('classes', [$assets, 'saveClass']);
    Route::put('classes/{class}', [$assets, 'saveClass'])->whereUuid('class');
    Route::post('classes/defaults', [$assets, 'defaultClasses']);
    Route::get('depreciation', [$assets, 'depreciation']);
    Route::post('depreciation/{period}', [$assets, 'postDepreciation'])->whereUuid('period')->middleware('moves-money');
    Route::get('register', [$assets, 'register']);
    Route::get('register/export', [$assets, 'export']);
    Route::get('{asset}', [$assets, 'show'])->whereUuid('asset');
    Route::post('{asset}/transfer', [$assets, 'transfer'])->whereUuid('asset')->middleware('moves-money');
    Route::post('{asset}/dispose', [$assets, 'dispose'])->whereUuid('asset')->middleware('moves-money');
    Route::post('{asset}/documents', [$assets, 'attachDocument'])->whereUuid('asset');
    Route::get('{asset}/documents/{document}', [$assets, 'downloadDocument'])->whereUuid(['asset', 'document']);
});

// Design addendum v2 §B.8.1 budgets: versions, the account × month grid per branch, approval (SoD prepare ✕ approve), variance report.
Route::middleware('auth')->prefix('budgets')->group(function (): void {
    $budgets = \App\Modules\Finance\Budget\Http\Controllers\BudgetsPageController::class;
    Route::get('/', [$budgets, 'index']);
    Route::post('/', [$budgets, 'store']);
    Route::get('variance', [$budgets, 'variance']);
    Route::get('variance/export', [$budgets, 'exportVariance']);
    Route::get('{budget}', [$budgets, 'show'])->whereUuid('budget');
    Route::post('{budget}/lines', [$budgets, 'saveRows'])->whereUuid('budget');
    Route::post('{budget}/paste', [$budgets, 'paste'])->whereUuid('budget');
    Route::post('{budget}/copy-actuals', [$budgets, 'copyActuals'])->whereUuid('budget');
    Route::post('{budget}/{action}', [$budgets, 'transition'])->whereUuid('budget')->whereIn('action', ['submit', 'approve', 'return', 'revise']);
});
