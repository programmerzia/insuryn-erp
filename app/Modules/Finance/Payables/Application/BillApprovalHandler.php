<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Application;

use App\Modules\Finance\Payables\Domain\Models\ApBill;
use App\Modules\Platform\Approvals\ApprovalHandler;
use App\Modules\Platform\Approvals\DescribesApprovalSubject;
use Illuminate\Support\Facades\DB;

/** Completes a supplier bill approval (object type `ap_bill`): the last step posts the bill; a rejection returns it to its maker as a draft. */
final class BillApprovalHandler implements ApprovalHandler, DescribesApprovalSubject
{
    public function __construct(private readonly BillService $bills) {}

    public function approved(string $objectId, string $finalApproverId, array $context): void
    {
        $this->bills->completeApproval($objectId, $finalApproverId);
    }

    public function rejected(string $objectId, string $deciderId, string $reason, array $context): void
    {
        $this->bills->returnToDraft($objectId, $deciderId, $reason);
    }

    /** @return array{title: string, amount_minor: int|null, currency: string|null, link: string|null} */
    public function describe(string $objectId): array
    {
        $bill = ApBill::query()->find($objectId);
        $supplier = $bill === null ? null : DB::table('suppliers as s')->join('parties as p', 'p.id', '=', 's.party_id')->where('s.id', $bill->supplier_id)->value('p.display_name');

        return ['title' => 'Supplier bill '.($bill->number ?? '').($supplier === null ? '' : " · {$supplier}"), 'amount_minor' => $bill?->payable_minor,
            'currency' => $bill?->currency, 'link' => $bill === null ? null : "/payables/bills/{$bill->id}"];
    }
}
