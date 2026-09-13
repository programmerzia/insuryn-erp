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
use App\Modules\Platform\Administration\Http\RolesPageController;
use App\Modules\Platform\Administration\Http\UsersPageController;
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
Route::middleware('auth')->get('help/{module}', \App\Http\Help\HelpController::class)->where('module', '[a-z]+');
Route::middleware('auth')->put('preferences/{key}', PreferencesController::class)->where('key', '.{1,80}')->name('preferences.update');

// Distribution (slice D8): producers, hierarchy, schemes, statement run, targets. Controllers authorize per area; money movements get the journal preview.
Route::middleware('auth')->prefix('distribution')->group(function (): void {
    Route::get('producers', [\App\Http\Distribution\ProducersPageController::class, 'index']);
    Route::post('producers', [\App\Http\Distribution\ProducersPageController::class, 'store']);
    Route::get('producers/{producer}', [\App\Http\Distribution\ProducersPageController::class, 'show'])->whereUuid('producer');
    Route::post('producers/{producer}/licences', [\App\Http\Distribution\ProducersPageController::class, 'storeLicence'])->whereUuid('producer');
    Route::post('producers/{producer}/advances', [\App\Http\Distribution\ProducersPageController::class, 'issueAdvance'])->whereUuid('producer')->middleware('moves-money');
    Route::post('producers/{producer}/status', [\App\Http\Distribution\ProducersPageController::class, 'updateStatus'])->whereUuid('producer');
    // F6: the agency (IDRA) register download from the producers queue, the same export as GET /api/distribution/licences/register.
    Route::get('licences/register', [\App\Modules\Distribution\Http\Controllers\LicenceController::class, 'register']);
    Route::post('licences/{licence}/{action}', [\App\Http\Distribution\ProducersPageController::class, 'licenceStatus'])->whereUuid('licence')->whereIn('action', ['suspend', 'revoke', 'reinstate']);
    Route::get('hierarchy', [\App\Modules\Distribution\Http\Controllers\HierarchyPageController::class, 'index']);
    Route::post('hierarchy/moves', [\App\Modules\Distribution\Http\Controllers\HierarchyPageController::class, 'move']);
    Route::get('schemes', [\App\Modules\Distribution\Http\Controllers\SchemesPageController::class, 'index']);
    Route::post('schemes', [\App\Modules\Distribution\Http\Controllers\SchemesPageController::class, 'store']);
    Route::get('schemes/{scheme}', [\App\Modules\Distribution\Http\Controllers\SchemesPageController::class, 'show'])->whereUuid('scheme');
    Route::put('schemes/{scheme}/compliance-profile', [\App\Modules\Distribution\Http\Controllers\SchemesPageController::class, 'updateProfile'])->whereUuid('scheme');
    Route::put('schemes/{scheme}/levels', [\App\Modules\Distribution\Http\Controllers\SchemesPageController::class, 'defineLevels'])->whereUuid('scheme');
    Route::post('schemes/{scheme}/rules', [\App\Modules\Distribution\Http\Controllers\SchemesPageController::class, 'storeRule'])->whereUuid('scheme');
    Route::post('rules/{rule}/end', [\App\Modules\Distribution\Http\Controllers\SchemesPageController::class, 'endRule'])->whereUuid('rule');
    Route::get('statements', [\App\Modules\Insurance\Commission\Http\Controllers\StatementWorkbenchController::class, 'index']);
    Route::post('statements/prepare', [\App\Modules\Insurance\Commission\Http\Controllers\StatementWorkbenchController::class, 'prepare']);
    Route::post('statements/incentives', [\App\Modules\Insurance\Commission\Http\Controllers\StatementWorkbenchController::class, 'runIncentives']);
    Route::post('statements/{statement}/approve', [\App\Modules\Insurance\Commission\Http\Controllers\StatementWorkbenchController::class, 'approve'])->whereUuid('statement')->middleware('moves-money');
    Route::post('statements/{statement}/pay', [\App\Modules\Insurance\Commission\Http\Controllers\StatementWorkbenchController::class, 'pay'])->whereUuid('statement')->middleware('moves-money');
    Route::get('targets', [\App\Http\Distribution\TargetsPageController::class, 'index']);
    Route::put('targets', [\App\Http\Distribution\TargetsPageController::class, 'save']);
});

// Setup wizard (session S1): steps authorize by the permission that owns their data; reopened from Admin → Setup.
Route::middleware('auth')->prefix('setup')->group(function (): void {
    Route::get('/', [\App\Http\Setup\SetupPageController::class, 'show']);
    Route::post('company', [\App\Http\Setup\SetupPageController::class, 'company']);
    Route::post('fiscal-year', [\App\Http\Setup\SetupPageController::class, 'fiscalYear']);
    Route::post('chart-of-accounts', [\App\Http\Setup\SetupPageController::class, 'chartOfAccounts']);
    Route::post('product', [\App\Http\Setup\SetupPageController::class, 'product']);
    Route::post('users', [\App\Http\Setup\SetupPageController::class, 'users']);
    Route::post('approvals', [\App\Http\Setup\SetupPageController::class, 'approvals']);
    Route::post('finish', [\App\Http\Setup\SetupPageController::class, 'finish']);
});

// Tariff editor (Phase 3 slice R10a): rating plans, their tables, rows and steps, and duties. RatingPlanService / DutyBook authorize every change.
Route::middleware('auth')->prefix('rating')->group(function (): void {
    $tariffs = \App\Modules\Insurance\Rating\Http\Controllers\TariffsPageController::class;
    Route::get('plans', [$tariffs, 'index']);
    Route::post('plans', [$tariffs, 'store']);
    Route::get('plans/{plan}', [$tariffs, 'show'])->whereUuid('plan');
    Route::put('plans/{plan}', [$tariffs, 'update'])->whereUuid('plan');
    Route::delete('plans/{plan}', [$tariffs, 'destroy'])->whereUuid('plan');
    Route::post('plans/{plan}/versions', [$tariffs, 'newVersion'])->whereUuid('plan');
    Route::post('plans/{plan}/approve', [$tariffs, 'approve'])->whereUuid('plan');
    Route::post('plans/{plan}/activate', [$tariffs, 'activate'])->whereUuid('plan');
    Route::post('plans/{plan}/retire', [$tariffs, 'retire'])->whereUuid('plan');
    Route::post('plans/{plan}/tables', [$tariffs, 'storeTable'])->whereUuid('plan');
    Route::delete('plans/{plan}/tables/{table}', [$tariffs, 'destroyTable'])->whereUuid('plan')->where('table', '[a-z][a-z0-9_]{0,63}');
    Route::put('plans/{plan}/tables/{table}/rows', [$tariffs, 'replaceRows'])->whereUuid('plan')->where('table', '[a-z][a-z0-9_]{0,63}');
    Route::post('plans/{plan}/steps', [$tariffs, 'storeStep'])->whereUuid('plan');
    Route::put('plans/{plan}/steps/{step}', [$tariffs, 'updateStep'])->whereUuid('plan')->where('step', '[a-z][a-z0-9_]{0,63}');
    Route::delete('plans/{plan}/steps/{step}', [$tariffs, 'destroyStep'])->whereUuid('plan')->where('step', '[a-z][a-z0-9_]{0,63}');
    Route::post('duties', [\App\Modules\Insurance\Rating\Http\Controllers\DutiesPageController::class, 'store']);
    Route::post('duties/{duty}/end', [\App\Modules\Insurance\Rating\Http\Controllers\DutiesPageController::class, 'end'])->whereUuid('duty');
});

// Administration (phase 2.0): users need platform.manage_users, roles platform.manage_roles; the controllers authorize.
Route::middleware('auth')->prefix('admin')->group(function (): void {
    Route::get('users', [UsersPageController::class, 'index']);
    Route::post('users', [UsersPageController::class, 'store']);
    Route::get('users/{user}', [UsersPageController::class, 'show'])->whereUuid('user');
    Route::post('users/{user}/roles', [UsersPageController::class, 'assign'])->whereUuid('user');
    Route::post('users/{user}/roles/remove', [UsersPageController::class, 'revoke'])->whereUuid('user');
    Route::post('users/{user}/deactivate', [UsersPageController::class, 'deactivate'])->whereUuid('user');
    Route::post('users/{user}/reactivate', [UsersPageController::class, 'reactivate'])->whereUuid('user');
    Route::post('users/{user}/invitation', [UsersPageController::class, 'resendInvitation'])->whereUuid('user');
    Route::get('roles', [RolesPageController::class, 'index']);
    Route::post('roles', [RolesPageController::class, 'store']);
    Route::get('roles/{role}', [RolesPageController::class, 'show'])->whereUuid('role');
    Route::put('roles/{role}', [RolesPageController::class, 'update'])->whereUuid('role');
    Route::delete('roles/{role}', [RolesPageController::class, 'destroy'])->whereUuid('role');
    // Fix F3: approval limits need platform.manage_approvals.
    Route::get('approval-limits', [\App\Modules\Platform\Approvals\Http\ApprovalLimitsPageController::class, 'index']);
    Route::post('approval-limits', [\App\Modules\Platform\Approvals\Http\ApprovalLimitsPageController::class, 'store']);
    Route::put('approval-limits/{policy}', [\App\Modules\Platform\Approvals\Http\ApprovalLimitsPageController::class, 'update'])->whereUuid('policy');
    Route::post('approval-limits/{policy}/end', [\App\Modules\Platform\Approvals\Http\ApprovalLimitsPageController::class, 'end'])->whereUuid('policy');
    // Phase 3 R5: underwriting limits need underwriting.manage_limits.
    Route::get('underwriting-limits', [\App\Modules\Insurance\Underwriting\Http\Controllers\UnderwritingLimitsPageController::class, 'index']);
    Route::post('underwriting-limits', [\App\Modules\Insurance\Underwriting\Http\Controllers\UnderwritingLimitsPageController::class, 'store']);
    Route::post('underwriting-limits/{limit}/end', [\App\Modules\Insurance\Underwriting\Http\Controllers\UnderwritingLimitsPageController::class, 'end'])->whereUuid('limit');
});

// Documents (slice R8): template editor with live preview; everything needs document.manage_templates (the controller authorizes).
Route::middleware('auth')->prefix('documents/templates')->group(function (): void {
    Route::get('/', [\App\Modules\Platform\Documents\Http\DocumentTemplatesPageController::class, 'index']);
    Route::post('/', [\App\Modules\Platform\Documents\Http\DocumentTemplatesPageController::class, 'store']);
    Route::post('preview', [\App\Modules\Platform\Documents\Http\DocumentTemplatesPageController::class, 'preview']);
    Route::get('{template}', [\App\Modules\Platform\Documents\Http\DocumentTemplatesPageController::class, 'show'])->whereUuid('template');
    Route::put('{template}', [\App\Modules\Platform\Documents\Http\DocumentTemplatesPageController::class, 'update'])->whereUuid('template');
    Route::post('{template}/activate', [\App\Modules\Platform\Documents\Http\DocumentTemplatesPageController::class, 'activate'])->whereUuid('template');
    Route::get('{template}/preview', [\App\Modules\Platform\Documents\Http\DocumentTemplatesPageController::class, 'previewSaved'])->whereUuid('template');
});

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

// Fix F4: account role → account mappings need accounting.manage_coa (the controller authorizes per entity).
Route::middleware('auth')->prefix('accounting')->group(function (): void {
    Route::get('account-roles', [\App\Modules\Accounting\Http\Controllers\AccountRolesPageController::class, 'index']);
    Route::post('account-roles', [\App\Modules\Accounting\Http\Controllers\AccountRolesPageController::class, 'map']);
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
    // Phase 3 R4: quotations (quote workbench, live rating, issue, decline).
    Route::get('quotations', [\App\Modules\Insurance\Quotation\Http\Controllers\QuotationPageController::class, 'index']);
    Route::get('quotations/create', [\App\Modules\Insurance\Quotation\Http\Controllers\QuotationPageController::class, 'create']);
    Route::post('quotations/rate', [\App\Modules\Insurance\Quotation\Http\Controllers\QuotationPageController::class, 'rate']);
    Route::post('quotations', [\App\Modules\Insurance\Quotation\Http\Controllers\QuotationPageController::class, 'store']);
    Route::get('quotations/{quotation}', [\App\Modules\Insurance\Quotation\Http\Controllers\QuotationPageController::class, 'show'])->whereUuid('quotation');
    Route::put('quotations/{quotation}', [\App\Modules\Insurance\Quotation\Http\Controllers\QuotationPageController::class, 'update'])->whereUuid('quotation');
    Route::post('quotations/{quotation}/issue', [\App\Modules\Insurance\Quotation\Http\Controllers\QuotationPageController::class, 'issue'])->whereUuid('quotation');
    Route::post('quotations/{quotation}/decline', [\App\Modules\Insurance\Quotation\Http\Controllers\QuotationPageController::class, 'decline'])->whereUuid('quotation');
    // Phase 3 R5: proposals, KYC, documents, underwriting referrals.
    Route::post('quotations/{quotation}/proposal', [\App\Modules\Insurance\Underwriting\Http\Controllers\ProposalPageController::class, 'store'])->whereUuid('quotation');
    Route::get('proposals/{proposal}', [\App\Modules\Insurance\Underwriting\Http\Controllers\ProposalPageController::class, 'show'])->whereUuid('proposal');
    Route::post('proposals/{proposal}/kyc', [\App\Modules\Insurance\Underwriting\Http\Controllers\ProposalPageController::class, 'kyc'])->whereUuid('proposal');
    Route::post('proposals/{proposal}/submit', [\App\Modules\Insurance\Underwriting\Http\Controllers\ProposalPageController::class, 'submit'])->whereUuid('proposal');
    Route::post('proposals/{proposal}/documents', [\App\Modules\Insurance\Underwriting\Http\Controllers\ProposalPageController::class, 'attachDocument'])->whereUuid('proposal');
    Route::get('proposals/{proposal}/documents/{document}', [\App\Modules\Insurance\Underwriting\Http\Controllers\ProposalPageController::class, 'downloadDocument'])->whereUuid(['proposal', 'document']);
    // Phase 3 R6: cover notes.
    Route::post('proposals/{proposal}/cover-notes', [\App\Modules\Insurance\CoverNote\Http\Controllers\CoverNotesPageController::class, 'store'])->whereUuid('proposal');
    Route::get('cover-notes', [\App\Modules\Insurance\CoverNote\Http\Controllers\CoverNotesPageController::class, 'index']);
    Route::post('cover-notes/{coverNote}/cancel', [\App\Modules\Insurance\CoverNote\Http\Controllers\CoverNotesPageController::class, 'cancel'])->whereUuid('coverNote');
    Route::get('underwriting/referrals', [\App\Modules\Insurance\Underwriting\Http\Controllers\ReferralsPageController::class, 'index']);
    Route::post('underwriting/referrals/{proposal}/decide', [\App\Modules\Insurance\Underwriting\Http\Controllers\ReferralsPageController::class, 'decide'])->whereUuid('proposal');

    Route::get('policies', [PolicyPageController::class, 'index']);
    Route::get('policies/create', [PolicyPageController::class, 'create']);
    Route::post('policies', [PolicyPageController::class, 'store']);
    Route::get('policies/{policy}', [\App\Http\Pages\ObjectPageController::class, 'policy'])->whereUuid('policy');
    Route::post('policies/{policy}/documents', [PolicyPageController::class, 'attachDocument'])->whereUuid('policy');
    Route::get('policies/{policy}/documents/{document}', [PolicyPageController::class, 'downloadDocument'])->whereUuid(['policy', 'document']);
    Route::post('policies/{policy}/generated-documents', [\App\Http\Documents\GeneratedDocumentsController::class, 'policy'])->whereUuid('policy'); // slice R8
    Route::post('policies/{policy}/issue', [PolicyPageController::class, 'issue'])->whereUuid('policy')->middleware('moves-money');
    Route::post('policies/{policy}/endorse', [PolicyPageController::class, 'endorse'])->whereUuid('policy')->middleware('moves-money');
    Route::post('policies/{policy}/cancel', [PolicyPageController::class, 'cancel'])->whereUuid('policy')->middleware('moves-money');
    Route::post('policies/{policy}/{action}', [PolicyPageController::class, 'transition'])->whereUuid('policy')->whereIn('action', ['lapse', 'reinstate', 'renew']);

    Route::get('receipts', [CollectionsPageController::class, 'index']);
    Route::get('receipts/create', [CollectionsPageController::class, 'create']);
    Route::post('receipts', [CollectionsPageController::class, 'store'])->middleware('moves-money');
    Route::get('receipts/{receipt}', [\App\Http\Pages\ObjectPageController::class, 'receipt'])->whereUuid('receipt');
    Route::post('receipts/{receipt}/documents', [CollectionsPageController::class, 'attachDocument'])->whereUuid('receipt');
    Route::get('receipts/{receipt}/documents/{document}', [CollectionsPageController::class, 'downloadDocument'])->whereUuid(['receipt', 'document']);
    Route::post('receipts/{receipt}/generated-documents', [\App\Http\Documents\GeneratedDocumentsController::class, 'receipt'])->whereUuid('receipt'); // slice R8
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
    Route::post('claims/{claim}/documents', [ClaimPageController::class, 'attachDocument'])->whereUuid('claim');
    Route::get('claims/{claim}/documents/{document}', [ClaimPageController::class, 'downloadDocument'])->whereUuid(['claim', 'document']);
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
