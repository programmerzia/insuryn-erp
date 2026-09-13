<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Advances;

use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Distribution\Application\ProducerDirectory;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Producer advances (Distribution design note §1 producer_advances, §2 step 6 "− advance recovery"). An advance is money paid out ahead of
 * commission (PRODUCER_ADVANCE_ISSUED: DR producer_advances / CR bank_main) under commission.pay, and each approved statement recovers from it
 * by its recovery rule: `full` (as much as the statement's net allows) or `percent_of_net` (bp of the statement's net before recovery), oldest
 * advance first, never more than the balance (PRODUCER_ADVANCE_RECOVERED: DR commission_payable / CR producer_advances).
 */
final class AdvanceService
{
    private const WHOLE_BP = 10_000;

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ProducerDirectory $producers,
        private readonly SubmitAccountingEvent $submit,
        private readonly Audit $audit,
    ) {}

    /** @param array<string, mixed> $recoveryRule {type: full} | {type: percent_of_net, bp: 1..10000} */
    public function issue(string $producerId, int $amountMinor, array $recoveryRule, CarbonImmutable $issuedOn, string $actorUserId, ?string $bankAccountId = null): string
    {
        $producer = $this->producers->get($producerId);
        $entityId = (string) DB::table('branches')->where('id', $producer->branchId)->value('entity_id');
        $this->permissions->authorize($actorUserId, 'commission.pay', AuthorizationScope::branch($entityId, $producer->branchId));
        $rule = self::rule($recoveryRule);
        if ($amountMinor <= 0) {
            throw new BusinessRuleViolation('ADVANCE_INVALID', 'An advance must be a positive amount.');
        }
        $entity = DB::table('legal_entities')->where('id', $entityId)->first(['base_currency']);
        $currency = (string) $entity?->base_currency;
        $bankGl = null;
        if ($bankAccountId !== null) {
            $bankGl = DB::table('bank_accounts')->where('id', $bankAccountId)->where('entity_id', $entityId)->where('currency', $currency)->where('status', 'active')->value('gl_account_id')
                ?? throw new BusinessRuleViolation('INVALID_BANK_ACCOUNT', 'Choose an active bank account of this entity in the same currency.');
        }

        return DB::transaction(function () use ($producer, $entityId, $amountMinor, $currency, $issuedOn, $rule, $bankAccountId, $bankGl, $actorUserId): string {
            $id = (string) Str::uuid7();
            DB::table('producer_advances')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId, 'producer_id' => $producer->id, 'amount_minor' => $amountMinor,
                'balance_minor' => $amountMinor, 'currency' => $currency, 'issued_on' => $issuedOn->toDateString(), 'recovery_rule' => json_encode($rule, JSON_THROW_ON_ERROR),
                'status' => 'open', 'bank_account_id' => $bankAccountId, 'issued_by' => $actorUserId, 'created_at' => now(), 'updated_at' => now()]);
            ($this->submit)(entityId: $entityId, eventType: 'PRODUCER_ADVANCE_ISSUED', sourceType: 'producer_advance', sourceId: $id, idempotencyKey: 'PRODUCER_ADVANCE_ISSUED:'.$id,
                transactionDate: $issuedOn, effectiveDate: $issuedOn, currency: $currency,
                payload: ['amount' => $amountMinor, 'producer_advance_id' => $id] + ($bankGl === null ? [] : ['account_overrides' => ['bank_main' => (string) $bankGl]]),
                dimensions: ['branch' => $producer->branchId, 'agent' => $producer->id]);
            $this->audit->record('producer_advance.issued', AuditSubject::of('producer', $producer->id), null,
                ['advance_id' => $id, 'amount_minor' => $amountMinor, 'recovery_rule' => $rule], null, 'commission.pay', Actor::user($actorUserId));

            return $id;
        });
    }

    /** What a statement with this net (before recovery) would recover from the producer's open advances now. */
    public function proposedRecovery(string $producerId, int $netBeforeRecoveryMinor): int
    {
        return array_sum(array_column($this->plan($producerId, $netBeforeRecoveryMinor, false), 'amount'));
    }

    /**
     * Recovers from open advances for an approved statement and posts it. Returns the amount recovered (may be less than the draft proposed if
     * advances changed since).
     */
    public function recover(string $producerId, string $entityId, int $netBeforeRecoveryMinor, string $statementId, CarbonImmutable $on): int
    {
        $plan = $this->plan($producerId, $netBeforeRecoveryMinor, true);
        $total = array_sum(array_column($plan, 'amount'));
        if ($total === 0) {
            return 0;
        }
        foreach ($plan as ['advance' => $advance, 'amount' => $amount]) {
            $balance = (int) $advance->balance_minor - $amount;
            DB::table('producer_advances')->where('id', $advance->id)->update(['balance_minor' => $balance, 'status' => $balance === 0 ? 'recovered' : 'open', 'updated_at' => now()]);
            DB::table('producer_advance_recoveries')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'advance_id' => $advance->id,
                'statement_id' => $statementId, 'amount_minor' => $amount, 'recovered_on' => $on->toDateString()]);
        }
        $producer = $this->producers->get($producerId);
        ($this->submit)(entityId: $entityId, eventType: 'PRODUCER_ADVANCE_RECOVERED', sourceType: 'commission_statement', sourceId: $statementId,
            idempotencyKey: 'PRODUCER_ADVANCE_RECOVERED:'.$statementId, transactionDate: $on, effectiveDate: $on, currency: (string) $plan[0]['advance']->currency,
            payload: ['amount' => $total, 'commission_statement_id' => $statementId], dimensions: ['branch' => $producer->branchId, 'agent' => $producerId]);

        return $total;
    }

    /** @return list<array{advance: \stdClass, amount: int}> */
    private function plan(string $producerId, int $netBeforeRecoveryMinor, bool $lock): array
    {
        $advances = DB::table('producer_advances')->where('producer_id', $producerId)->where('status', 'open')->orderBy('issued_on')->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $remaining = max(0, $netBeforeRecoveryMinor);
        $plan = [];
        foreach ($advances as $advance) {
            if ($remaining === 0) {
                break;
            }
            /** @var array{type: string, bp?: int} $rule */
            $rule = json_decode((string) $advance->recovery_rule, true);
            $allowed = $rule['type'] === 'full' ? $remaining : intdiv(max(0, $netBeforeRecoveryMinor) * (int) ($rule['bp'] ?? 0), self::WHOLE_BP);
            $amount = min($remaining, $allowed, (int) $advance->balance_minor);
            if ($amount > 0) {
                $plan[] = ['advance' => $advance, 'amount' => $amount];
                $remaining -= $amount;
            }
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array{type: string, bp?: int}
     */
    private static function rule(array $rule): array
    {
        $type = $rule['type'] ?? null;
        if ($type === 'full') {
            return ['type' => 'full'];
        }
        if ($type === 'percent_of_net' && is_int($rule['bp'] ?? null) && $rule['bp'] >= 1 && $rule['bp'] <= self::WHOLE_BP) {
            return ['type' => 'percent_of_net', 'bp' => $rule['bp']];
        }

        throw new BusinessRuleViolation('ADVANCE_INVALID', 'A recovery rule is {type: full} or {type: percent_of_net, bp: 1–10000}.');
    }
}
