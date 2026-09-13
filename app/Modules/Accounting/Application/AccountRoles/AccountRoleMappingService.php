<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\AccountRoles;

use App\Modules\Accounting\Application\PostingRuleRepository;
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
 * Account role → account mappings per entity and book (fix F4, design §2.2 account_role_mappings, §3.4): list them with their history, map or remap
 * a role from a date, and find the roles the posting rules use that have no account.
 *
 * Mappings are effective-dated like the posting context reads them: [effective_from, effective_to), effective_to not included. Remapping from a date
 * ends the mapping in force there (its last day is the day before) and inserts the new one; mappings never overlap, a later mapping is never
 * overwritten, and a remap never starts on or before a day already posted with the role (history is not rewritten, DECISION D-27).
 *
 * ASSUMPTION: A-56 — the account must be active, postable and of the entity; a control account goes only with a role its subledger reconciles to
 * (subledger_controls), and such a control role only with a control account.
 */
final class AccountRoleMappingService
{
    public const PERMISSION = 'accounting.manage_coa';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
        private readonly PostingRuleRepository $rules,
    ) {}

    /**
     * Every account role with the mapping in force on $on, its history, and whether the posting rules in force use it.
     *
     * @return list<array{code: string, description: string, used_by_rules: list<string>, account_id: string|null, account_code: string|null, account_name: string|null,
     *     effective_from: string|null, effective_to: string|null, control_subledger: string|null,
     *     history: list<array{account_code: string, account_name: string, effective_from: string, effective_to: string|null}>}>
     */
    public function roles(string $entityId, string $bookId, CarbonImmutable $on): array
    {
        $used = $this->rolesUsedByRules($bookId, $on);
        $controls = $this->controlRoles($entityId, $bookId);
        $mappings = DB::table('account_role_mappings as m')->join('accounts as a', 'a.id', '=', 'm.account_id')
            ->where('m.entity_id', $entityId)->where('m.book_id', $bookId)->orderBy('m.role_code')->orderByDesc('m.effective_from')
            ->get(['m.role_code', 'm.account_id', 'a.code', 'a.name', 'm.effective_from', 'm.effective_to'])->groupBy('role_code');
        $day = $on->toDateString();

        $rows = [];
        foreach (DB::table('account_roles')->orderBy('code')->get(['code', 'description']) as $role) {
            $code = (string) $role->code;
            $history = $mappings->get($code, collect());
            $current = $history->first(fn (object $m): bool => (string) $m->effective_from <= $day && ($m->effective_to === null || (string) $m->effective_to > $day));
            $rows[] = ['code' => $code, 'description' => self::plain((string) $role->description), 'used_by_rules' => $used[$code] ?? [],
                'account_id' => $current === null ? null : (string) $current->account_id, 'account_code' => $current === null ? null : (string) $current->code,
                'account_name' => $current === null ? null : (string) $current->name, 'effective_from' => $current === null ? null : (string) $current->effective_from,
                'effective_to' => $current === null || $current->effective_to === null ? null : (string) $current->effective_to, 'control_subledger' => $controls[$code] ?? null,
                'history' => array_values($history->map(fn (object $m): array => ['account_code' => (string) $m->code, 'account_name' => (string) $m->name,
                    'effective_from' => (string) $m->effective_from, 'effective_to' => $m->effective_to === null ? null : (string) $m->effective_to])->all())];
        }

        return $rows;
    }

    /**
     * Roles the posting rules in force on $on (for this book) use, with no mapping in force that day.
     *
     * @return list<array{code: string, description: string, used_by_rules: list<string>}>
     */
    public function unmappedRoles(string $entityId, string $bookId, CarbonImmutable $on): array
    {
        $day = $on->toDateString();
        $mapped = DB::table('account_role_mappings')->where('entity_id', $entityId)->where('book_id', $bookId)->where('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $day))->pluck('role_code')->map(fn (mixed $c): string => (string) $c)->all();
        $descriptions = DB::table('account_roles')->pluck('description', 'code')->map(fn (mixed $d): string => self::plain((string) $d))->all();
        $unmapped = [];
        foreach ($this->rolesUsedByRules($bookId, $on) as $code => $events) {
            if (! in_array($code, $mapped, true)) {
                $unmapped[] = ['code' => $code, 'description' => $descriptions[$code] ?? $code, 'used_by_rules' => $events];
            }
        }

        return $unmapped;
    }

    /**
     * Maps $roleCode to $accountId from $from. Returns the new mapping id.
     *
     * @throws BusinessRuleViolation ROLE_MAPPING_INVALID | ROLE_MAPPING_OVERLAP | ROLE_ALREADY_POSTED
     */
    public function map(string $entityId, string $bookId, string $roleCode, string $accountId, CarbonImmutable $from, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::entity($entityId));

        return DB::transaction(function () use ($entityId, $bookId, $roleCode, $accountId, $from, $actorUserId): string {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["account_role_mappings:{$entityId}:{$bookId}:{$roleCode}"]);
            $this->assertMappable($entityId, $bookId, $roleCode, $accountId);
            $day = $from->toDateString();
            $later = DB::table('account_role_mappings')->where('entity_id', $entityId)->where('book_id', $bookId)->where('role_code', $roleCode)
                ->where('effective_from', '>=', $day)->orderBy('effective_from')->value('effective_from');
            if ($later !== null) {
                throw new BusinessRuleViolation('ROLE_MAPPING_OVERLAP', 'This role already has a mapping from '.CarbonImmutable::parse((string) $later)->format('j M Y')
                    .'. Choose a date after it.');
            }
            /** @var object{id: string, account_id: string, effective_from: string, effective_to: string|null}|null $current */
            $current = DB::table('account_role_mappings')->where('entity_id', $entityId)->where('book_id', $bookId)->where('role_code', $roleCode)
                ->where('effective_from', '<', $day)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $day))
                ->lockForUpdate()->first(['id', 'account_id', 'effective_from', 'effective_to']);
            if ($current !== null && $current->account_id === $accountId) {
                throw new BusinessRuleViolation('ROLE_MAPPING_INVALID', 'The role already goes to this account on that date.');
            }
            $posted = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.entity_id', $entityId)->where('j.book_id', $bookId)
                ->where('l.role_code', $roleCode)->whereIn('j.status', ['posted', 'reversed'])->where('j.effective_date', '>=', $day)->max('j.effective_date');
            if ($posted !== null) {
                throw new BusinessRuleViolation('ROLE_ALREADY_POSTED', 'Journals up to '.CarbonImmutable::parse((string) $posted)->format('j M Y')
                    .' already posted with this role to its current account. Choose a date after that.');
            }

            if ($current !== null) {
                DB::table('account_role_mappings')->where('id', $current->id)->update(['effective_to' => $day]);
            }
            $id = (string) Str::uuid7();
            DB::table('account_role_mappings')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId, 'book_id' => $bookId,
                'role_code' => $roleCode, 'account_id' => $accountId, 'effective_from' => $day, 'effective_to' => $current?->effective_to]);
            $this->audit->record('account_role.mapped', AuditSubject::of('legal_entity', $entityId),
                $current === null ? null : ['role' => $roleCode, 'book_id' => $bookId, 'account_id' => $current->account_id, 'effective_from' => $current->effective_from, 'effective_to' => $day],
                ['role' => $roleCode, 'book_id' => $bookId, 'account_id' => $accountId, 'effective_from' => $day, 'mapping_id' => $id], null, self::PERMISSION, Actor::user($actorUserId));

            return $id;
        });
    }

    /**
     * Roles used by the lines (and rounding residual) of the posting rules in force on $on that post to $bookId's book, with the event types using each.
     *
     * @return array<string, list<string>>
     */
    public function rolesUsedByRules(string $bookId, CarbonImmutable $on): array
    {
        $bookCode = (string) DB::table('books')->where('id', $bookId)->value('code');
        $used = [];
        foreach ($this->rules->all() as $rule) {
            if (! $rule->isEffectiveOn($on) || ! in_array($bookCode, $rule->books, true)) {
                continue;
            }
            foreach ([...array_column($rule->lines, 'role'), $rule->roundingResidualRole] as $role) {
                $used[$role][$rule->eventType] = true;
            }
        }
        ksort($used);

        return array_map(fn (array $events): array => array_keys($events), $used);
    }

    /** @throws BusinessRuleViolation ROLE_MAPPING_INVALID */
    private function assertMappable(string $entityId, string $bookId, string $roleCode, string $accountId): void
    {
        if (! DB::table('account_roles')->where('code', $roleCode)->exists() || ! DB::table('books')->where('id', $bookId)->exists()) {
            throw new BusinessRuleViolation('ROLE_MAPPING_INVALID', 'Choose a known account role and book.');
        }
        /** @var object{entity_id: string, status: string, is_postable: bool, is_control: bool, control_subledger: string|null}|null $account */
        $account = DB::table('accounts')->where('id', $accountId)->first(['entity_id', 'status', 'is_postable', 'is_control', 'control_subledger']);
        if ($account === null || $account->entity_id !== $entityId || $account->status !== 'active' || ! $account->is_postable) {
            throw new BusinessRuleViolation('ROLE_MAPPING_INVALID', 'Choose an active account of this entity that takes postings.');
        }
        $controlSubledger = $this->controlRoles($entityId, $bookId)[$roleCode] ?? null;
        if ($controlSubledger !== null && ! $account->is_control) {
            throw new BusinessRuleViolation('ROLE_MAPPING_INVALID', "This role is reconciled to the {$controlSubledger} subledger, so it needs a control account.");
        }
        if ($controlSubledger === null && $account->is_control) {
            throw new BusinessRuleViolation('ROLE_MAPPING_INVALID', 'This is a control account of the '.($account->control_subledger ?? 'a').' subledger; map it only to the role that subledger reconciles to.');
        }
    }

    /** @return array<string, string> control role → subledger */
    private function controlRoles(string $entityId, string $bookId): array
    {
        return DB::table('subledger_controls')->where('entity_id', $entityId)->where('book_id', $bookId)->pluck('subledger', 'control_account_role')
            ->mapWithKeys(fn (mixed $subledger, mixed $role): array => [(string) $role => (string) $subledger])->all();
    }

    /** Role descriptions without their design-note asides ("(Distribution D6)", "(LATER)"). */
    private static function plain(string $description): string
    {
        return (string) preg_replace('/\s*\([^)]*\)\s*$/', '', $description);
    }
}
