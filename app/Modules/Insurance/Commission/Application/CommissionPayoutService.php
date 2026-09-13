<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Application;

use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Finance\Bank\Application\BankAccountQuery;
use App\Modules\Insurance\Commission\Domain\Models\CommissionEntry;
use App\Modules\Insurance\Commission\Domain\Models\CommissionStatement;
use App\Modules\Distribution\Application\ProducerDirectory;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Commission payout (design §5.6 accrued ─▶ approved ─▶ paid, spec §4 "clawback netting"). `approve` gathers an agent's accrued entries earned up
 * to a date into a statement — clawbacks net against earnings — under commission.approve; `pay` is done by someone else (CONTEXT.md
 * non-negotiable #9, §7.3 commission.approve ✕ commission.pay) and posts COMMISSION_PAID: DR commission_payable / CR bank_main for the net.
 * Withholding stays in commission_withholding_payable for remittance to the tax authority (not part of the payout).
 */
final class CommissionPayoutService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SodGuard $sod,
        private readonly DocumentNumberer $numbers,
        private readonly SubmitAccountingEvent $submit,
        private readonly BankAccountQuery $bankAccounts,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation NOTHING_TO_PAY when no accrued entries up to the date net to a positive amount */
    public function approve(string $agentId, CarbonImmutable $upTo, string $actorUserId, CarbonImmutable $on): CommissionStatement
    {
        $agent = app(ProducerDirectory::class)->get($agentId);
        $entityId = (string) DB::table('branches')->where('id', $agent->branchId)->value('entity_id');
        $this->permissions->authorize($actorUserId, 'commission.approve', AuthorizationScope::branch($entityId, $agent->branchId));
        $this->assertSomethingToPay($agent->id, $entityId, $upTo);
        $number = $this->numbers->reserve(new DocumentNumberScope($entityId, null, 'commission_statement', 'CST', $on), $actorUserId);

        return DB::transaction(function () use ($agent, $entityId, $upTo, $actorUserId, $on, $number): CommissionStatement {
            $entries = $this->accruedEntries($agent->id, $entityId, $upTo)->lockForUpdate()->get();
            $gross = (int) $entries->sum('amount_minor');
            $withholding = (int) $entries->sum('withholding_minor');
            if ($gross - $withholding <= 0) {
                throw new BusinessRuleViolation('NOTHING_TO_PAY', "Agent {$agent->code} has nothing payable up to {$upTo->toDateString()}.");
            }
            $statement = CommissionStatement::query()->create(['entity_id' => $entityId, 'agent_id' => $agent->id, 'number' => $number->number, 'up_to' => $upTo->toDateString(),
                'gross_minor' => $gross, 'withholding_minor' => $withholding, 'net_minor' => $gross - $withholding, 'currency' => (string) $entries->first()?->currency,
                'status' => 'approved', 'approved_by' => $actorUserId, 'approved_on' => $on->toDateString()]);
            $this->numbers->markUsed($number->id, 'commission_statement', $statement->id);
            CommissionEntry::query()->whereKey($entries->modelKeys())->update(['status' => 'approved', 'statement_id' => $statement->id]);
            $this->audit->record('commission_statement.approved', AuditSubject::of('commission_statement', $statement->id), null,
                ['agent_id' => $agent->id, 'up_to' => $upTo->toDateString(), 'net_minor' => $statement->net_minor, 'entries' => $entries->count()],
                null, 'commission.approve', Actor::user($actorUserId));

            return $statement;
        });
    }

    /** @throws BusinessRuleViolation STATEMENT_NOT_APPROVED | INVALID_BANK_ACCOUNT */
    public function pay(string $statementId, ?string $bankAccountId, string $actorUserId, CarbonImmutable $paidOn): CommissionStatement
    {
        $statement = CommissionStatement::query()->findOrFail($statementId);
        $agent = app(ProducerDirectory::class)->get($statement->agent_id);
        $this->permissions->authorize($actorUserId, 'commission.pay', AuthorizationScope::branch($statement->entity_id, $agent->branchId));
        $this->sod->assert($actorUserId, 'commission.pay', AuditSubject::of('commission_statement', $statement->id));
        $bankGl = $bankAccountId === null ? null : $this->bankAccounts->glAccountFor($bankAccountId, $statement->entity_id, $statement->currency);

        return DB::transaction(function () use ($statementId, $agent, $bankAccountId, $bankGl, $actorUserId, $paidOn): CommissionStatement {
            $statement = CommissionStatement::query()->whereKey($statementId)->lockForUpdate()->firstOrFail();
            if ($statement->status !== 'approved') {
                throw new BusinessRuleViolation('STATEMENT_NOT_APPROVED', "Commission statement {$statement->number} is {$statement->status}.");
            }
            $statement->forceFill(['status' => 'paid', 'paid_by' => $actorUserId, 'paid_on' => $paidOn->toDateString(), 'bank_account_id' => $bankAccountId])->save();
            CommissionEntry::query()->where('statement_id', $statement->id)->update(['status' => 'paid', 'paid_on' => $paidOn->toDateString()]);
            ($this->submit)(
                entityId: $statement->entity_id, eventType: 'COMMISSION_PAID', sourceType: 'commission_statement', sourceId: $statement->id,
                idempotencyKey: 'COMMISSION_PAID:'.$statement->id, transactionDate: $paidOn, effectiveDate: $paidOn, currency: $statement->currency,
                payload: ['amount' => $statement->net_minor, 'commission_statement_id' => $statement->id, 'bank_account_id' => $bankAccountId]
                    + ($bankGl === null ? [] : ['account_overrides' => ['bank_main' => $bankGl]]),
                dimensions: ['branch' => $agent->branchId, 'agent' => $agent->id],
            );
            $this->audit->record('commission_statement.paid', AuditSubject::of('commission_statement', $statement->id), ['status' => 'approved'],
                ['status' => 'paid', 'paid_on' => $paidOn->toDateString()], null, 'commission.pay', Actor::user($actorUserId));

            return $statement;
        });
    }

    private function assertSomethingToPay(string $agentId, string $entityId, CarbonImmutable $upTo): void
    {
        $net = (int) $this->accruedEntries($agentId, $entityId, $upTo)->sum(DB::raw('amount_minor - withholding_minor'));
        if ($net <= 0) {
            throw new BusinessRuleViolation('NOTHING_TO_PAY', "Agent {$agentId} has nothing payable up to {$upTo->toDateString()}.");
        }
    }

    /** @return \Illuminate\Database\Eloquent\Builder<CommissionEntry> */
    private function accruedEntries(string $agentId, string $entityId, CarbonImmutable $upTo): \Illuminate\Database\Eloquent\Builder
    {
        return CommissionEntry::query()->where('agent_id', $agentId)->where('entity_id', $entityId)->where('status', 'accrued')
            ->whereNull('statement_id')->where('earned_on', '<=', $upTo->toDateString());
    }
}
