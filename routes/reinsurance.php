<?php

declare(strict_types=1);

use App\Modules\Insurance\Reinsurance\Http\Controllers\ReinsurancePageController;
use Illuminate\Support\Facades\Route;

// Reinsurance MVP (market gap G4): treaties and reinsurers, cessions, facultative placements from the policy page, reinsurer statements. The controller and
// the application services authorize; a facultative placement posts, so it gets the journal preview. Bordereaux are reports (/reports/ri-*-bordereau).
Route::middleware('auth')->group(function (): void {
    Route::get('reinsurance/treaties', [ReinsurancePageController::class, 'treaties']);
    Route::get('reinsurance/treaties/create', [ReinsurancePageController::class, 'createTreaty']);
    Route::post('reinsurance/treaties', [ReinsurancePageController::class, 'storeTreaty']);
    Route::get('reinsurance/treaties/{treaty}', [ReinsurancePageController::class, 'editTreaty'])->whereUuid('treaty');
    Route::put('reinsurance/treaties/{treaty}', [ReinsurancePageController::class, 'updateTreaty'])->whereUuid('treaty');
    Route::post('reinsurance/reinsurers', [ReinsurancePageController::class, 'storeReinsurer']);
    Route::get('reinsurance/cessions', [ReinsurancePageController::class, 'cessions']);
    Route::get('reinsurance/statements', [ReinsurancePageController::class, 'statements']);
    Route::post('reinsurance/statements', [ReinsurancePageController::class, 'prepareStatement']);
    Route::post('policies/{policy}/facultative', [ReinsurancePageController::class, 'placeFacultative'])->whereUuid('policy')->middleware('moves-money');
});
