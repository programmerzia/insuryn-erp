<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application;

use App\Modules\Platform\Approvals\ApprovalHandler;
use App\Modules\Platform\Approvals\DescribesApprovalSubject;
use Illuminate\Support\Facades\DB;

/** Completes a claim payment release approval (object type `claim_payment_release`). */
final class ClaimPaymentReleaseApprovalHandler implements ApprovalHandler, DescribesApprovalSubject
{
    public function __construct(private readonly ClaimPaymentService $payments) {}

    public function approved(string $objectId, string $finalApproverId, array $context): void
    {
        $this->payments->completeRelease($objectId);
    }

    public function rejected(string $objectId, string $deciderId, string $reason, array $context): void
    {
        $this->payments->rejectRelease($objectId, $deciderId, $reason);
    }

    /** @return array{title: string, amount_minor: int|null, currency: string|null, link: string|null} */
    public function describe(string $objectId): array
    {
        $row = DB::table('claim_payments as p')->join('claims as c', 'c.id', '=', 'p.claim_id')->where('p.id', $objectId)->first(['c.id', 'c.number', 'p.amount_minor', 'p.currency']);

        return ['title' => 'Claim payment release '.($row->number ?? ''), 'amount_minor' => $row === null ? null : (int) $row->amount_minor,
            'currency' => $row === null ? null : (string) $row->currency, 'link' => $row === null ? null : "/claims/{$row->id}"];
    }
}
