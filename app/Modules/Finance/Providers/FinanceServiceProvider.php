<?php

declare(strict_types=1);

namespace App\Modules\Finance\Providers;

use App\Modules\Accounting\Application\Contracts\AccountUsage;
use App\Modules\Accounting\Application\Contracts\CloseTaskCheck;
use App\Modules\Accounting\Application\Contracts\ConditionalCloseTask;
use App\Modules\Finance\Bank\Application\BankAccountGlUsage;
use App\Modules\Finance\Bank\Application\BankReconciliationCloseCheck;
use App\Modules\Finance\FixedAssets\Application\DepreciationCloseTask;
use App\Modules\Finance\FixedAssets\Application\FixedAssetReconciliationCloseTask;
use Illuminate\Support\ServiceProvider;

/** Wires the Finance context into the kernel's extension points (close task checks, accounts in use). */
final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([BankReconciliationCloseCheck::class], CloseTaskCheck::class);
        $this->app->tag([BankAccountGlUsage::class], AccountUsage::class);
        // Slice 2.3: the AP subledger against accounts payable per supplier (D-103), in the close as task ap_reconciliation.
        $this->app->tag([\App\Modules\Finance\Payables\Application\PayablesReconciler::class], \App\Modules\Accounting\Application\Contracts\SubledgerReconciler::class);
        // Design addendum v2 §B.7 fixed assets: the depreciation and register reconciliation close tasks, for entities with a register (D-115).
        $this->app->tag([DepreciationCloseTask::class, FixedAssetReconciliationCloseTask::class], CloseTaskCheck::class);
        $this->app->tag([DepreciationCloseTask::class, FixedAssetReconciliationCloseTask::class], \App\Modules\Accounting\Application\Contracts\CloseTaskContributor::class);
    }

    public function boot(\App\Modules\Platform\Approvals\ApprovalHandlerRegistry $approvals): void
    {
        // Slices 2.3/2.4: supplier bills and payment runs through the approval engine when an approval limit applies.
        $approvals->register('ap_bill', \App\Modules\Finance\Payables\Application\BillApprovalHandler::class);
        $approvals->register('payment_run', \App\Modules\Finance\Payables\Application\PaymentRunApprovalHandler::class);
    }
}
