<?php

declare(strict_types=1);

use App\Modules\Accounting\Http\Controllers\ImportController;
use App\Modules\Insurance\Party\Http\Controllers\AgentController;
use App\Modules\Insurance\Party\Http\Controllers\PartyController;
use App\Modules\Insurance\Product\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

// JSON API. Every api route runs ResolveTenant (bootstrap/app.php). Authentication is the default guard:
// token authentication for external clients (Sanctum, design §0) is not built yet.
Route::middleware('auth')->prefix('accounting')->group(function (): void {
    Route::post('imports/{type}', [ImportController::class, 'api'])->whereIn('type', ['chart-of-accounts', 'opening-balances'])->name('api.accounting.imports');
});

Route::middleware('auth')->prefix('insurance')->group(function (): void {
    Route::get('parties', [PartyController::class, 'index']);
    Route::post('parties', [PartyController::class, 'store']);
    Route::get('parties/{party}', [PartyController::class, 'show'])->whereUuid('party');
    Route::patch('parties/{party}', [PartyController::class, 'update'])->whereUuid('party');
    Route::post('parties/{party}/bank-accounts', [PartyController::class, 'storeBankAccount'])->whereUuid('party');
    Route::get('agents', [AgentController::class, 'index']);
    Route::post('agents', [AgentController::class, 'store']);
    Route::get('agents/{agent}', [AgentController::class, 'show'])->whereUuid('agent');
    Route::patch('agents/{agent}', [AgentController::class, 'update'])->whereUuid('agent');
    Route::get('products', [ProductController::class, 'index']);
    Route::post('products', [ProductController::class, 'store']);
    Route::get('products/{product}', [ProductController::class, 'show'])->whereUuid('product');
    Route::post('products/{product}/versions', [ProductController::class, 'storeVersion'])->whereUuid('product');
    Route::get('products/{product}/versions/resolve', [ProductController::class, 'resolveVersion'])->whereUuid('product');
    Route::patch('products/{product}/versions/{version}', [ProductController::class, 'endVersion'])->whereUuid(['product', 'version']);
});
