<?php

declare(strict_types=1);

use App\Modules\Accounting\Http\Controllers\FinancialReportController;
use App\Modules\Accounting\Http\Controllers\ImportController;
use App\Modules\Accounting\Http\Controllers\PeriodCloseController;
use App\Modules\Distribution\Http\Controllers\CompensationSchemeController;
use App\Modules\Distribution\Http\Controllers\LicenceController;
use App\Modules\Finance\Bank\Http\Controllers\BankController;
use App\Modules\Insurance\Claims\Http\Controllers\ClaimController;
use App\Modules\Insurance\Collections\Http\Controllers\ReceiptController;
use App\Modules\Insurance\Collections\Http\Controllers\RefundController;
use App\Modules\Insurance\Collections\Http\Controllers\SuspenseController;
use App\Modules\Insurance\Commission\Http\Controllers\CommissionController;
use App\Modules\Insurance\Party\Http\Controllers\AgentController;
use App\Modules\Insurance\Party\Http\Controllers\PartyController;
use App\Modules\Insurance\Policy\Http\Controllers\PolicyController;
use App\Modules\Insurance\Product\Http\Controllers\ProductController;
use App\Modules\Insurance\Reports\Http\Controllers\InsuranceReportController;
use Illuminate\Support\Facades\Route;

// JSON API. Every api route runs ResolveTenant (bootstrap/app.php). Authentication is the default guard:
// token authentication for external clients (Sanctum, design §0) is not built yet.
Route::middleware('auth')->prefix('accounting')->group(function (): void {
    Route::post('imports/{type}', [ImportController::class, 'api'])->whereIn('type', ['chart-of-accounts', 'opening-balances'])->name('api.accounting.imports');
    Route::post('periods/{period}/close', [PeriodCloseController::class, 'start'])->whereUuid('period');
    Route::get('close-runs/{run}', [PeriodCloseController::class, 'show'])->whereUuid('run');
    Route::post('close-tasks/{task}/execute', [PeriodCloseController::class, 'execute'])->whereUuid('task');
    Route::post('close-tasks/{task}/skip', [PeriodCloseController::class, 'skip'])->whereUuid('task');
});

Route::middleware('auth')->prefix('distribution')->group(function (): void {
    Route::get('producers/{producer}/licences', [LicenceController::class, 'index'])->whereUuid('producer');
    Route::post('producers/{producer}/licences', [LicenceController::class, 'store'])->whereUuid('producer');
    Route::post('licences/{licence}/{action}', [LicenceController::class, 'changeStatus'])->whereUuid('licence')->whereIn('action', ['suspend', 'revoke', 'reinstate']);
    Route::get('licences/register', [LicenceController::class, 'register']);
    Route::get('schemes', [CompensationSchemeController::class, 'index']);
    Route::post('schemes', [CompensationSchemeController::class, 'store']);
    Route::get('schemes/{scheme}', [CompensationSchemeController::class, 'show'])->whereUuid('scheme');
    Route::put('schemes/{scheme}/compliance-profile', [CompensationSchemeController::class, 'updateProfile'])->whereUuid('scheme');
    Route::put('schemes/{scheme}/levels', [CompensationSchemeController::class, 'defineLevels'])->whereUuid('scheme');
    Route::post('schemes/{scheme}/rules', [CompensationSchemeController::class, 'storeRule'])->whereUuid('scheme');
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
    Route::post('policies', [PolicyController::class, 'store']);
    Route::get('dunning-notices', [PolicyController::class, 'dunningNotices']);
    Route::get('policies/{policy}', [PolicyController::class, 'show'])->whereUuid('policy');
    Route::get('policies/{policy}/payers', [PolicyController::class, 'payers'])->whereUuid('policy');
    foreach (['issue', 'endorse', 'cancel', 'lapse', 'reinstate', 'renew'] as $action) {
        Route::post("policies/{policy}/{$action}", [PolicyController::class, $action])->whereUuid('policy');
    }
    Route::post('receipts', [ReceiptController::class, 'store']);
    Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->whereUuid('receipt');
    Route::post('receipts/{receipt}/bounce', [ReceiptController::class, 'bounce'])->whereUuid('receipt');
    Route::get('cheques', [ReceiptController::class, 'cheques']);
    Route::post('agents/{agent}/deposits', [ReceiptController::class, 'deposit'])->whereUuid('agent');
    Route::get('suspense/ageing', [SuspenseController::class, 'ageing']);
    Route::post('suspense-items/{suspenseItem}/allocate', [SuspenseController::class, 'allocate'])->whereUuid('suspenseItem');
    Route::post('policies/{policy}/refunds', [RefundController::class, 'store'])->whereUuid('policy');
    Route::post('refunds/{refund}/release', [RefundController::class, 'release'])->whereUuid('refund');
    Route::post('refunds/{refund}/reject', [RefundController::class, 'reject'])->whereUuid('refund');
    Route::post('commission-plans', [CommissionController::class, 'storePlan']);
    Route::get('agents/{agent}/commission-statement', [CommissionController::class, 'statement'])->whereUuid('agent');
    Route::post('agents/{agent}/commission-statements', [CommissionController::class, 'approvePayout'])->whereUuid('agent');
    Route::post('commission-statements/{statement}/pay', [CommissionController::class, 'pay'])->whereUuid('statement');
    Route::post('claims', [ClaimController::class, 'store']);
    Route::get('claims/{claim}', [ClaimController::class, 'show'])->whereUuid('claim');
    foreach (['reserve', 'close', 'reject', 'reopen', 'recover'] as $action) {
        Route::post("claims/{claim}/{$action}", [ClaimController::class, $action])->whereUuid('claim');
    }
    Route::post('claims/{claim}/payments', [ClaimController::class, 'approvePayment'])->whereUuid('claim');
    Route::post('claim-payments/{payment}/request-release', [ClaimController::class, 'requestRelease'])->whereUuid('payment');
    Route::post('claim-payments/{payment}/release', [ClaimController::class, 'release'])->whereUuid('payment');
});

Route::middleware('auth')->prefix('finance')->group(function (): void {
    Route::post('bank-accounts', [BankController::class, 'store']);
    Route::post('bank-accounts/{bankAccount}/statements', [BankController::class, 'importStatement'])->whereUuid('bankAccount');
    Route::post('bank-accounts/{bankAccount}/auto-match', [BankController::class, 'autoMatch'])->whereUuid('bankAccount');
    Route::get('bank-accounts/{bankAccount}/unmatched', [BankController::class, 'unmatched'])->whereUuid('bankAccount');
    Route::post('bank-statement-lines/{statementLine}/match', [BankController::class, 'match'])->whereUuid('statementLine');
    Route::post('bank-statement-lines/{statementLine}/explain', [BankController::class, 'explain'])->whereUuid('statementLine');
});

// Read-only reports (slice 1A.10); every figure carries URLs drilling to account activity and journals.
Route::middleware('auth')->prefix('reports')->group(function (): void {
    Route::get('premium-register', [InsuranceReportController::class, 'premiumRegister']);
    Route::get('leaderboard', [InsuranceReportController::class, 'leaderboard']);
    Route::get('persistency', [InsuranceReportController::class, 'persistency']);
    Route::get('receivable-ageing', [InsuranceReportController::class, 'receivableAgeing']);
    Route::get('suspense-ageing', [InsuranceReportController::class, 'suspenseAgeing']);
    Route::get('commission-statement', [InsuranceReportController::class, 'commissionStatement']);
    Route::get('outstanding-claims', [InsuranceReportController::class, 'outstandingClaims']);
    Route::get('agent-cash', [InsuranceReportController::class, 'agentCash']);
    Route::get('loss-ratio', [InsuranceReportController::class, 'lossRatio']);
    Route::get('claims-paid', [InsuranceReportController::class, 'claimsPaid']);
    Route::get('profit-and-loss', [FinancialReportController::class, 'profitAndLoss']);
    Route::get('balance-sheet', [FinancialReportController::class, 'balanceSheet']);
    Route::get('accounts/{account}/activity', [FinancialReportController::class, 'accountActivity'])->whereUuid('account');
});
