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
use App\Modules\Platform\Messaging\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Commission payout (design §5.6 accrued ─▶ approved ─▶ paid, spec §4 "clawback netting"). Statements are prepared and approved only by the monthly
 * statement run (CommissionStatementRun, GA-10 / D-75: the Phase 1 "approve up to a date" payout was removed); `pay` is done by someone else (CONTEXT.md
 * non-negotiable #9, §7.3 commission.approve ✕ commission.pay) and posts COMMISSION_PAID for a bank route: DR commission_payable / CR bank_main for the net.
 * Withholding stays in commission_withholding_payable for remittance to the tax authority (not part of the payout). Statement-run statements (slice D6) are
 * paid by their route: `payroll` → COMMISSION_PAYOUT_TO_PAYROLL (to salary_payable), `ap` → COMMISSION_PAYOUT_TO_AP (to accounts_payable), each with an outbox message.
 */
final class CommissionPayoutService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SodGuard $sod,
        private readonly SubmitAccountingEvent $submit,
        private readonly BankAccountQuery $bankAccounts,
        private readonly Audit $audit,
    ) {}

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
            $eventType = match ($statement->paid_via) {
                'payroll' => 'COMMISSION_PAYOUT_TO_PAYROLL',
                'ap' => 'COMMISSION_PAYOUT_TO_AP',
                default => 'COMMISSION_PAID',
            };
            if ($statement->net_minor > 0) {
                ($this->submit)(
                    entityId: $statement->entity_id, eventType: $eventType, sourceType: 'commission_statement', sourceId: $statement->id,
                    idempotencyKey: $eventType.':'.$statement->id, transactionDate: $paidOn, effectiveDate: $paidOn, currency: $statement->currency,
                    // Gap fix GA-27: the statement number is the payout's reference, so the bank line of the payment names it and the statement line matches it.
                    payload: ['amount' => $statement->net_minor, 'commission_statement_id' => $statement->id, 'bank_account_id' => $bankAccountId, 'reference' => $statement->number]
                        // Addendum §B.10.7: COMMISSION_PAYOUT_TO_PAYROLL v2 carries the employee on the salary payable line.
                        + ($statement->paid_via === 'payroll' && $agent->employeeId !== null ? ['employee_id' => $agent->employeeId] : [])
                        + ($bankGl === null || $statement->paid_via !== 'bank' ? [] : ['account_overrides' => ['bank_main' => $bankGl]]),
                    dimensions: ['branch' => $agent->branchId, 'agent' => $agent->id],
                );
            }
            // Payroll and payables are Phase 2 modules: they pick the payout up from these messages (Distribution design note §2 step 6, §4 "bonus posts through payroll as an earning type").
            if ($statement->paid_via === 'payroll') {
                app(Outbox::class)->add('CommissionPayrollEarning', ['commission_statement_id' => $statement->id, 'producer_id' => $agent->id, 'employee_id' => $agent->employeeId,
                    'earning_type' => 'commission', 'amount_minor' => $statement->net_minor, 'currency' => $statement->currency, 'period_end' => $statement->period_end?->toDateString(),
                    'paid_on' => $paidOn->toDateString(), 'reference' => $statement->number]); // addendum §B.11: the payroll consumer dates the liability and names the statement
            } elseif ($statement->paid_via === 'ap') {
                app(Outbox::class)->add('CommissionPayableToAp', ['commission_statement_id' => $statement->id, 'producer_id' => $agent->id, 'party_id' => $agent->partyId,
                    'amount_minor' => $statement->net_minor, 'currency' => $statement->currency, 'period_end' => $statement->period_end?->toDateString()]);
            }
            $this->audit->record('commission_statement.paid', AuditSubject::of('commission_statement', $statement->id), ['status' => 'approved'],
                ['status' => 'paid', 'paid_on' => $paidOn->toDateString()], null, 'commission.pay', Actor::user($actorUserId));

            return $statement;
        });
    }
}
