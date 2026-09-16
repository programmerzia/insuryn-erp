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

// JSON API. Every api route runs ResolveTenant (bootstrap/app.php).
// Ledger integration API (docs/plan/api-accounting-v1.md): Sanctum tokens for kind=integration users.
Route::prefix('v1')->group(function (): void {
    Route::post('tokens', [\App\Http\Ledger\LedgerTokenController::class, 'issue'])->middleware('throttle:10,1');
    Route::middleware(['auth:sanctum', 'ledger-integration'])->group(function (): void {
        Route::delete('tokens/current', [\App\Http\Ledger\LedgerTokenController::class, 'revoke']);
        Route::middleware('abilities:integration:events:read')->group(function (): void {
            Route::get('event-types', [\App\Modules\Accounting\Http\Controllers\Ledger\EventTypeController::class, 'index']);
            Route::post('events/preview', [\App\Modules\Accounting\Http\Controllers\Ledger\PreviewController::class, 'preview']);
            Route::post('events/{event}/replay', [\App\Modules\Accounting\Http\Controllers\Ledger\PreviewController::class, 'replay'])->whereUuid('event');
            Route::get('events/{event}', [\App\Modules\Accounting\Http\Controllers\Ledger\EventController::class, 'show'])->whereUuid('event');
            Route::get('journals/{journal}', [\App\Modules\Accounting\Http\Controllers\Ledger\JournalController::class, 'show'])->whereUuid('journal');
            Route::get('balances', [\App\Modules\Accounting\Http\Controllers\Ledger\BalanceController::class, 'show']);
            Route::get('trial-balance', [\App\Modules\Accounting\Http\Controllers\Ledger\BalanceController::class, 'trialBalance']);
            Route::get('reports/profit-and-loss', [\App\Modules\Accounting\Http\Controllers\Ledger\ReportController::class, 'profitAndLoss']);
            Route::get('reports/balance-sheet', [\App\Modules\Accounting\Http\Controllers\Ledger\ReportController::class, 'balanceSheet']);
        });
        Route::post('events', [\App\Modules\Accounting\Http\Controllers\Ledger\EventController::class, 'store'])->middleware('abilities:integration:events:write');
    });
});

Route::middleware('auth')->prefix('accounting')->group(function (): void {
    Route::post('imports/{type}', [ImportController::class, 'api'])->whereIn('type', ['chart-of-accounts', 'opening-balances'])->name('api.accounting.imports');
    Route::post('periods/{period}/close', [PeriodCloseController::class, 'start'])->whereUuid('period');
    Route::get('close-runs/{run}', [PeriodCloseController::class, 'show'])->whereUuid('run');
    Route::post('close-tasks/{task}/execute', [PeriodCloseController::class, 'execute'])->whereUuid('task');
    Route::post('close-tasks/{task}/skip', [PeriodCloseController::class, 'skip'])->whereUuid('task');
});

// Producer portal (Distribution design note §5, slice D9): Sanctum tokens for portal users; docs/api/producer-portal.openapi.json (php artisan portal:openapi).
Route::prefix('portal')->group(function (): void {
    Route::post('tokens', [\App\Http\Portal\PortalTokenController::class, 'issue'])->middleware('throttle:10,1');
    Route::middleware(['auth:sanctum', 'producer-portal'])->group(function (): void {
        Route::delete('tokens/current', [\App\Http\Portal\PortalTokenController::class, 'revoke']);
        Route::middleware('abilities:portal:read')->group(function (): void {
            Route::get('me', [\App\Http\Portal\PortalController::class, 'me']);
            Route::get('licence', [\App\Http\Portal\PortalController::class, 'licence']);
            Route::get('customers', [\App\Http\Portal\PortalController::class, 'customers']);
            Route::get('policies', [\App\Http\Portal\PortalController::class, 'policies']);
            Route::get('policies/{policy}', [\App\Http\Portal\PortalController::class, 'policy'])->whereUuid('policy');
            Route::get('renewals-due', [\App\Http\Portal\PortalController::class, 'renewalsDue']);
            Route::get('collections-to-deposit', [\App\Http\Portal\PortalController::class, 'collectionsToDeposit']);
            Route::get('statements', [\App\Http\Portal\PortalController::class, 'statements']);
            Route::get('statements/{statement}', [\App\Http\Portal\PortalController::class, 'statement'])->whereUuid('statement');
            Route::get('targets', [\App\Http\Portal\PortalController::class, 'targets']);
        });
        Route::post('collections', [\App\Http\Portal\PortalController::class, 'recordCollection'])->middleware('abilities:portal:collect');
    });
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
    // GA-10 (D-75): commission is approved only through the monthly statement run (prepare, approve), then paid by someone else.
    Route::post('commission-statements/prepare', [CommissionController::class, 'prepare']);
    Route::post('commission-statements/{statement}/approve', [CommissionController::class, 'approve'])->whereUuid('statement');
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
    Route::get('unearned-premium', [InsuranceReportController::class, 'unearnedPremium']);
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
