<?php

declare(strict_types=1);

use App\Modules\Accounting\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

// JSON API. Every api route runs ResolveTenant (bootstrap/app.php). Authentication is the default guard:
// token authentication for external clients (Sanctum, design §0) is not built yet.
Route::middleware('auth')->prefix('accounting')->group(function (): void {
    Route::post('imports/{type}', [ImportController::class, 'api'])->whereIn('type', ['chart-of-accounts', 'opening-balances'])->name('api.accounting.imports');
});
