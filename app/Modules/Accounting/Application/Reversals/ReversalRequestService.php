<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Reversals;

use App\Modules\Accounting\Application\ReversalService;
use App\Modules\Accounting\Domain\Enums\JournalStatus;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Exceptions\ReversalRequestException;
use App\Modules\Platform\Approvals\ApprovalFacts;
use App\Modules\Platform\Approvals\ApprovalService;
use App\Modules\Platform\Approvals\Decision;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Authorization\SodViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design §2.3 + D-11: a reversal is requested (accounting.reverse_journal, reason), approved by someone
 * other than the requester — every policy step when an approval policy for `journal_reversal` matches,
 * otherwise one checker holding accounting.approve_journal — and then executed by ReversalService.
 */
final class ReversalRequestService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SodGuard $sod,
        private readonly ApprovalService $approvals,
        private readonly ReversalService $reversals,
        private readonly Audit $audit,
    ) {}

    public function request(string $journalId, CarbonImmutable $on, string $reason, string $requesterId): string
    {
        return DB::transaction(function () use ($journalId, $on, $reason, $requesterId): string {
            $journal = Journal::query()->whereKey($journalId)->lockForUpdate()->firstOrFail();
            $this->permissions->authorize($requesterId, 'accounting.reverse_journal', AuthorizationScope::entity($journal->entity_id));
            if (trim($reason) === '') {
                throw new ReversalRequestException('REASON_REQUIRED', 'A reversal requires a reason.');
            }
            if ($journal->status !== JournalStatus::Posted) {
                throw new ReversalRequestException('NOT_POSTED', "Journal {$journalId} is not posted.");
            }
            if (DB::table('journal_reversal_requests')->where('journal_id', $journalId)->where('status', 'pending')->exists()) {
                throw new ReversalRequestException('REQUEST_PENDING', "Journal {$journalId} already has a pending reversal request.");
            }

            $requestId = (string) Str::uuid7();
            $amount = (int) DB::table('journal_lines')->where('journal_id', $journalId)->where('side', Side::Debit->value)->sum('amount_minor');
            $approvalId = $this->approvals->request('journal_reversal', $requestId, new ApprovalFacts($amount, ['kind' => $journal->kind->value]), $requesterId, $on);
            DB::table('journal_reversal_requests')->insert([
                'id' => $requestId, 'tenant_id' => TenantContext::id(), 'journal_id' => $journalId, 'on_date' => $on->toDateString(),
                'reason' => trim($reason), 'requested_by' => $requesterId, 'approval_id' => $approvalId, 'status' => 'pending', 'created_at' => now(),
            ]);
            $this->audit->record('journal.reversal_requested', AuditSubject::of('journal_reversal_request', $requestId), null,
                ['journal_id' => $journalId, 'on_date' => $on->toDateString(), 'approval_id' => $approvalId], trim($reason), 'accounting.reverse_journal', Actor::user($requesterId));

            return $requestId;
        });
    }

    /** One approval decision; returns the reversal journal once executed, null while steps remain. */
    public function approve(string $requestId, string $checkerId, ?string $reason = null): ?Journal
    {
        return DB::transaction(function () use ($requestId, $checkerId, $reason): ?Journal {
            $request = $this->lockPending($requestId);
            if ($request->approval_id !== null) {
                $this->approvals->decide((string) $request->approval_id, $checkerId, Decision::Approved, $reason); // handler executes on the final step
            } else {
                $this->assertSingleChecker($request, $checkerId);
                $this->audit->record('journal.reversal_approved', AuditSubject::of('journal_reversal_request', $requestId), null, null,
                    $reason, 'accounting.approve_journal', Actor::user($checkerId));
                $this->execute($requestId, $checkerId);
            }
            $reversalId = DB::table('journal_reversal_requests')->where('id', $requestId)->value('reversal_journal_id');

            return is_string($reversalId) ? Journal::query()->findOrFail($reversalId) : null;
        });
    }

    public function reject(string $requestId, string $checkerId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new ReversalRequestException('REASON_REQUIRED', 'Rejecting a reversal requires a reason.');
        }
        DB::transaction(function () use ($requestId, $checkerId, $reason): void {
            $request = $this->lockPending($requestId);
            if ($request->approval_id !== null) {
                $this->approvals->decide((string) $request->approval_id, $checkerId, Decision::Rejected, $reason);

                return;
            }
            $this->assertSingleChecker($request, $checkerId);
            $this->markRejected($requestId, $checkerId, $reason);
        });
    }

    /** Final approval reached: run the reversal, created by the requester and approved by the final approver. */
    public function execute(string $requestId, string $approverId): Journal
    {
        $request = $this->lockPending($requestId);
        $original = Journal::query()->findOrFail((string) $request->journal_id);
        $mayPostSoftLocked = $this->permissions->has($approverId, 'accounting.post_in_soft_locked', AuthorizationScope::entity($original->entity_id));
        $reversal = $this->reversals->reverse($original, CarbonImmutable::parse((string) $request->on_date), (string) $request->reason,
            (string) $request->requested_by, $mayPostSoftLocked, $approverId);
        DB::table('journal_reversal_requests')->where('id', $requestId)->update([
            'status' => 'executed', 'decided_by' => $approverId, 'decided_at' => now(), 'reversal_journal_id' => $reversal->id,
        ]);

        return $reversal;
    }

    public function markRejected(string $requestId, string $deciderId, string $reason): void
    {
        $this->lockPending($requestId);
        DB::table('journal_reversal_requests')->where('id', $requestId)->update(['status' => 'rejected', 'decided_by' => $deciderId, 'decided_at' => now()]);
        $this->audit->record('journal.reversal_rejected', AuditSubject::of('journal_reversal_request', $requestId), ['status' => 'pending'], ['status' => 'rejected'],
            $reason, 'accounting.approve_journal', Actor::user($deciderId));
    }

    /** @param object{id: string, journal_id: string, on_date: string, reason: string, requested_by: string, approval_id: string|null} $request */
    private function assertSingleChecker(object $request, string $checkerId): void
    {
        $entityId = (string) Journal::query()->whereKey((string) $request->journal_id)->value('entity_id');
        $this->permissions->authorize($checkerId, 'accounting.approve_journal', AuthorizationScope::entity($entityId));
        if ($request->requested_by === $checkerId) {
            throw new SodViolation('SOD_CONFLICT', $checkerId, 'accounting.approve_journal', 'accounting.reverse_journal', 'MAKER_CHECKER',
                "The requester of reversal {$request->id} cannot approve it.");
        }
        $this->sod->assert($checkerId, 'accounting.approve_journal', AuditSubject::of('journal_reversal_request', (string) $request->id));
    }

    /** @return object{id: string, journal_id: string, on_date: string, reason: string, requested_by: string, approval_id: string|null} */
    private function lockPending(string $requestId): object
    {
        $request = DB::table('journal_reversal_requests')->where('id', $requestId)->lockForUpdate()->first();
        if ($request === null || $request->status !== 'pending') {
            throw new ReversalRequestException('NOT_PENDING', "Reversal request {$requestId} is not pending.");
        }

        /** @var object{id: string, journal_id: string, on_date: string, reason: string, requested_by: string, approval_id: string|null} $request */
        return $request;
    }
}
