<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use App\Modules\Distribution\Application\Advances\AdvanceService;
use App\Modules\Distribution\Application\ProducerDirectory;
use App\Modules\Distribution\Application\ProducerSummary;
use App\Modules\Insurance\Commission\Domain\Models\CommissionEntry;
use App\Modules\Insurance\Commission\Domain\Models\CommissionStatement;
use App\Modules\Insurance\Policy\Application\PersistencyQuery;
use App\Modules\Insurance\Policy\Domain\Models\Policy;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The monthly producer statement run (Distribution design note §2 step 6, slice D6). `prepare` (commission.approve) first releases conditional
 * commission whose persistency condition is met (A-23), then gives every producer with accrued commission up to the period end one draft
 * statement: earned (direct) + override + bonus + clawback − withholding − advance recovery = net, with the payout route (A-22). Rerunning a
 * period rebuilds its drafts. `approve` numbers the statement, recovers the advances and approves its entries; CommissionPayoutService::pay
 * (commission.pay, another person) pays it by its route. Producers whose commission nets to nothing are carried to the next run.
 */
final class CommissionStatementRun
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ProducerDirectory $producers,
        private readonly AdvanceService $advances,
        private readonly PersistencyQuery $persistency,
        private readonly CommissionAccountingEvents $accounting,
        private readonly DocumentNumberer $numbers,
        private readonly Audit $audit,
    ) {}

    /** @return list<string> the draft statement ids */
    public function prepare(string $entityId, CarbonImmutable $periodEnd, string $actorUserId): array
    {
        $this->permissions->authorize($actorUserId, 'commission.approve', AuthorizationScope::entity($entityId));
        $day = $periodEnd->toDateString();

        return DB::transaction(function () use ($entityId, $periodEnd, $day, $actorUserId): array {
            $drafts = CommissionStatement::query()->where('entity_id', $entityId)->where('period_end', $day)->where('status', 'draft')->lockForUpdate()->pluck('id');
            CommissionEntry::query()->whereIn('statement_id', $drafts)->update(['statement_id' => null]);
            CommissionStatement::query()->whereKey($drafts)->delete();
            $this->releaseConditional($entityId, $periodEnd);

            $totals = DB::table('commission_entries')->where('entity_id', $entityId)->where('status', 'accrued')->whereNull('statement_id')->where('earned_on', '<=', $day)
                ->groupBy('agent_id', 'currency')->orderBy('agent_id')->selectRaw("agent_id, currency,
                    sum(amount_minor) filter (where kind = 'earned' and beneficiary_role = 'direct') as earned,
                    sum(amount_minor) filter (where kind = 'earned' and beneficiary_role = 'override') as override,
                    sum(amount_minor) filter (where kind = 'bonus') as bonus,
                    sum(amount_minor) filter (where kind = 'clawback') as clawback,
                    sum(amount_minor) as gross, sum(withholding_minor) as withholding")->get();
            $ids = [];
            foreach ($totals as $row) {
                $netBefore = (int) $row->gross - (int) $row->withholding;
                if ($netBefore <= 0) {
                    continue;
                }
                $producer = $this->producers->get((string) $row->agent_id);
                $unstated = DB::table('commission_entries')->where('entity_id', $entityId)->where('agent_id', $producer->id)->where('status', 'accrued')->whereNull('statement_id')
                    ->where('earned_on', '<=', $day)->where('currency', (string) $row->currency);
                $onPlanOnly = (clone $unstated)->whereNotNull('commission_plan_id')->exists() && ! (clone $unstated)->whereNotNull('scheme_id')->exists();
                $recovery = $this->advances->proposedRecovery($producer->id, $netBefore);
                $statement = CommissionStatement::query()->create(['entity_id' => $entityId, 'agent_id' => $producer->id, 'up_to' => $day, 'period_end' => $day,
                    'earned_minor' => (int) $row->earned, 'override_minor' => (int) $row->override, 'bonus_minor' => (int) $row->bonus, 'clawback_minor' => (int) $row->clawback,
                    'gross_minor' => (int) $row->gross, 'withholding_minor' => (int) $row->withholding, 'advances_recovered_minor' => $recovery, 'net_minor' => $netBefore - $recovery,
                    'currency' => (string) $row->currency, 'status' => 'draft', 'paid_via' => self::route($producer, $onPlanOnly), 'prepared_by' => $actorUserId]);
                CommissionEntry::query()->where('entity_id', $entityId)->where('agent_id', $producer->id)->where('status', 'accrued')->whereNull('statement_id')
                    ->where('earned_on', '<=', $day)->where('currency', (string) $row->currency)->update(['statement_id' => $statement->id]);
                $ids[] = $statement->id;
            }
            $this->audit->record('commission_statement_run.prepared', AuditSubject::of('legal_entity', $entityId), null, ['period_end' => $day, 'statements' => count($ids)],
                null, 'commission.approve', Actor::user($actorUserId));

            return $ids;
        });
    }

    /** @throws BusinessRuleViolation STATEMENT_NOT_DRAFT */
    public function approve(string $statementId, string $actorUserId, CarbonImmutable $on): CommissionStatement
    {
        $statement = CommissionStatement::query()->findOrFail($statementId);
        $this->permissions->authorize($actorUserId, 'commission.approve', AuthorizationScope::entity($statement->entity_id));
        $number = $this->numbers->reserve(new DocumentNumberScope($statement->entity_id, null, 'commission_statement', 'CST', $on), $actorUserId);

        return DB::transaction(function () use ($statementId, $actorUserId, $on, $number): CommissionStatement {
            $statement = CommissionStatement::query()->whereKey($statementId)->lockForUpdate()->firstOrFail();
            if ($statement->status !== 'draft') {
                throw new BusinessRuleViolation('STATEMENT_NOT_DRAFT', "Commission statement {$statement->number} is already {$statement->status}.");
            }
            $netBefore = $statement->gross_minor - $statement->withholding_minor;
            $recovered = $this->advances->recover($statement->agent_id, $statement->entity_id, $netBefore, $statement->id, $on);
            $statement->forceFill(['status' => 'approved', 'number' => $number->number, 'approved_by' => $actorUserId, 'approved_on' => $on->toDateString(),
                'advances_recovered_minor' => $recovered, 'net_minor' => $netBefore - $recovered])->save();
            $this->numbers->markUsed($number->id, 'commission_statement', $statement->id);
            CommissionEntry::query()->where('statement_id', $statement->id)->update(['status' => 'approved']);
            $this->audit->record('commission_statement.approved', AuditSubject::of('commission_statement', $statement->id), ['status' => 'draft'],
                ['status' => 'approved', 'net_minor' => $statement->net_minor, 'advances_recovered_minor' => $recovered], null, 'commission.approve', Actor::user($actorUserId));

            return $statement;
        });
    }

    /** Conditional entries whose rule's minimum persistency the producer meets on the period end become accrued, dated the period end, and post. */
    private function releaseConditional(string $entityId, CarbonImmutable $periodEnd): void
    {
        $conditional = CommissionEntry::query()->join('compensation_rules as r', 'r.id', '=', 'commission_entries.rule_id')->where('commission_entries.entity_id', $entityId)
            ->where('commission_entries.status', 'conditional')->where('commission_entries.earned_on', '<=', $periodEnd->toDateString())
            ->orderBy('commission_entries.created_at')->get(['commission_entries.*', 'r.min_persistency_bp']);
        $measured = [];
        foreach ($conditional as $entry) {
            $measured[$entry->agent_id] ??= $this->persistency->monthBp($entry->agent_id, $periodEnd);
            if ($measured[$entry->agent_id] === null || $measured[$entry->agent_id] < (int) $entry->getAttribute('min_persistency_bp')) {
                continue;
            }
            $released = CommissionEntry::query()->findOrFail($entry->id);
            $released->forceFill(['status' => 'accrued', 'earned_on' => $periodEnd->toDateString()])->save();
            $this->accounting->earned($released, Policy::query()->findOrFail($released->policy_id), (int) $released->getAttribute('withholding_bp'));
        }
    }

    /**
     * ASSUMPTION A-22: producers on payroll (an employee record) are paid through payroll; everyone else through accounts payable.
     * ASSUMPTION: A-191 (GA-10) — commission earned only under Phase 1 commission plans (A-20, no compensation scheme) is paid from the bank as the Phase 1
     * payout did, now that the statement run is the only payout path; `erp.distribution.plan_payout_route` changes it.
     */
    private static function route(ProducerSummary $producer, bool $onPlanOnly): string
    {
        /** @var array<string, string> $byType */
        $byType = config('erp.distribution.payout_route_by_type', []);
        if ($producer->employeeId !== null) {
            return 'payroll';
        }
        $planRoute = config('erp.distribution.plan_payout_route', 'bank');
        if ($onPlanOnly && is_string($planRoute) && in_array($planRoute, ['bank', 'ap', 'payroll'], true)) {
            return $planRoute;
        }

        return $byType[$producer->type] ?? 'ap';
    }
}
