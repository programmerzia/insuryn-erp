<?php

declare(strict_types=1);

namespace App\Http\Pages;

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
     * @return list<array{id: string, no: int, due_date: string, outstanding_minor: int}>
     */
    public static function outstandingInstallments(string $policyId): array
    {
        $rows = [];
        foreach (DB::table('installments')->where('policy_id', $policyId)->whereRaw('amount_minor - paid_minor - cancelled_minor > 0')->orderBy('due_date')->orderBy('no')->orderBy('id')
            ->get(['id', 'no', 'due_date', DB::raw('amount_minor - paid_minor - cancelled_minor as outstanding')]) as $row) {
            $rows[] = ['id' => (string) $row->id, 'no' => (int) $row->no, 'due_date' => (string) $row->due_date, 'outstanding_minor' => (int) $row->outstanding];
        }

        return $rows;
    }

    /** Whether the user may record the premium receipt of this policy now: money is outstanding and they hold receipt.create in the policy's branch. */
    public function canRecordReceipt(string $userId, string $policyId): bool
    {
        $policy = DB::table('policies')->where('id', $policyId)->first(['entity_id', 'branch_id', 'status']);

        return $policy !== null && in_array((string) $policy->status, ['issued', 'active', 'lapsed', 'expired'], true)
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

    /** @return array{label: string, url: string, prompt: string}|null */
    public function afterIssue(string $userId, string $policyId): ?array
    {
        return $this->canRecordReceipt($userId, $policyId)
            ? ['label' => 'Record receipt', 'url' => "/receipts/create?policy={$policyId}", 'prompt' => 'Record the premium receipt?'] : null;
    }
}
