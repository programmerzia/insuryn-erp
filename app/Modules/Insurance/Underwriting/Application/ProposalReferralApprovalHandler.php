<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Underwriting\Application;

use App\Modules\Platform\Approvals\ApprovalHandler;
use App\Modules\Platform\Approvals\DescribesApprovalSubject;
use Illuminate\Support\Facades\DB;

/** Completes an underwriting referral (approval object type `proposal_referral`, slice R5). */
final class ProposalReferralApprovalHandler implements ApprovalHandler, DescribesApprovalSubject
{
    public function __construct(private readonly UnderwritingDecisions $decisions) {}

    public function approved(string $objectId, string $finalApproverId, array $context): void
    {
        $this->decisions->completeApproval($objectId, $finalApproverId);
    }

    public function rejected(string $objectId, string $deciderId, string $reason, array $context): void
    {
        $this->decisions->completeRejection($objectId, $deciderId, $reason);
    }

    /** @return array{title: string, amount_minor: int|null, currency: string|null, link: string|null} */
    public function describe(string $objectId): array
    {
        $row = DB::table('proposals')->where('id', $objectId)->first(['id', 'number', 'sum_insured_minor', 'currency']);

        return ['title' => 'Underwriting referral '.($row->number ?? ''), 'amount_minor' => $row === null ? null : (int) $row->sum_insured_minor,
            'currency' => $row === null ? null : (string) $row->currency, 'link' => $row === null ? null : "/proposals/{$row->id}"];
    }
}
