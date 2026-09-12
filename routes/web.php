<?php

declare(strict_types=1);

use App\Modules\Accounting\Http\Controllers\ImportController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Accounting\Http\Controllers\TrialBalanceController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/accounting/journals');

// Read-only accounting pages (slice 0.6). Every web route runs ResolveTenant before auth (bootstrap/app.php).
Route::middleware(['auth', 'can:accounting.view_journals'])->prefix('accounting')->name('accounting.')->group(function (): void {
    Route::get('trial-balance', TrialBalanceController::class)->name('trial-balance');
    Route::get('journals', [JournalController::class, 'index'])->name('journals.index');
    Route::get('journals/{journal}', [JournalController::class, 'show'])->name('journals.show');
    Route::get('imports', [ImportController::class, 'page'])->name('imports');
    Route::post('imports/{type}', [ImportController::class, 'submit'])->whereIn('type', ['chart-of-accounts', 'opening-balances'])->name('imports.submit');
});
