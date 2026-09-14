<?php

declare(strict_types=1);

namespace App\Modules\Finance\Expenses\Application;

use App\Modules\Accounting\Application\Queries\AccountLineQuery;
use App\Modules\Accounting\Application\Queries\FiscalPeriodQuery;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Finance\Bank\Application\BankAccountQuery;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design addendum v2 §B.6 petty cash (imprest): a float per branch held by a custodian; vouchers paid from it (PETTY_CASH_SPENT); replenishment of the
 * vouchers spent, requested by one person and approved by another (PETTY_CASH_REPLENISHED); surprise cash counts whose difference is posted
 * (PETTY_CASH_COUNT_DIFFERENCE). INVARIANT: cash on hand is never negative — a voucher above it is refused.
 */
final class PettyCashService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SodGuard $sod,
        private readonly AccountLineQuery $accounts,
        private readonly FiscalPeriodQuery $periods,
        private readonly BankAccountQuery $bankAccounts,
        private readonly DocumentNumberer $numberer,
        private readonly SubmitAccountingEvent $submit,
        private readonly Audit $audit,
    ) {}

    /**
     * Sets up a float and hands the cash to its custodian (PETTY_CASH_FLOAT_ISSUED from the bank). The finance manager's duty (`pettycash.approve`).
     *
     * @throws BusinessRuleViolation PETTY_CASH_ACCOUNT_INVALID | INVALID_AMOUNT | ASSET_PERIOD_NOT_OPEN
     */
    public function createFloat(string $entityId, string $branchId, string $code, string $name, string $custodianUserId, int $imprestMinor, string $glAccountId, string $bankAccountId,
        CarbonImmutable $issuedOn, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, 'pettycash.approve', AuthorizationScope::branch($entityId, $branchId));
        $account = $this->accounts->account($glAccountId);
        if ($account === null || $account->entityId !== $entityId || $account->type !== 'asset' || ! $account->acceptsPostings()) {
            throw new BusinessRuleViolation('PETTY_CASH_ACCOUNT_INVALID', 'Choose an active asset account for the petty cash float.');
        }
        if ($imprestMinor <= 0) {
            throw new BusinessRuleViolation('INVALID_AMOUNT', 'Give the float a positive limit.');
        }
        $this->assertOpen($entityId, $issuedOn);
        $currency = (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency');
        $bankGl = $this->bankAccounts->glAccountFor($bankAccountId, $entityId, $currency);

        return DB::transaction(function () use ($entityId, $branchId, $code, $name, $custodianUserId, $imprestMinor, $glAccountId, $bankAccountId, $bankGl, $issuedOn, $currency, $actorUserId): string {
            $id = (string) Str::uuid7();
            DB::table('petty_cash_floats')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId, 'branch_id' => $branchId, 'code' => $code, 'name' => $name,
                'custodian_user_id' => $custodianUserId, 'imprest_minor' => $imprestMinor, 'gl_account_id' => $glAccountId, 'currency' => $currency, 'issued_on' => $issuedOn->toDateString(),
                'bank_account_id' => $bankAccountId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            ($this->submit)(entityId: $entityId, eventType: 'PETTY_CASH_FLOAT_ISSUED', sourceType: 'petty_cash_float', sourceId: $id, idempotencyKey: 'PETTY_CASH_FLOAT_ISSUED:'.$id,
                transactionDate: $issuedOn, effectiveDate: $issuedOn, currency: $currency,
                payload: ['amount' => $imprestMinor, 'petty_cash_float_id' => $id, 'reference' => $code, 'bank_account_id' => $bankAccountId, 'account_overrides' => ['petty_cash' => $glAccountId, 'bank_main' => $bankGl]],
                dimensions: ['branch' => $branchId, 'petty_cash_float' => $id]);
            $this->audit->record('petty_cash_float.created', AuditSubject::of('petty_cash_float', $id), null, ['code' => $code, 'imprest_minor' => $imprestMinor, 'custodian_user_id' => $custodianUserId],
                null, 'pettycash.approve', Actor::user($actorUserId));

            return $id;
        });
    }

    /**
     * A voucher paid from the float (PCV-{branch}-{fy}-n): DR the expense account / CR petty cash.
     *
     * @throws BusinessRuleViolation PETTY_CASH_ACCOUNT_INVALID | PETTY_CASH_INSUFFICIENT | INVALID_AMOUNT | ASSET_PERIOD_NOT_OPEN
     */
    public function spend(string $floatId, CarbonImmutable $voucherDate, string $payee, string $description, string $accountId, int $amountMinor, string $actorUserId): string
    {
        $float = $this->float($floatId);
        $this->permissions->authorize($actorUserId, 'pettycash.spend', AuthorizationScope::branch((string) $float->entity_id, (string) $float->branch_id));
        $account = $this->accounts->account($accountId);
        if ($account === null || $account->entityId !== $float->entity_id || $account->type !== 'expense' || ! $account->acceptsPostings() || $account->isControl) {
            throw new BusinessRuleViolation('PETTY_CASH_ACCOUNT_INVALID', 'Choose an active expense account for the voucher.');
        }
        if ($amountMinor <= 0) {
            throw new BusinessRuleViolation('INVALID_AMOUNT', 'Enter the amount paid as a positive amount.');
        }
        $this->assertOpen((string) $float->entity_id, $voucherDate);
        $number = $this->numberer->reserve(new DocumentNumberScope((string) $float->entity_id, (string) $float->branch_id, 'petty_cash_voucher', 'PCV', $voucherDate), $actorUserId);

        return DB::transaction(function () use ($floatId, $voucherDate, $payee, $description, $accountId, $amountMinor, $actorUserId, $number): string {
            $float = DB::table('petty_cash_floats')->where('id', $floatId)->lockForUpdate()->first() ?? throw new \LogicException('Float missing.');
            $onHand = $this->cashOnHand($floatId);
            if ($amountMinor > $onHand) {
                throw new BusinessRuleViolation('PETTY_CASH_INSUFFICIENT', 'The float does not hold enough cash for this voucher. Ask for a replenishment first.');
            }
            $id = (string) Str::uuid7();
            DB::table('petty_cash_vouchers')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'float_id' => $floatId, 'number' => $number->number, 'voucher_date' => $voucherDate->toDateString(),
                'payee' => $payee, 'description' => $description, 'account_id' => $accountId, 'amount_minor' => $amountMinor, 'status' => 'posted', 'created_by' => $actorUserId]);
            $this->numberer->markUsed($number->id, 'petty_cash_voucher', $id);
            ($this->submit)(entityId: (string) $float->entity_id, eventType: 'PETTY_CASH_SPENT', sourceType: 'petty_cash_voucher', sourceId: $id, idempotencyKey: 'PETTY_CASH_SPENT:'.$id,
                transactionDate: $voucherDate, effectiveDate: $voucherDate, currency: (string) $float->currency,
                payload: ['amount' => $amountMinor, 'petty_cash_voucher_id' => $id, 'reference' => $number->number, 'account_overrides' => ['petty_cash_expense' => $accountId, 'petty_cash' => (string) $float->gl_account_id]],
                dimensions: ['branch' => (string) $float->branch_id, 'petty_cash_float' => $floatId]);
            $this->audit->record('petty_cash_voucher.posted', AuditSubject::of('petty_cash_voucher', $id), null, ['number' => $number->number, 'amount_minor' => $amountMinor, 'float_id' => $floatId],
                $description, 'pettycash.spend', Actor::user($actorUserId));
            $this->audit->record('petty_cash_voucher.posted', AuditSubject::of('petty_cash_float', $floatId), null, ['voucher' => $number->number, 'amount_minor' => $amountMinor], $description, 'pettycash.spend', Actor::user($actorUserId));

            return $id;
        });
    }

    /**
     * Imprest top-up of every voucher not yet replenished (PCR-{branch}-{fy}-n), waiting for approval.
     *
     * @throws BusinessRuleViolation PETTY_CASH_NOTHING_TO_REPLENISH | PETTY_CASH_REPLENISHMENT_PENDING
     */
    public function requestReplenishment(string $floatId, string $bankAccountId, string $actorUserId, ?CarbonImmutable $requestedOn = null): string
    {
        $float = $this->float($floatId);
        $this->permissions->authorize($actorUserId, 'pettycash.replenish', AuthorizationScope::branch((string) $float->entity_id, (string) $float->branch_id));
        $this->bankAccounts->glAccountFor($bankAccountId, (string) $float->entity_id, (string) $float->currency);
        $number = $this->numberer->reserve(new DocumentNumberScope((string) $float->entity_id, (string) $float->branch_id, 'petty_cash_replenishment', 'PCR', $requestedOn ?? CarbonImmutable::parse((string) $float->issued_on)), $actorUserId);

        return DB::transaction(function () use ($floatId, $bankAccountId, $actorUserId, $number): string {
            DB::table('petty_cash_floats')->where('id', $floatId)->lockForUpdate()->value('id');
            if (DB::table('petty_cash_replenishments')->where('float_id', $floatId)->where('status', 'pending_approval')->exists()) {
                throw new BusinessRuleViolation('PETTY_CASH_REPLENISHMENT_PENDING', 'A replenishment of this float is already waiting for approval.');
            }
            $vouchers = DB::table('petty_cash_vouchers')->where('float_id', $floatId)->where('status', 'posted')->whereNull('replenishment_id');
            $amount = (int) (clone $vouchers)->sum('amount_minor');
            if ($amount <= 0) {
                throw new BusinessRuleViolation('PETTY_CASH_NOTHING_TO_REPLENISH', 'No vouchers have been paid since the last replenishment.');
            }
            $id = (string) Str::uuid7();
            DB::table('petty_cash_replenishments')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'float_id' => $floatId, 'number' => $number->number, 'amount_minor' => $amount,
                'bank_account_id' => $bankAccountId, 'status' => 'pending_approval', 'requested_by' => $actorUserId, 'requested_at' => now()]);
            $this->numberer->markUsed($number->id, 'petty_cash_replenishment', $id);
            $vouchers->update(['replenishment_id' => $id]);
            $this->audit->record('petty_cash_replenishment.requested', AuditSubject::of('petty_cash_replenishment', $id), null, ['number' => $number->number, 'amount_minor' => $amount],
                null, 'pettycash.replenish', Actor::user($actorUserId));

            return $id;
        });
    }

    /** @throws BusinessRuleViolation PETTY_CASH_NOT_PENDING | ASSET_PERIOD_NOT_OPEN | MAKER_CHECKER */
    public function approveReplenishment(string $replenishmentId, CarbonImmutable $paidOn, string $actorUserId): void
    {
        [$replenishment, $float] = $this->decidable($replenishmentId, $actorUserId);
        $this->assertOpen((string) $float->entity_id, $paidOn);
        DB::transaction(function () use ($replenishment, $float, $paidOn, $actorUserId): void {
            $updated = DB::table('petty_cash_replenishments')->where('id', $replenishment->id)->where('status', 'pending_approval')
                ->update(['status' => 'paid', 'decided_by' => $actorUserId, 'decided_at' => now(), 'paid_on' => $paidOn->toDateString()]);
            if ($updated !== 1) {
                throw new BusinessRuleViolation('PETTY_CASH_NOT_PENDING', 'This replenishment has already been decided. Refresh the page.');
            }
            ($this->submit)(entityId: (string) $float->entity_id, eventType: 'PETTY_CASH_REPLENISHED', sourceType: 'petty_cash_replenishment', sourceId: (string) $replenishment->id,
                idempotencyKey: 'PETTY_CASH_REPLENISHED:'.$replenishment->id, transactionDate: $paidOn, effectiveDate: $paidOn, currency: (string) $float->currency,
                payload: ['amount' => (int) $replenishment->amount_minor, 'petty_cash_replenishment_id' => (string) $replenishment->id, 'reference' => (string) $replenishment->number,
                    'bank_account_id' => (string) $replenishment->bank_account_id, 'account_overrides' => ['petty_cash' => (string) $float->gl_account_id,
                        'bank_main' => $this->bankAccounts->glAccountFor((string) $replenishment->bank_account_id, (string) $float->entity_id, (string) $float->currency)]],
                dimensions: ['branch' => (string) $float->branch_id, 'petty_cash_float' => (string) $float->id]);
            $this->audit->record('petty_cash_replenishment.approved', AuditSubject::of('petty_cash_replenishment', (string) $replenishment->id), ['status' => 'pending_approval'],
                ['status' => 'paid', 'paid_on' => $paidOn->toDateString()], null, 'pettycash.approve', Actor::user($actorUserId));
        });
    }

    /** @throws BusinessRuleViolation PETTY_CASH_NOT_PENDING */
    public function rejectReplenishment(string $replenishmentId, string $reason, string $actorUserId): void
    {
        [$replenishment] = $this->decidable($replenishmentId, $actorUserId);
        DB::transaction(function () use ($replenishment, $reason, $actorUserId): void {
            DB::table('petty_cash_replenishments')->where('id', $replenishment->id)->update(['status' => 'rejected', 'decided_by' => $actorUserId, 'decided_at' => now(), 'decision_reason' => $reason]);
            DB::table('petty_cash_vouchers')->where('replenishment_id', $replenishment->id)->update(['replenishment_id' => null]);
            $this->audit->record('petty_cash_replenishment.rejected', AuditSubject::of('petty_cash_replenishment', (string) $replenishment->id), ['status' => 'pending_approval'], ['status' => 'rejected'],
                $reason, 'pettycash.approve', Actor::user($actorUserId));
        });
    }

    /**
     * A surprise count by someone other than the custodian: the difference from the book is posted (shortage to petty cash over/short).
     *
     * @throws BusinessRuleViolation PETTY_CASH_CUSTODIAN_COUNT | INVALID_AMOUNT | ASSET_PERIOD_NOT_OPEN
     */
    public function count(string $floatId, CarbonImmutable $countedOn, int $countedMinor, ?string $note, string $actorUserId): string
    {
        $float = $this->float($floatId);
        $this->permissions->authorize($actorUserId, 'pettycash.replenish', AuthorizationScope::branch((string) $float->entity_id, (string) $float->branch_id));
        if ($float->custodian_user_id === $actorUserId) {
            throw new BusinessRuleViolation('PETTY_CASH_CUSTODIAN_COUNT', 'The custodian cannot count their own float. Ask someone from accounts to count it.');
        }
        if ($countedMinor < 0) {
            throw new BusinessRuleViolation('INVALID_AMOUNT', 'Enter the cash counted as zero or more.');
        }
        $this->assertOpen((string) $float->entity_id, $countedOn);

        return DB::transaction(function () use ($floatId, $countedOn, $countedMinor, $note, $actorUserId): string {
            $float = DB::table('petty_cash_floats')->where('id', $floatId)->lockForUpdate()->first() ?? throw new \LogicException('Float missing.');
            $expected = $this->cashOnHand($floatId);
            $difference = $countedMinor - $expected;
            $id = (string) Str::uuid7();
            DB::table('petty_cash_counts')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'float_id' => $floatId, 'counted_on' => $countedOn->toDateString(), 'counted_minor' => $countedMinor,
                'expected_minor' => $expected, 'difference_minor' => $difference, 'counted_by' => $actorUserId, 'note' => $note]);
            if ($difference !== 0) {
                ($this->submit)(entityId: (string) $float->entity_id, eventType: 'PETTY_CASH_COUNT_DIFFERENCE', sourceType: 'petty_cash_count', sourceId: $id, idempotencyKey: 'PETTY_CASH_COUNT_DIFFERENCE:'.$id,
                    transactionDate: $countedOn, effectiveDate: $countedOn, currency: (string) $float->currency,
                    payload: ['shortage' => -$difference, 'petty_cash_count_id' => $id, 'reference' => (string) $float->code, 'account_overrides' => ['petty_cash' => (string) $float->gl_account_id]],
                    dimensions: ['branch' => (string) $float->branch_id, 'petty_cash_float' => $floatId]);
            }
            $this->audit->record('petty_cash.counted', AuditSubject::of('petty_cash_float', $floatId), null, ['counted_minor' => $countedMinor, 'expected_minor' => $expected, 'difference_minor' => $difference],
                $note, 'pettycash.replenish', Actor::user($actorUserId));

            return $id;
        });
    }

    /** Cash the float should hold: the float issued, plus replenishments paid, less vouchers paid, plus count differences. */
    public function cashOnHand(string $floatId, ?CarbonImmutable $asOf = null): int
    {
        return array_sum(array_column($this->movements($floatId, null, $asOf), 'amount_minor'));
    }

    /**
     * The float's cash book entries in date order, signed (+ cash in, − cash out).
     *
     * @return list<array{date: string, kind: string, number: string, description: string, account: string|null, amount_minor: int, id: string}>
     */
    public function movements(string $floatId, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $float = $this->float($floatId);
        $entries = [['date' => (string) $float->issued_on, 'kind' => 'float', 'number' => (string) $float->code, 'description' => 'Float issued from the bank', 'account' => null,
            'amount_minor' => (int) $float->imprest_minor, 'id' => (string) $float->id]];
        foreach (DB::table('petty_cash_vouchers as v')->join('accounts as a', 'a.id', '=', 'v.account_id')->where('v.float_id', $floatId)->where('v.status', 'posted')
            ->get(['v.id', 'v.voucher_date', 'v.number', 'v.payee', 'v.description', 'a.code', 'a.name', 'v.amount_minor']) as $v) {
            $entries[] = ['date' => (string) $v->voucher_date, 'kind' => 'voucher', 'number' => (string) $v->number, 'description' => "{$v->payee}: {$v->description}", 'account' => "{$v->code} {$v->name}",
                'amount_minor' => -(int) $v->amount_minor, 'id' => (string) $v->id];
        }
        foreach (DB::table('petty_cash_replenishments')->where('float_id', $floatId)->where('status', 'paid')->get(['id', 'paid_on', 'number', 'amount_minor']) as $r) {
            $entries[] = ['date' => (string) $r->paid_on, 'kind' => 'replenishment', 'number' => (string) $r->number, 'description' => 'Replenished from the bank', 'account' => null,
                'amount_minor' => (int) $r->amount_minor, 'id' => (string) $r->id];
        }
        foreach (DB::table('petty_cash_counts')->where('float_id', $floatId)->where('difference_minor', '<>', 0)->get(['id', 'counted_on', 'difference_minor']) as $c) {
            $entries[] = ['date' => (string) $c->counted_on, 'kind' => 'count', 'number' => 'Count', 'description' => (int) $c->difference_minor < 0 ? 'Cash count shortage' : 'Cash count surplus',
                'account' => null, 'amount_minor' => (int) $c->difference_minor, 'id' => (string) $c->id];
        }
        $entries = array_values(array_filter($entries, fn (array $e): bool => ($from === null || $e['date'] >= $from->toDateString()) && ($to === null || $e['date'] <= $to->toDateString())));
        usort($entries, fn (array $a, array $b): int => [$a['date'], $a['kind'] === 'float' ? 0 : 1, $a['number']] <=> [$b['date'], $b['kind'] === 'float' ? 0 : 1, $b['number']]);

        return $entries;
    }

    private function float(string $floatId): \stdClass
    {
        return DB::table('petty_cash_floats')->where('id', $floatId)->first() ?? abort(404);
    }

    /** @return array{0: \stdClass, 1: \stdClass} */
    private function decidable(string $replenishmentId, string $actorUserId): array
    {
        $replenishment = DB::table('petty_cash_replenishments')->where('id', $replenishmentId)->first() ?? abort(404);
        $float = $this->float((string) $replenishment->float_id);
        $this->permissions->authorize($actorUserId, 'pettycash.approve', AuthorizationScope::branch((string) $float->entity_id, (string) $float->branch_id));
        if ($replenishment->requested_by === $actorUserId) {
            throw new BusinessRuleViolation('MAKER_CHECKER', 'You asked for this replenishment, so someone else has to approve it.');
        }
        $this->sod->assert($actorUserId, 'pettycash.approve', AuditSubject::of('petty_cash_replenishment', $replenishmentId));
        if ($replenishment->status !== 'pending_approval') {
            throw new BusinessRuleViolation('PETTY_CASH_NOT_PENDING', 'This replenishment has already been decided. Refresh the page.');
        }

        return [$replenishment, $float];
    }

    private function assertOpen(string $entityId, CarbonImmutable $date): void
    {
        $period = $this->periods->containing($entityId, $date);
        if ($period === null || ! $period->isOpen()) {
            throw new BusinessRuleViolation('ASSET_PERIOD_NOT_OPEN', 'That date is in a month that is not open for posting. Choose a date in an open month.');
        }
    }
}
