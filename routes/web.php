<?php

declare(strict_types=1);

use App\Http\Search\GlobalSearchController;
use App\Http\Search\LookupController;
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
use App\Modules\Platform\Preferences\Http\PreferencesController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/home');
Route::middleware('auth')->get('home', \App\Http\Home\HomeController::class)->name('home');

Route::middleware('auth')->get('account/security', SecurityPageController::class)->name('account.security');
Route::middleware('auth')->get('search', GlobalSearchController::class)->name('search');
Route::middleware('auth')->get('lookup/{type}', [LookupController::class, 'search'])->where('type', '[a-z]+');
Route::middleware('auth')->post('lookup/customer', [LookupController::class, 'createCustomer']);
Route::middleware('auth')->put('preferences/{key}', PreferencesController::class)->where('key', '.{1,80}')->name('preferences.update');

// Read-only accounting pages (slice 0.6). Every web route runs ResolveTenant before auth (bootstrap/app.php);
// sign-in, sign-out, password reset and two-factor routes come from Fortify (config/fortify.php).
Route::middleware(['auth', 'can:accounting.view_journals'])->prefix('accounting')->name('accounting.')->group(function (): void {
    Route::get('journals', [JournalController::class, 'index'])->name('journals.index');
    Route::get('journals/create', [ManualJournalPageController::class, 'create'])->name('journals.create');
    Route::post('journals', [ManualJournalPageController::class, 'store'])->name('journals.store');
    Route::post('journals/{journal}/approve', [ManualJournalPageController::class, 'approve'])->whereUuid('journal')->middleware('moves-money');
    Route::post('journals/{journal}/reject', [ManualJournalPageController::class, 'reject'])->whereUuid('journal');
    Route::post('journals/{journal}/reversal-requests', [ManualJournalPageController::class, 'requestReversal'])->whereUuid('journal');
    Route::post('reversal-requests/{reversalRequest}/{decision}', [ManualJournalPageController::class, 'decideReversal'])->whereUuid('reversalRequest')->whereIn('decision', ['approve', 'reject'])->middleware('moves-money');
    Route::get('journals/{journal}', \App\Http\Pages\JournalPageController::class)->name('journals.show');
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
    Route::get('policies/{policy}', [\App\Http\Pages\ObjectPageController::class, 'policy'])->whereUuid('policy');
    Route::post('policies/{policy}/issue', [PolicyPageController::class, 'issue'])->whereUuid('policy')->middleware('moves-money');
    Route::post('policies/{policy}/endorse', [PolicyPageController::class, 'endorse'])->whereUuid('policy')->middleware('moves-money');
    Route::post('policies/{policy}/cancel', [PolicyPageController::class, 'cancel'])->whereUuid('policy')->middleware('moves-money');
    Route::post('policies/{policy}/{action}', [PolicyPageController::class, 'transition'])->whereUuid('policy')->whereIn('action', ['lapse', 'reinstate', 'renew']);

    Route::get('receipts', [CollectionsPageController::class, 'index']);
    Route::get('receipts/create', [CollectionsPageController::class, 'create']);
    Route::post('receipts', [CollectionsPageController::class, 'store'])->middleware('moves-money');
    Route::get('receipts/{receipt}', [\App\Http\Pages\ObjectPageController::class, 'receipt'])->whereUuid('receipt');
    Route::get('receipts/{receipt}/allocate', [CollectionsPageController::class, 'allocateWorkbench'])->whereUuid('receipt');
    Route::post('receipts/{receipt}/bounce', [CollectionsPageController::class, 'bounce'])->whereUuid('receipt')->middleware('moves-money');
    Route::get('suspense', [CollectionsPageController::class, 'suspense']);
    Route::post('suspense/{suspenseItem}/allocate', [CollectionsPageController::class, 'allocate'])->whereUuid('suspenseItem')->middleware('moves-money');
    Route::post('suspense/{suspenseItem}/allocations', [CollectionsPageController::class, 'allocateMany'])->whereUuid('suspenseItem')->middleware('moves-money');
    Route::get('refunds', [CollectionsPageController::class, 'refunds']);
    Route::post('refunds', [CollectionsPageController::class, 'requestRefund']);
    Route::post('refunds/{refund}/{decision}', [CollectionsPageController::class, 'decideRefund'])->whereUuid('refund')->whereIn('decision', ['release', 'reject'])->middleware('moves-money');
    Route::get('agent-cash', [CollectionsPageController::class, 'agentCash']);
    Route::post('agent-cash/deposits', [CollectionsPageController::class, 'deposit'])->middleware('moves-money');
    Route::get('cheques', [CollectionsPageController::class, 'cheques']);
    Route::get('dunning', [CollectionsPageController::class, 'dunning']);

    Route::get('bank', [BankPageController::class, 'index']);
    Route::post('bank', [BankPageController::class, 'store']);
    Route::get('bank/{bankAccount}', [BankPageController::class, 'show'])->whereUuid('bankAccount');
    Route::post('bank/{bankAccount}/statements', [BankPageController::class, 'import'])->whereUuid('bankAccount');
    Route::post('bank/{bankAccount}/auto-match', [BankPageController::class, 'autoMatch'])->whereUuid('bankAccount');
    Route::post('bank/lines/{statementLine}/match', [BankPageController::class, 'match'])->whereUuid('statementLine');
    Route::post('bank/lines/{statementLine}/explain', [BankPageController::class, 'explain'])->whereUuid('statementLine');
    Route::post('bank/lines/{statementLine}/unmatch', [BankPageController::class, 'unmatch'])->whereUuid('statementLine');

    Route::get('claims', [ClaimPageController::class, 'index']);
    Route::get('claims/create', [ClaimPageController::class, 'create']);
    Route::post('claims', [ClaimPageController::class, 'store']);
    Route::get('claims/{claim}', [\App\Http\Pages\ObjectPageController::class, 'claim'])->whereUuid('claim');
    Route::post('claims/{claim}/reserve', [ClaimPageController::class, 'reserve'])->whereUuid('claim')->middleware('moves-money');
    Route::post('claims/{claim}/payments', [ClaimPageController::class, 'approvePayment'])->whereUuid('claim')->middleware('moves-money');
    Route::post('claims/{claim}/recover', [ClaimPageController::class, 'recover'])->whereUuid('claim')->middleware('moves-money');
    Route::post('claims/{claim}/{action}', [ClaimPageController::class, 'decision'])->whereUuid('claim')->whereIn('action', ['close', 'reject', 'reopen'])->middleware('moves-money');
    Route::post('claim-payments/{payment}/request-release', [ClaimPageController::class, 'requestRelease'])->whereUuid('payment');
    Route::post('claim-payments/{payment}/release', [ClaimPageController::class, 'release'])->whereUuid('payment')->middleware('moves-money');

    Route::get('commission', [CommissionPageController::class, 'index']);
    Route::post('commission/plans', [CommissionPageController::class, 'storePlan']);
    Route::post('commission/statements', [CommissionPageController::class, 'approve']);
    Route::post('commission/statements/{statement}/pay', [CommissionPageController::class, 'pay'])->whereUuid('statement')->middleware('moves-money');
    Route::get('commission/agents/{agent}', [CommissionPageController::class, 'statement'])->whereUuid('agent');

    Route::get('approvals', [ApprovalsPageController::class, 'index']);
    Route::post('approvals/{approval}/decide', [ApprovalsPageController::class, 'decide'])->whereUuid('approval')->middleware('moves-money');

    Route::get('close', [ClosePageController::class, 'index']);
    Route::post('close/periods/{period}', [ClosePageController::class, 'start'])->whereUuid('period');
    Route::post('close/periods/{period}/reopen', [ClosePageController::class, 'reopen'])->whereUuid('period');
    Route::get('close/runs/{run}', [ClosePageController::class, 'run'])->whereUuid('run');
    Route::post('close/tasks/{task}/execute', [ClosePageController::class, 'execute'])->whereUuid('task')->middleware('moves-money');
    Route::post('close/tasks/{task}/skip', [ClosePageController::class, 'skip'])->whereUuid('task');

    Route::get('reports', [ReportsPageController::class, 'index']);
    Route::get('reports/{report}', [ReportsPageController::class, 'show'])->where('report', '[a-z-]+');
});
