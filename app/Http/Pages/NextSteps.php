<?php

declare(strict_types=1);

namespace App\Http\Pages;

use App\Modules\Insurance\Collections\Application\RefundableQuery;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Documents\Generation\DocumentGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Flow audit: the next step offered when a piece of work is done (the brief's "after issue → Record receipt?", "after receipt → Print receipt").
 * A step is flashed as `next` ({label, url, prompt?, method?: get|post|download}) beside the status message; the shell shows it as the confirmation's action.
 */
final class NextSteps
{
    public function __construct(private readonly PermissionChecker $permissions) {}

    /**
     * The policy's installments with money outstanding, oldest due first.
     *
     * @return list<array{id: string, no: int, endorsement_no: int|null, due_date: string, outstanding_minor: int}>
     */
    public static function outstandingInstallments(string $policyId): array
    {
        $rows = [];
        foreach (DB::table('installments')->where('policy_id', $policyId)->whereRaw('amount_minor - paid_minor - cancelled_minor > 0')->orderBy('due_date')->orderBy('no')->orderBy('id')
            ->get(['id', 'no', 'endorsement_no', 'due_date', DB::raw('amount_minor - paid_minor - cancelled_minor as outstanding')]) as $row) {
            $rows[] = ['id' => (string) $row->id, 'no' => (int) $row->no, 'endorsement_no' => $row->endorsement_no === null ? null : (int) $row->endorsement_no, 'due_date' => (string) $row->due_date,
                'outstanding_minor' => (int) $row->outstanding];
        }

        return $rows;
    }

    /**
     * Policy statuses whose unpaid premium is still collected. GA-24: a cancelled policy keeps the premium earned up to the cancellation date that the
     * customer has not paid (the cancellation credits only the unearned part), so it is collected like any other.
     */
    public const COLLECTABLE_STATUSES = ['issued', 'active', 'lapsed', 'expired', 'cancelled'];

    /** Whether the user may record the premium receipt of this policy now: money is outstanding and they hold receipt.create in the policy's branch. */
    public function canRecordReceipt(string $userId, string $policyId): bool
    {
        $policy = DB::table('policies')->where('id', $policyId)->first(['entity_id', 'branch_id', 'status']);

        return $policy !== null && in_array((string) $policy->status, self::COLLECTABLE_STATUSES, true)
            && $this->permissions->has($userId, 'receipt.create', AuthorizationScope::branch((string) $policy->entity_id, (string) $policy->branch_id))
            && self::outstandingInstallments($policyId) !== [];
    }

    /** Flow fix X5: whether the user may print this receipt now (as the Documents tab offers it: not bounced, document.generate in its branch). */
    public function canPrintReceipt(string $userId, string $receiptId): bool
    {
        $receipt = DB::table('receipts')->where('id', $receiptId)->first(['entity_id', 'branch_id', 'status']);

        return $receipt !== null && $receipt->status !== 'bounced'
            && $this->permissions->has($userId, DocumentGenerator::PERMISSION, AuthorizationScope::branch((string) $receipt->entity_id, (string) $receipt->branch_id));
    }

    /** @return array{label: string, url: string, method: string}|null */
    public function afterReceipt(string $userId, string $receiptId): ?array
    {
        return $this->canPrintReceipt($userId, $receiptId) ? ['label' => 'Print receipt', 'url' => "/receipts/{$receiptId}/generated-documents", 'method' => 'post'] : null;
    }

    /**
     * GA-01 / GA-24: after a cancellation, the money still to settle with the customer — the refund owed to them (when the user may request refunds in
     * the policy's branch), else the earned premium they still owe (when the user may record receipts there). Nothing when both are settled.
     *
     * @return array{label: string, url: string, prompt: string}|null
     */
    public function afterCancel(string $userId, string $policyId): ?array
    {
        $policy = DB::table('policies')->where('id', $policyId)->first(['entity_id', 'branch_id', 'currency']);
        if ($policy === null) {
            return null;
        }
        $refundable = app(RefundableQuery::class)->availableMinor($policyId);
        if ($refundable > 0 && $this->permissions->has($userId, 'receipt.refund_request', AuthorizationScope::branch((string) $policy->entity_id, (string) $policy->branch_id))) {
            $amount = PageSupport::money($refundable, (string) $policy->currency);

            return ['label' => "Request the refund of {$amount}", 'url' => "/refunds?policy={$policyId}", 'prompt' => "The customer is owed {$amount}. Request the refund?"];
        }
        $owed = array_sum(array_column(self::outstandingInstallments($policyId), 'outstanding_minor'));
        if ($this->canRecordReceipt($userId, $policyId)) {
            $amount = PageSupport::money($owed, (string) $policy->currency);

            return ['label' => "Collect {$amount}", 'url' => "/receipts/create?policy={$policyId}", 'prompt' => "The customer still owes {$amount} of premium earned before the cancellation."];
        }

        return null;
    }

    /**
     * GA-25: after an endorsement, "Record receipt" for an increase the user may collect, with "Print endorsement" beside it (or alone) when the user may print.
     *
     * @return array{label: string, url: string, prompt?: string, method?: string, also?: array{label: string, url: string, method: string}|null}|null
     */
    public function afterEndorsement(string $userId, string $policyId, string $transactionId, bool $increased): ?array
    {
        $policy = DB::table('policies')->where('id', $policyId)->first(['entity_id', 'branch_id']);
        $print = $policy !== null && $this->permissions->has($userId, DocumentGenerator::PERMISSION, AuthorizationScope::branch((string) $policy->entity_id, (string) $policy->branch_id))
            ? ['label' => 'Print endorsement', 'url' => "/policies/{$policyId}/generated-documents?template_code=endorsement&object_id={$transactionId}", 'method' => 'post'] : null;
        if ($increased && $this->canRecordReceipt($userId, $policyId)) {
            return ['label' => 'Record receipt', 'url' => "/receipts/create?policy={$policyId}", 'prompt' => 'Collect the additional premium?', 'also' => $print];
        }

        return $print;
    }

    /** @return array{label: string, url: string, prompt: string}|null */
    public function afterIssue(string $userId, string $policyId): ?array
    {
        return $this->canRecordReceipt($userId, $policyId)
            ? ['label' => 'Record receipt', 'url' => "/receipts/create?policy={$policyId}", 'prompt' => 'Record the premium receipt?'] : null;
    }
}
