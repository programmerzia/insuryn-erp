<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Providers;

use App\Modules\Accounting\Application\Contracts\CloseTaskCheck;
use App\Modules\Accounting\Application\Contracts\SubledgerReconciler;
use App\Modules\Insurance\Collections\Application\SuspenseReviewCloseCheck;
use App\Modules\Insurance\Policy\Application\PremiumEarning\PremiumEarningCloseCheck;
use App\Modules\Insurance\Claims\Application\ClaimPaymentApprovalHandler;
use App\Modules\Insurance\Claims\Application\ClaimPaymentReleaseApprovalHandler;
use App\Modules\Insurance\Claims\Application\ClaimReopenApprovalHandler;
use App\Modules\Insurance\Collections\Application\Reconciliation\PremiumReconciler;
use App\Modules\Insurance\Collections\Application\Reconciliation\SuspenseReconciler;
use App\Modules\Insurance\Collections\Domain\Events\ReceiptAllocated;
use App\Modules\Insurance\Commission\Application\CommissionReconciler;
use App\Modules\Insurance\Commission\Application\ClawBackCommissionOnCancellation;
use App\Modules\Insurance\Commission\Application\EarnCommissionOnAllocation;
use App\Modules\Insurance\Policy\Application\PremiumEarning\CatchUpEarningOnCancellation;
use App\Modules\Insurance\Policy\Domain\Events\PolicyCancelled;
use App\Modules\Platform\Approvals\ApprovalHandlerRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Wires the Insurance context's in-process domain event listeners (all run inside the emitting transaction) its subledger reconcilers and close task checks. */
final class InsuranceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([PremiumReconciler::class, SuspenseReconciler::class, CommissionReconciler::class], SubledgerReconciler::class);
        $this->app->tag([PremiumEarningCloseCheck::class, SuspenseReviewCloseCheck::class], CloseTaskCheck::class);
    }

    public function boot(ApprovalHandlerRegistry $approvals): void
    {
        $approvals->register('claim_payment', ClaimPaymentApprovalHandler::class);
        $approvals->register('claim_payment_release', ClaimPaymentReleaseApprovalHandler::class);
        $approvals->register('claim_reopen', ClaimReopenApprovalHandler::class);
        Event::listen(PolicyCancelled::class, [CatchUpEarningOnCancellation::class, 'handle']);
        Event::listen(PolicyCancelled::class, [ClawBackCommissionOnCancellation::class, 'handle']);
        Event::listen(ReceiptAllocated::class, [EarnCommissionOnAllocation::class, 'handle']);
    }
}
