<?php

declare(strict_types=1);

use App\Modules\Accounting\Http\Controllers\ClosePageController;
use App\Modules\Accounting\Http\Controllers\ImportController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Accounting\Http\Controllers\ManualJournalPageController;
use App\Modules\Accounting\Http\Controllers\TrialBalanceController;
use App\Modules\Finance\Bank\Http\Controllers\BankPageController;
use App\Modules\Insurance\Claims\Http\Controllers\ClaimPageController;
use App\Modules\Insurance\Collections\Http\Controllers\CollectionsPageController;
use App\Modules\Insurance\Commission\Http\Controllers\CommissionPageController;
use App\Modules\Insurance\Party\Http\Controllers\PartyPageController;
use App\Modules\Insurance\Policy\Http\Controllers\PolicyPageController;
use App\Modules\Insurance\Product\Http\Controllers\ProductPageController;
use App\Modules\Insurance\Reports\Http\Controllers\ReportsPageController;
use App\Modules\Platform\Approvals\Http\ApprovalsPageController;
use App\Modules\Platform\Authentication\Http\SecurityPageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/accounting/journals');

Route::middleware('auth')->get('account/security', SecurityPageController::class)->name('account.security');

// Read-only accounting pages (slice 0.6). Every web route runs ResolveTenant before auth (bootstrap/app.php);
// sign-in, sign-out, password reset and two-factor routes come from Fortify (config/fortify.php).
Route::middleware(['auth', 'can:accounting.view_journals'])->prefix('accounting')->name('accounting.')->group(function (): void {
    Route::get('journals', [JournalController::class, 'index'])->name('journals.index');
    Route::get('journals/create', [ManualJournalPageController::class, 'create'])->name('journals.create');
    Route::post('journals', [ManualJournalPageController::class, 'store'])->name('journals.store');
    Route::post('journals/{journal}/approve', [ManualJournalPageController::class, 'approve'])->whereUuid('journal');
    Route::post('journals/{journal}/reject', [ManualJournalPageController::class, 'reject'])->whereUuid('journal');
    Route::post('journals/{journal}/reversal-requests', [ManualJournalPageController::class, 'requestReversal'])->whereUuid('journal');
    Route::post('reversal-requests/{reversalRequest}/{decision}', [ManualJournalPageController::class, 'decideReversal'])->whereUuid('reversalRequest')->whereIn('decision', ['approve', 'reject']);
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

    Route::get('receipts', [CollectionsPageController::class, 'index']);
    Route::get('receipts/create', [CollectionsPageController::class, 'create']);
    Route::post('receipts', [CollectionsPageController::class, 'store']);
    Route::get('receipts/{receipt}', [CollectionsPageController::class, 'show'])->whereUuid('receipt');
    Route::post('receipts/{receipt}/bounce', [CollectionsPageController::class, 'bounce'])->whereUuid('receipt');
    Route::get('suspense', [CollectionsPageController::class, 'suspense']);
    Route::post('suspense/{suspenseItem}/allocate', [CollectionsPageController::class, 'allocate'])->whereUuid('suspenseItem');
    Route::get('refunds', [CollectionsPageController::class, 'refunds']);
    Route::post('refunds', [CollectionsPageController::class, 'requestRefund']);
    Route::post('refunds/{refund}/{decision}', [CollectionsPageController::class, 'decideRefund'])->whereUuid('refund')->whereIn('decision', ['release', 'reject']);
    Route::get('agent-cash', [CollectionsPageController::class, 'agentCash']);
    Route::post('agent-cash/deposits', [CollectionsPageController::class, 'deposit']);
    Route::get('cheques', [CollectionsPageController::class, 'cheques']);
    Route::get('dunning', [CollectionsPageController::class, 'dunning']);

    Route::get('bank', [BankPageController::class, 'index']);
    Route::post('bank', [BankPageController::class, 'store']);
    Route::get('bank/{bankAccount}', [BankPageController::class, 'show'])->whereUuid('bankAccount');
    Route::post('bank/{bankAccount}/statements', [BankPageController::class, 'import'])->whereUuid('bankAccount');
    Route::post('bank/{bankAccount}/auto-match', [BankPageController::class, 'autoMatch'])->whereUuid('bankAccount');
    Route::post('bank/lines/{statementLine}/match', [BankPageController::class, 'match'])->whereUuid('statementLine');
    Route::post('bank/lines/{statementLine}/explain', [BankPageController::class, 'explain'])->whereUuid('statementLine');

    Route::get('claims', [ClaimPageController::class, 'index']);
    Route::get('claims/create', [ClaimPageController::class, 'create']);
    Route::post('claims', [ClaimPageController::class, 'store']);
    Route::get('claims/{claim}', [ClaimPageController::class, 'show'])->whereUuid('claim');
    Route::post('claims/{claim}/reserve', [ClaimPageController::class, 'reserve'])->whereUuid('claim');
    Route::post('claims/{claim}/payments', [ClaimPageController::class, 'approvePayment'])->whereUuid('claim');
    Route::post('claims/{claim}/recover', [ClaimPageController::class, 'recover'])->whereUuid('claim');
    Route::post('claims/{claim}/{action}', [ClaimPageController::class, 'decision'])->whereUuid('claim')->whereIn('action', ['close', 'reject', 'reopen']);
    Route::post('claim-payments/{payment}/request-release', [ClaimPageController::class, 'requestRelease'])->whereUuid('payment');
    Route::post('claim-payments/{payment}/release', [ClaimPageController::class, 'release'])->whereUuid('payment');

    Route::get('commission', [CommissionPageController::class, 'index']);
    Route::post('commission/plans', [CommissionPageController::class, 'storePlan']);
    Route::post('commission/statements', [CommissionPageController::class, 'approve']);
    Route::post('commission/statements/{statement}/pay', [CommissionPageController::class, 'pay'])->whereUuid('statement');
    Route::get('commission/agents/{agent}', [CommissionPageController::class, 'statement'])->whereUuid('agent');

    Route::get('approvals', [ApprovalsPageController::class, 'index']);
    Route::post('approvals/{approval}/decide', [ApprovalsPageController::class, 'decide'])->whereUuid('approval');

    Route::get('close', [ClosePageController::class, 'index']);
    Route::post('close/periods/{period}', [ClosePageController::class, 'start'])->whereUuid('period');
    Route::post('close/periods/{period}/reopen', [ClosePageController::class, 'reopen'])->whereUuid('period');
    Route::get('close/runs/{run}', [ClosePageController::class, 'run'])->whereUuid('run');
    Route::post('close/tasks/{task}/execute', [ClosePageController::class, 'execute'])->whereUuid('task');
    Route::post('close/tasks/{task}/skip', [ClosePageController::class, 'skip'])->whereUuid('task');

    Route::get('reports', [ReportsPageController::class, 'index']);
    Route::get('reports/{report}', [ReportsPageController::class, 'show'])->where('report', '[a-z-]+');
});
