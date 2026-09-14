<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Application;

use App\Modules\Accounting\Application\Queries\EventLinesPreview;
use App\Modules\Insurance\Claims\Domain\Models\Claim;
use App\Modules\Insurance\Claims\Domain\Models\ClaimPayment;
use App\Modules\Platform\Approvals\ApprovalHandler;
use App\Modules\Platform\Approvals\DescribesApprovalSubject;
use App\Modules\Platform\Approvals\PreviewsApprovalSubject;
use Illuminate\Support\Facades\DB;

/** Completes a claim payment release approval (object type `claim_payment_release`). */
final class ClaimPaymentReleaseApprovalHandler implements ApprovalHandler, DescribesApprovalSubject, PreviewsApprovalSubject
{
    public function __construct(private readonly ClaimPaymentService $payments, private readonly ClaimAccountingEvents $events, private readonly EventLinesPreview $lines) {}

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

    /** Gap fixes W7 (GA-04 remainder): the claim, the payee, the date and the journal lines the final approval posts (CLAIM_PAID). */
    public function preview(string $objectId): array
    {
        $payment = ClaimPayment::query()->find($objectId);
        $claim = $payment === null ? null : Claim::query()->find($payment->claim_id);
        if ($payment === null || $claim === null) {
            return ['link_label' => 'Open the claim', 'details' => [], 'lines' => [], 'posts_on_final_step' => true];
        }
        $on = $payment->paid_on ?? app(\App\Modules\Platform\Tenancy\BusinessClock::class)->today($claim->entity_id);

        return ['link_label' => 'Open the claim', 'details' => [
            ['label' => 'Claim', 'value' => $claim->number],
            ['label' => 'Payee', 'value' => (string) DB::table('parties')->where('id', $payment->payee_party_id)->value('display_name')],
            ['label' => 'Paid on', 'value' => $on->toDateString(), 'date' => true],
            ...($payment->bank_account_id === null ? [] : [['label' => 'Pay from', 'value' => (string) DB::table('bank_accounts')->where('id', $payment->bank_account_id)->selectRaw("concat(bank_name, ' ', account_no_masked) as name")->value('name')]]),
        ], 'lines' => $this->lines->lines($claim->entity_id, 'CLAIM_PAID', $on, $claim->currency, $this->events->paidPayload($claim, $payment), ClaimAccountingEvents::dimensions($claim)), 'posts_on_final_step' => true];
    }
}
