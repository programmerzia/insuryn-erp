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
use App\Modules\Insurance\Claims\Application\ClaimsReconciler;
use App\Modules\Insurance\Collections\Application\Reconciliation\PremiumReconciler;
use App\Modules\Insurance\Collections\Application\Reconciliation\SuspenseReconciler;
use App\Modules\Insurance\Collections\Domain\Events\ReceiptAllocated;
use App\Modules\Insurance\Collections\Domain\Events\ReceiptAllocationReversed;
use App\Modules\Insurance\Commission\Application\ClawBackCommissionOnReversal;
use App\Modules\Insurance\Commission\Application\CommissionReconciler;
use App\Modules\Insurance\Commission\Application\ClawBackCommissionOnCancellation;
use App\Modules\Insurance\Commission\Application\EarnCommissionOnAllocation;
use App\Modules\Insurance\Commission\Application\EarnCommissionOnIssue;
use App\Modules\Insurance\Policy\Domain\Events\PolicyIssued;
use App\Modules\Insurance\Policy\Application\PremiumEarning\CatchUpEarningOnCancellation;
use App\Modules\Insurance\Policy\Domain\Events\PolicyCancelled;
use App\Modules\Insurance\Collections\Application\Documents\ReceiptDocumentData;
use App\Modules\Insurance\Policy\Application\Documents\EndorsementDocumentData;
use App\Modules\Insurance\Policy\Application\Documents\PolicyScheduleDocumentData;
use App\Modules\Platform\Approvals\ApprovalHandlerRegistry;
use App\Modules\Platform\Documents\Generation\DocumentDataProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Wires the Insurance context's in-process domain event listeners (all run inside the emitting transaction) its subledger reconcilers and close task checks. */
final class InsuranceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([PremiumReconciler::class, SuspenseReconciler::class, CommissionReconciler::class, ClaimsReconciler::class], SubledgerReconciler::class);
        $this->app->tag([PremiumEarningCloseCheck::class, SuspenseReviewCloseCheck::class], CloseTaskCheck::class);
        // Slice 2.1b (D-55): claim payments and refunds still waiting hold the period's lock.
        $this->app->tag([\App\Modules\Insurance\Claims\Application\ClaimPaymentsPendingAtClose::class, \App\Modules\Insurance\Collections\Application\RefundsPendingAtClose::class],
            \App\Modules\Accounting\Application\Contracts\PendingCloseDocuments::class);
        // Slice R8: printable documents. A new document for an object is one DocumentDataProvider class tagged here.
        $this->app->tag([PolicyScheduleDocumentData::class, EndorsementDocumentData::class, ReceiptDocumentData::class,
            \App\Modules\Insurance\Quotation\Application\Documents\QuotationDocumentData::class, \App\Modules\Insurance\CoverNote\Application\Documents\CoverNoteDocumentData::class,
            \App\Modules\Insurance\Renewal\Application\Documents\RenewalNoticeDocumentData::class, // slice R9: renewal notice
            // Gap audit GA-41: the claim acknowledgement and the discharge voucher.
            \App\Modules\Insurance\Claims\Application\Documents\ClaimAcknowledgementDocumentData::class, \App\Modules\Insurance\Claims\Application\Documents\DischargeVoucherDocumentData::class], DocumentDataProvider::class);
    }

    public function boot(ApprovalHandlerRegistry $approvals): void
    {
        $approvals->register('claim_payment', ClaimPaymentApprovalHandler::class);
        $approvals->register('claim_payment_release', ClaimPaymentReleaseApprovalHandler::class);
        $approvals->register('claim_reopen', ClaimReopenApprovalHandler::class);
        $approvals->register('proposal_referral', \App\Modules\Insurance\Underwriting\Application\ProposalReferralApprovalHandler::class); // slice R5
        Event::listen(PolicyCancelled::class, [CatchUpEarningOnCancellation::class, 'handle']);
        Event::listen(PolicyCancelled::class, [ClawBackCommissionOnCancellation::class, 'handle']);
        Event::listen(ReceiptAllocated::class, [EarnCommissionOnAllocation::class, 'handle']);
        Event::listen(PolicyIssued::class, [EarnCommissionOnIssue::class, 'handle']);
        Event::listen(\App\Modules\Insurance\Policy\Domain\Events\PolicyRenewed::class, [\App\Modules\Insurance\Renewal\Application\ExpiryRegister::class, 'markRenewed']); // slice R9
        Event::listen(ReceiptAllocationReversed::class, [ClawBackCommissionOnReversal::class, 'handle']);
    }
}
