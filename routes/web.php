<?php

declare(strict_types=1);

use App\Modules\Accounting\Http\Controllers\ImportController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Accounting\Http\Controllers\TrialBalanceController;
use App\Modules\Insurance\Party\Http\Controllers\PartyPageController;
use App\Modules\Insurance\Policy\Http\Controllers\PolicyPageController;
use App\Modules\Insurance\Product\Http\Controllers\ProductPageController;
use App\Modules\Platform\Authentication\Http\SecurityPageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/accounting/journals');

Route::middleware('auth')->get('account/security', SecurityPageController::class)->name('account.security');

// Read-only accounting pages (slice 0.6). Every web route runs ResolveTenant before auth (bootstrap/app.php);
// sign-in, sign-out, password reset and two-factor routes come from Fortify (config/fortify.php).
Route::middleware(['auth', 'can:accounting.view_journals'])->prefix('accounting')->name('accounting.')->group(function (): void {
    Route::get('journals', [JournalController::class, 'index'])->name('journals.index');
    Route::get('journals/{journal}', [JournalController::class, 'show'])->name('journals.show');
    Route::get('imports', [ImportController::class, 'page'])->name('imports');
    Route::post('imports/{type}', [ImportController::class, 'submit'])->whereIn('type', ['chart-of-accounts', 'opening-balances'])->name('imports.submit');
});

// Financial reports need reports.financial (design §7.1–7.2: Auditor reports.*, Finance Manager and CFO).
Route::middleware(['auth', 'can:reports.financial'])->prefix('accounting')->name('accounting.')->group(function (): void {
    Route::get('trial-balance', TrialBalanceController::class)->name('trial-balance');
});

// Operations screens (slice 1C.8+). Each page checks its area's permissions; each action goes through the same application service as the API.
Route::middleware('auth')->group(function (): void {
    Route::get('parties', [PartyPageController::class, 'index']);
    Route::post('parties', [PartyPageController::class, 'store']);
    Route::get('parties/{party}', [PartyPageController::class, 'show'])->whereUuid('party');
    Route::post('parties/{party}/bank-accounts', [PartyPageController::class, 'storeBankAccount'])->whereUuid('party');
    Route::get('agents', [PartyPageController::class, 'agents']);
    Route::post('agents', [PartyPageController::class, 'storeAgent']);
    Route::get('products', [ProductPageController::class, 'index']);
    Route::post('products', [ProductPageController::class, 'store']);
    Route::post('products/{product}/versions', [ProductPageController::class, 'storeVersion'])->whereUuid('product');
    Route::get('policies', [PolicyPageController::class, 'index']);
    Route::get('policies/create', [PolicyPageController::class, 'create']);
    Route::post('policies', [PolicyPageController::class, 'store']);
    Route::get('policies/{policy}', [PolicyPageController::class, 'show'])->whereUuid('policy');
    Route::post('policies/{policy}/issue', [PolicyPageController::class, 'issue'])->whereUuid('policy');
    Route::post('policies/{policy}/endorse', [PolicyPageController::class, 'endorse'])->whereUuid('policy');
    Route::post('policies/{policy}/cancel', [PolicyPageController::class, 'cancel'])->whereUuid('policy');
    Route::post('policies/{policy}/{action}', [PolicyPageController::class, 'transition'])->whereUuid('policy')->whereIn('action', ['lapse', 'reinstate', 'renew']);
});
