<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application\Dunning;

use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Domain\Enums\PolicyStatus;
use App\Modules\Platform\Messaging\Outbox;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spec §4 "dunning, grace, auto-lapse". ASSUMPTION: A-10 — the reminder schedule and grace period are not specified: reminders at
 * erp.collections.dunning_notice_days overdue (default 7 and 21), and an active policy with an installment unpaid for more than
 * erp.collections.grace_days (default 30; counted from the later of the due date and the policy's reinstatement) lapses when
 * erp.collections.auto_lapse is on. Notices are recorded once per installment and level and queued as `DunningNoticeDue` outbox messages;
 * delivering them (letter, SMS, email) is LATER. Reruns are no-ops.
 */
final class DunningRun
{
    public function __construct(
        private readonly PolicyLifecycle $policies,
        private readonly Outbox $outbox,
    ) {}

    public function run(string $entityId, CarbonImmutable $asOf): DunningRunResult
    {
        $overdue = DB::table('installments as i')->join('policies as p', 'p.id', '=', 'i.policy_id')
            ->where('p.entity_id', $entityId)->whereIn('p.status', [PolicyStatus::Issued->value, PolicyStatus::Active->value])
            ->where('i.due_date', '<', $asOf->toDateString())->whereRaw('i.amount_minor - i.paid_minor - i.cancelled_minor > 0')
            ->orderBy('i.due_date')->orderBy('i.id')
            ->get(['i.id', 'i.policy_id', 'i.due_date', 'p.status', 'p.reinstated_on', DB::raw('i.amount_minor - i.paid_minor - i.cancelled_minor as outstanding')]);

        $notices = 0;
        $toLapse = [];
        foreach ($overdue as $installment) {
            /** @var object{id: string, policy_id: string, due_date: string, status: string, reinstated_on: string|null, outstanding: int|string} $installment */
            $daysOverdue = (int) CarbonImmutable::parse((string) $installment->due_date)->diffInDays($asOf);
            $notices += $this->issueNotices($entityId, $installment, $daysOverdue, $asOf);
            if ($installment->status === PolicyStatus::Active->value && $this->beyondGrace($installment, $asOf)) {
                $toLapse[(string) $installment->policy_id] = true;
            }
        }
        $lapsed = 0;
        if ((bool) config('erp.collections.auto_lapse', true)) {
            foreach (array_keys($toLapse) as $policyId) {
                $graceDays = (int) config('erp.collections.grace_days', 30);
                $lapsed += $this->policies->lapseForNonPayment($policyId, "An installment is unpaid beyond the {$graceDays}-day grace period (dunning run {$asOf->toDateString()}).") ? 1 : 0;
            }
        }

        return new DunningRunResult($notices, $lapsed);
    }

    /**
     * Gap fix GA-14 (ASSUMPTION A-218): the premium cheque for an installment bounced, so its first reminder goes out now instead of 7 days after the
     * due date — for a policy in force with the installment still unpaid. Recorded once per installment and level like the nightly notices (a later
     * nightly run does not repeat level 1; level 2 follows the schedule), and queued as `DunningNoticeDue` with the reason.
     */
    public function bouncedPremiumNotice(string $installmentId, CarbonImmutable $on): bool
    {
        $installment = DB::table('installments as i')->join('policies as p', 'p.id', '=', 'i.policy_id')->where('i.id', $installmentId)
            ->whereIn('p.status', [PolicyStatus::Issued->value, PolicyStatus::Active->value])
            ->first(['i.id', 'i.policy_id', 'i.due_date', 'p.entity_id', DB::raw('i.amount_minor - i.paid_minor - i.cancelled_minor as outstanding')]);
        if ($installment === null || (int) $installment->outstanding <= 0) {
            return false;
        }
        $inserted = DB::table('dunning_notices')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'entity_id' => (string) $installment->entity_id,
            'policy_id' => (string) $installment->policy_id, 'installment_id' => (string) $installment->id, 'level' => 1,
            'days_overdue' => max(0, (int) CarbonImmutable::parse((string) $installment->due_date)->diffInDays($on, false)),
            'outstanding_minor' => (int) $installment->outstanding, 'issued_on' => $on->toDateString(), 'created_at' => now()]);
        if ($inserted === 1) {
            $this->outbox->add('DunningNoticeDue', ['policy_id' => (string) $installment->policy_id, 'installment_id' => (string) $installment->id, 'level' => 1, 'reason' => 'cheque_bounced']);
        }

        return $inserted === 1;
    }

    /** @param object{id: string, policy_id: string, outstanding: int|string} $installment */
    private function issueNotices(string $entityId, object $installment, int $daysOverdue, CarbonImmutable $asOf): int
    {
        $issued = 0;
        /** @var list<int> $schedule */
        $schedule = config('erp.collections.dunning_notice_days', [7, 21]);
        foreach ($schedule as $index => $threshold) {
            if ($daysOverdue < $threshold) {
                break;
            }
            $inserted = DB::table('dunning_notices')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId,
                'policy_id' => $installment->policy_id, 'installment_id' => $installment->id, 'level' => $index + 1, 'days_overdue' => $daysOverdue,
                'outstanding_minor' => (int) $installment->outstanding, 'issued_on' => $asOf->toDateString(), 'created_at' => now()]);
            if ($inserted === 1) {
                $this->outbox->add('DunningNoticeDue', ['policy_id' => $installment->policy_id, 'installment_id' => $installment->id, 'level' => $index + 1]);
                $issued++;
            }
        }

        return $issued;
    }

    /** @param object{due_date: string, reinstated_on: string|null} $installment */
    private function beyondGrace(object $installment, CarbonImmutable $asOf): bool
    {
        $from = CarbonImmutable::parse((string) $installment->due_date);
        if ($installment->reinstated_on !== null && CarbonImmutable::parse((string) $installment->reinstated_on)->greaterThan($from)) {
            $from = CarbonImmutable::parse((string) $installment->reinstated_on);
        }

        return (int) $from->diffInDays($asOf, false) > (int) config('erp.collections.grace_days', 30);
    }
}
