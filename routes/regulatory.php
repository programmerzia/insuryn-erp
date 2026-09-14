<?php

declare(strict_types=1);

use App\Modules\Insurance\Regulatory\Http\Controllers\RegulatoryReturnsPageController;
use App\Modules\Insurance\Regulatory\Http\Controllers\TechnicalProvisionsPageController;
use Illuminate\Support\Facades\Route;

// Market gap G5: regulatory returns and technical provisions. Required from routes/web.php inside the authenticated group; controllers and services authorize.
Route::prefix('regulatory')->group(function (): void {
    Route::get('/', [RegulatoryReturnsPageController::class, 'dashboard']);
    Route::get('returns', [RegulatoryReturnsPageController::class, 'index']);
    Route::post('returns/generate', [RegulatoryReturnsPageController::class, 'generate']);
    Route::get('returns/export', [RegulatoryReturnsPageController::class, 'export']);
    Route::post('returns/{return}/review', [RegulatoryReturnsPageController::class, 'review'])->whereUuid('return');
    Route::post('returns/{return}/file', [RegulatoryReturnsPageController::class, 'file'])->whereUuid('return');
    Route::get('provisions', [TechnicalProvisionsPageController::class, 'index']);
    Route::post('provisions/prepare', [TechnicalProvisionsPageController::class, 'prepare']);
    Route::post('provisions/{run}/review', [TechnicalProvisionsPageController::class, 'review'])->whereUuid('run');
    Route::post('provisions/{run}/approve', [TechnicalProvisionsPageController::class, 'approve'])->whereUuid('run')->middleware('moves-money');
});
