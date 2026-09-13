<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application;

use App\Modules\Platform\Approvals\ApprovalHandler;
use App\Modules\Platform\Approvals\DescribesApprovalSubject;
use Illuminate\Support\Facades\DB;

/** Completes a claim reopen approval (object type `claim_reopen`); a rejection leaves the claim closed. */
final class ClaimReopenApprovalHandler implements ApprovalHandler, DescribesApprovalSubject
{
    public function __construct(private readonly ClaimService $claims) {}

    public function approved(string $objectId, string $finalApproverId, array $context): void
    {
        $this->claims->completeReopen($objectId, (string) ($context['reason'] ?? ''), $finalApproverId);
    }

    public function rejected(string $objectId, string $deciderId, string $reason, array $context): void {}

    /** @return array{title: string, amount_minor: int|null, currency: string|null, link: string|null} */
    public function describe(string $objectId): array
    {
        return ['title' => 'Reopen claim '.(string) DB::table('claims')->where('id', $objectId)->value('number'), 'amount_minor' => null, 'currency' => null, 'link' => "/claims/{$objectId}"];
    }
}
