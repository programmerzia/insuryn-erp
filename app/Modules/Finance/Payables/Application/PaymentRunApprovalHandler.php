<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Application;

use App\Modules\Finance\Payables\Domain\Models\PaymentRun;
use App\Modules\Platform\Approvals\ApprovalHandler;
use App\Modules\Platform\Approvals\DescribesApprovalSubject;

/** Completes a payment run approval (object type `payment_run`): approved runs wait for release; a rejection returns the run to draft. */
final class PaymentRunApprovalHandler implements ApprovalHandler, DescribesApprovalSubject
{
    public function __construct(private readonly PaymentRunService $runs) {}

    public function approved(string $objectId, string $finalApproverId, array $context): void
    {
        $this->runs->completeApproval($objectId, $finalApproverId);
    }

    public function rejected(string $objectId, string $deciderId, string $reason, array $context): void
    {
        $this->runs->returnToDraft($objectId, $deciderId, $reason);
    }

    /** @return array{title: string, amount_minor: int|null, currency: string|null, link: string|null} */
    public function describe(string $objectId): array
    {
        $run = PaymentRun::query()->find($objectId);

        return ['title' => 'Payment run '.($run->number ?? '').($run === null ? '' : " · {$run->item_count} bills"), 'amount_minor' => $run?->total_minor,
            'currency' => $run?->currency, 'link' => $run === null ? null : "/payables/payment-runs/{$run->id}"];
    }
}
