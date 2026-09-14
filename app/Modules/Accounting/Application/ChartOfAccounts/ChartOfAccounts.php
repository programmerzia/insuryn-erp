<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\ChartOfAccounts;

use App\Modules\Accounting\Application\Contracts\AccountUsage;
use App\Modules\Accounting\Application\Imports\ImportMode;
use App\Modules\Accounting\Application\Imports\ImportOutcome;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalStatus;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Money\MinorUnits;
use App\Modules\Platform\Tenancy\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Accounting → Chart of accounts (UX U2): add an account, change its name, parent, postability, type and normal side, deactivate and reactivate it.
 * A new account passes the same rules as the chart-of-accounts import (ChartOfAccountsRules). Every change needs accounting.manage_coa in the
 * account's entity and is audited.
 *
 * ASSUMPTION: A-167 — once any journal line names the account (in any status), its type and normal side stay, and it stays postable: the lines
 * were written against them. The code never changes (reports and imports refer to it).
 * ASSUMPTION: A-168 — an account is deactivated only with nothing left on it: no balance in any currency or book, no journal still on its way to
 * the ledger, no account role mapped to it today or later, nothing else posting to it directly (an open bank account: AccountUsage) and no active child.
 */
final class ChartOfAccounts
{
    public const PERMISSION = 'accounting.manage_coa';

    /** Journal statuses that are ledger history (LedgerQuery). */
    private const LEDGER_STATUSES = [JournalStatus::Posted->value, JournalStatus::Reversed->value];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
        private readonly ChartOfAccountsRules $rules,
        /** @var iterable<AccountUsage> */
        private readonly iterable $usages = [],
    ) {}

    /**
     * @param array{code: string, name: string, type: string, normal_side: string, parent_id: string|null, is_postable: bool, is_control: bool, control_subledger: string|null, currency: string|null} $input
     *
     * @throws AccountInvalid with the field errors the rules found
     */
    public function create(string $entityId, array $input, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::entity($entityId));
        $parentCode = null;
        $outcome = new ImportOutcome('account', ImportMode::Commit);
        if ($input['parent_id'] !== null) {
            $parentCode = DB::table('accounts')->where('id', $input['parent_id'])->where('entity_id', $entityId)->value('code');
            if (! is_string($parentCode)) {
                throw new AccountInvalid(['parent_id' => 'Choose a parent account from this chart.']);
            }
        }
        $row = ['code' => trim($input['code']), 'name' => trim($input['name']), 'type' => $input['type'], 'normal_side' => $input['normal_side'], 'parent_code' => $parentCode ?? '',
            'is_postable' => $input['is_postable'] ? 'true' : 'false', 'is_control' => $input['is_control'] ? 'true' : 'false',
            'control_subledger' => (string) $input['control_subledger'], 'currency' => (string) $input['currency'], 'role' => ''];

        return DB::transaction(function () use ($row, $entityId, $outcome, $actorUserId): string {
            $accounts = $this->rules->checkNew([1 => $row], $entityId, $outcome, fromImport: false);
            if ($outcome->hasErrors()) {
                $errors = [];
                foreach ($outcome->toArray()['errors'] as $error) {
                    $errors[$error['field'] === 'parent_code' ? 'parent_id' : $error['field']] ??= $error['message'];
                }
                throw new AccountInvalid($errors);
            }
            $id = $this->rules->insertNew($accounts, $entityId)[$row['code']];
            $this->audit->record('account.created', AuditSubject::of('account', $id), null, $this->snapshot($id), null, self::PERMISSION, Actor::user($actorUserId));

            return $id;
        });
    }

    /**
     * ASSUMPTION: A-169 — the control flag, subledger and currency of an existing account are not changed here: they decide reconciliation and which
     * journals may post. A wrong one is corrected with a new account and a journal moving the balance.
     *
     * @param array{name: string, parent_id: string|null, is_postable: bool, type: string, normal_side: string} $changes
     *
     * @throws AccountInvalid | BusinessRuleViolation ACCOUNT_NOT_FOUND | ACCOUNT_HAS_POSTINGS | ACCOUNT_ROLE_MAPPED
     */
    public function update(string $accountId, array $changes, string $actorUserId): void
    {
        DB::transaction(function () use ($accountId, $changes, $actorUserId): void {
            $account = $this->lockedAccount($accountId, $actorUserId);
            $errors = [];
            $name = trim($changes['name']);
            if ($name === '' || mb_strlen($name) > 255) {
                $errors['name'] = $name === '' ? 'Account name is required.' : 'Keep the name to 255 characters.';
            }
            if (AccountType::tryFrom($changes['type']) === null) {
                $errors['type'] = 'Type must be one of: '.implode(', ', array_column(AccountType::cases(), 'value')).'.';
            }
            if (Side::tryFrom($changes['normal_side']) === null) {
                $errors['normal_side'] = 'Normal side must be debit or credit.';
            }
            if ($changes['parent_id'] !== null && ($parentError = $this->parentError($account, $changes['parent_id'])) !== null) {
                $errors['parent_id'] = $parentError;
            }
            if ($errors !== []) {
                throw new AccountInvalid($errors);
            }

            $hasLines = DB::table('journal_lines')->where('account_id', $accountId)->exists();
            if ($hasLines && ($changes['type'] !== $account->type || $changes['normal_side'] !== $account->normal_side)) {
                throw new BusinessRuleViolation('ACCOUNT_HAS_POSTINGS', "Account {$account->code} already has journal lines, so its type and normal side stay as they are. Open a new account and move the balance with a journal.");
            }
            if (! $changes['is_postable'] && $account->is_postable) {
                if ($hasLines) {
                    throw new BusinessRuleViolation('ACCOUNT_HAS_POSTINGS', "Account {$account->code} already has journal lines, so it stays postable.");
                }
                $this->refuseWhenRoleMapped($account, 'made a heading');
            }

            $before = $this->snapshot($accountId);
            DB::table('accounts')->where('id', $accountId)->update(['name' => $name, 'parent_id' => $changes['parent_id'], 'is_postable' => $changes['is_postable'],
                'type' => $changes['type'], 'normal_side' => $changes['normal_side'], 'updated_at' => now()]);
            $after = $this->snapshot($accountId);
            $changed = array_keys(array_filter($after, fn (mixed $value, string $key): bool => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));
            if ($changed !== []) {
                $this->audit->record('account.updated', AuditSubject::of('account', $accountId), array_intersect_key($before, array_flip($changed)),
                    array_intersect_key($after, array_flip($changed)), null, self::PERMISSION, Actor::user($actorUserId));
            }
        });
    }

    /** @throws BusinessRuleViolation ACCOUNT_NOT_FOUND | ACCOUNT_HAS_BALANCE | ACCOUNT_IN_OPEN_JOURNAL | ACCOUNT_ROLE_MAPPED | ACCOUNT_IN_USE | ACCOUNT_HAS_ACTIVE_CHILDREN */
    public function deactivate(string $accountId, string $actorUserId, CarbonImmutable $today): void
    {
        DB::transaction(function () use ($accountId, $actorUserId, $today): void {
            $account = $this->lockedAccount($accountId, $actorUserId);
            if ($account->status !== 'active') {
                return;
            }
            $balances = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('l.account_id', $accountId)
                ->whereIn('j.status', self::LEDGER_STATUSES)->groupBy('l.currency')->havingRaw("sum(case when l.side = 'debit' then l.amount_minor else -l.amount_minor end) <> 0")
                ->selectRaw("l.currency, sum(case when l.side = 'debit' then l.amount_minor else -l.amount_minor end) as balance")->get();
            if ($balances->isNotEmpty()) {
                $amounts = $balances->map(fn (object $b): string => MinorUnits::format(abs((int) $b->balance), (string) $b->currency).' '.$b->currency.' '.((int) $b->balance > 0 ? 'debit' : 'credit'))->implode(', ');
                throw new BusinessRuleViolation('ACCOUNT_HAS_BALANCE', "Account {$account->code} has a balance of {$amounts}. Move it to another account with a journal, then deactivate.");
            }
            $open = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('l.account_id', $accountId)
                ->whereNotIn('j.status', [...self::LEDGER_STATUSES, JournalStatus::Cancelled->value])->exists();
            if ($open) {
                throw new BusinessRuleViolation('ACCOUNT_IN_OPEN_JOURNAL', "A journal that is not posted yet uses account {$account->code}. Post, reject or cancel it first.");
            }
            $this->refuseWhenRoleMapped($account, 'deactivated', $today);
            foreach ($this->usages as $usage) {
                if (($reason = $usage->blocksDeactivation($accountId, $account->code)) !== null) {
                    throw new BusinessRuleViolation('ACCOUNT_IN_USE', $reason);
                }
            }
            $child = DB::table('accounts')->where('parent_id', $accountId)->where('status', 'active')->orderBy('code')->value('code');
            if ($child !== null) {
                throw new BusinessRuleViolation('ACCOUNT_HAS_ACTIVE_CHILDREN', "Account {$child} sits under {$account->code} and is active. Deactivate or move it first.");
            }
            DB::table('accounts')->where('id', $accountId)->update(['status' => 'inactive', 'updated_at' => now()]);
            $this->audit->record('account.deactivated', AuditSubject::of('account', $accountId), ['status' => 'active'], ['status' => 'inactive'], null, self::PERMISSION, Actor::user($actorUserId));
        });
    }

    /** @throws BusinessRuleViolation ACCOUNT_NOT_FOUND */
    public function reactivate(string $accountId, string $actorUserId): void
    {
        DB::transaction(function () use ($accountId, $actorUserId): void {
            $account = $this->lockedAccount($accountId, $actorUserId);
            if ($account->status === 'active') {
                return;
            }
            DB::table('accounts')->where('id', $accountId)->update(['status' => 'active', 'updated_at' => now()]);
            $this->audit->record('account.reactivated', AuditSubject::of('account', $accountId), ['status' => $account->status], ['status' => 'active'], null, self::PERMISSION, Actor::user($actorUserId));
        });
    }

    /** @return object{id: string, entity_id: string, code: string, type: string, normal_side: string, is_postable: bool, status: string} */
    private function lockedAccount(string $accountId, string $actorUserId): object
    {
        /** @var object{id: string, entity_id: string, code: string, type: string, normal_side: string, is_postable: bool, status: string}|null $account */
        $account = DB::table('accounts')->where('id', $accountId)->lockForUpdate()->first(['id', 'entity_id', 'code', 'type', 'normal_side', 'is_postable', 'status']);
        if ($account === null) {
            throw new BusinessRuleViolation('ACCOUNT_NOT_FOUND', 'The account is not in this chart of accounts.');
        }
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::entity((string) $account->entity_id));

        return $account;
    }

    /** @param object{id: string, entity_id: string, code: string} $account */
    private function parentError(object $account, string $parentId): ?string
    {
        if ($parentId === $account->id) {
            return 'An account cannot sit under itself.';
        }
        $parents = DB::table('accounts')->where('entity_id', $account->entity_id)->pluck('parent_id', 'id')->map(fn (mixed $p): ?string => $p === null ? null : (string) $p)->all();
        if (! array_key_exists($parentId, $parents)) {
            return 'Choose a parent account from this chart.';
        }
        for ($cursor = $parentId, $guard = 0; $cursor !== null && $guard <= count($parents); $cursor = $parents[$cursor] ?? null, $guard++) {
            if ($cursor === $account->id) {
                return "That account sits under {$account->code}; an account cannot sit under its own child.";
            }
        }

        return null;
    }

    /** @param object{id: string, code: string} $account */
    private function refuseWhenRoleMapped(object $account, string $what, ?CarbonImmutable $today = null): void
    {
        $day = ($today ?? app(BusinessClock::class)->today())->toDateString();
        $role = DB::table('account_role_mappings as m')->leftJoin('account_roles as r', 'r.code', '=', 'm.role_code')->where('m.account_id', $account->id)
            ->where(fn ($q) => $q->whereNull('m.effective_to')->orWhere('m.effective_to', '>', $day))->orderBy('m.role_code')->first(['m.role_code', 'r.description']);
        if ($role !== null) {
            $name = (string) preg_replace('/\s*\([^)]*\)\s*$/', '', (string) ($role->description ?? $role->role_code));
            throw new BusinessRuleViolation('ACCOUNT_ROLE_MAPPED', "Account {$account->code} is where the accounting posts \"{$name}\", so it cannot be {$what}. Map that role to another account first in Accounting → Account roles.");
        }
    }

    /** @return array{code: string, name: string, type: string, normal_side: string, parent_id: string|null, is_postable: bool, is_control: bool, control_subledger: string|null, currency: string|null, status: string} */
    private function snapshot(string $accountId): array
    {
        /** @var object{code: string, name: string, type: string, normal_side: string, parent_id: string|null, is_postable: bool, is_control: bool, control_subledger: string|null, currency: string|null, status: string} $a */
        $a = DB::table('accounts')->where('id', $accountId)->first(['code', 'name', 'type', 'normal_side', 'parent_id', 'is_postable', 'is_control', 'control_subledger', 'currency', 'status']);

        return ['code' => (string) $a->code, 'name' => (string) $a->name, 'type' => (string) $a->type, 'normal_side' => (string) $a->normal_side,
            'parent_id' => $a->parent_id === null ? null : (string) $a->parent_id, 'is_postable' => (bool) $a->is_postable, 'is_control' => (bool) $a->is_control,
            'control_subledger' => $a->control_subledger === null ? null : (string) $a->control_subledger, 'currency' => $a->currency === null ? null : (string) $a->currency,
            'status' => (string) $a->status];
    }
}
