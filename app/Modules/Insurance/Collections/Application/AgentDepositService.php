<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Finance\Bank\Application\BankAccountQuery;
use App\Modules\Insurance\Collections\Domain\Models\AgentDeposit;
use App\Modules\Distribution\Application\ProducerDirectory;
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
 * An agent deposits collected cash (spec §4): AGENT_DEPOSIT_RECORDED, DR bank_main (the bank account's GL account when named) / CR agent_receivable,
 * never more than the agent's undeposited cash. Interpretation: recorded under receipt.create at the agent's branch.
 */
final class AgentDepositService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly DocumentNumberer $numbers,
        private readonly SubmitAccountingEvent $submit,
        private readonly BankAccountQuery $bankAccounts,
        private readonly AgentCashPositionQuery $position,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation INVALID_AMOUNT | DEPOSIT_EXCEEDS_UNDEPOSITED_CASH | INVALID_BANK_ACCOUNT */
    public function record(string $agentId, int $amountMinor, ?string $bankAccountId, ?string $reference, string $actorUserId, CarbonImmutable $depositedOn): AgentDeposit
    {
        $agent = app(ProducerDirectory::class)->get($agentId);
        $entityId = (string) DB::table('branches')->where('id', $agent->branchId)->value('entity_id');
        $currency = (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency');
        $this->permissions->authorize($actorUserId, 'receipt.create', AuthorizationScope::branch($entityId, $agent->branchId));
        if ($amountMinor <= 0) {
            throw new BusinessRuleViolation('INVALID_AMOUNT', 'A deposit must be a positive amount.');
        }
        $bankGl = $bankAccountId === null ? null : $this->bankAccounts->glAccountFor($bankAccountId, $entityId, $currency);
        $number = $this->numbers->reserve(new DocumentNumberScope($entityId, $agent->branchId, 'agent_deposit', 'ADP', $depositedOn), $actorUserId);

        return DB::transaction(function () use ($agent, $entityId, $currency, $amountMinor, $bankAccountId, $bankGl, $reference, $actorUserId, $depositedOn, $number): AgentDeposit {
            app(ProducerDirectory::class)->lock($agent->id); // serialises deposits per agent
            $undeposited = $this->position->undepositedMinor($agent->id);
            if ($amountMinor > $undeposited) {
                throw new BusinessRuleViolation('DEPOSIT_EXCEEDS_UNDEPOSITED_CASH', "Agent {$agent->code} holds {$undeposited} undeposited, less than {$amountMinor}.");
            }
            $deposit = AgentDeposit::query()->create(['entity_id' => $entityId, 'branch_id' => $agent->branchId, 'agent_id' => $agent->id, 'number' => $number->number,
                'amount_minor' => $amountMinor, 'currency' => $currency, 'deposited_on' => $depositedOn->toDateString(), 'bank_account_id' => $bankAccountId,
                'reference' => $reference, 'recorded_by' => $actorUserId]);
            $this->numbers->markUsed($number->id, 'agent_deposit', $deposit->id);
            ($this->submit)(
                entityId: $entityId, eventType: 'AGENT_DEPOSIT_RECORDED', sourceType: 'agent_deposit', sourceId: $deposit->id,
                idempotencyKey: 'AGENT_DEPOSIT_RECORDED:'.$deposit->id, transactionDate: $depositedOn, effectiveDate: $depositedOn, currency: $currency,
                payload: ['amount' => $amountMinor, 'agent_deposit_id' => $deposit->id, 'reference' => $reference, 'receipt_number' => $deposit->number, 'bank_account_id' => $bankAccountId]
                    + ($bankGl === null ? [] : ['account_overrides' => ['bank_main' => $bankGl]]),
                dimensions: ['branch' => $agent->branchId, 'agent' => $agent->id],
            );
            $this->audit->record('agent_deposit.recorded', AuditSubject::of('agent_deposit', $deposit->id), null,
                ['agent_id' => $agent->id, 'amount_minor' => $amountMinor, 'deposited_on' => $depositedOn->toDateString()], null, 'receipt.create', Actor::user($actorUserId));

            return $deposit;
        });
    }
}
