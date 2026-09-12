<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Imports;

use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Imports\CsvTable;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Chart-of-accounts CSV import (design §9.3 "COA import", spec §7 import pipeline). New accounts only:
 * a code that already exists in the entity is an error, never an update. Optional `role` maps the account
 * to a semantic account role in the primary book from today.
 */
final class ChartOfAccountsImport
{
    public const TYPE = 'chart-of-accounts';

    private const SUBLEDGERS = ['premium', 'claims', 'commission', 'customer', 'agent', 'bank', 'ap', 'ar', 'suspense'];

    private const BOOLEANS = ['true' => true, '1' => true, 'yes' => true, 'false' => false, '0' => false, 'no' => false];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    public function run(string $csv, string $entityId, ImportMode $mode, string $actorUserId): ImportOutcome
    {
        $this->permissions->authorize($actorUserId, 'accounting.manage_coa', AuthorizationScope::entity($entityId));
        $outcome = new ImportOutcome(self::TYPE, $mode);
        try {
            $table = CsvTable::parse($csv, (array) config('erp.imports.chart_of_accounts'), ['code', 'name', 'type', 'normal_side'], (int) config('erp.imports.max_rows'));
        } catch (InvalidArgumentException $e) {
            $outcome->addError(0, 'file', $e->getMessage());

            return $outcome;
        }

        $accounts = $this->validate($table, $entityId, $outcome);
        $outcome->preview = ['accounts_to_create' => count($accounts), 'rows' => array_values($accounts)];
        if ($mode === ImportMode::Commit && ! $outcome->hasErrors()) {
            $outcome->result = ['accounts_created' => $this->commit($accounts, $entityId, $actorUserId)];
        }

        return $outcome;
    }

    /**
     * @return array<string, array{code: string, name: string, type: string, normal_side: string, parent_code: string|null, is_postable: bool, is_control: bool, control_subledger: string|null, currency: string|null, role: string|null}>
     */
    private function validate(CsvTable $table, string $entityId, ImportOutcome $outcome): array
    {
        $existingCodes = DB::table('accounts')->where('entity_id', $entityId)->pluck('code')->map(fn (mixed $c): string => (string) $c)->all();
        $fileCodes = array_map(fn (array $row): string => $row['code'] ?? '', $table->rows);
        $knownRoles = DB::table('account_roles')->pluck('code')->map(fn (mixed $c): string => (string) $c)->all();

        $accounts = [];
        $seenRoles = [];
        foreach ($table->rows as $rowNo => $row) {
            $code = $row['code'] ?? '';
            $isControl = $this->boolean($row, 'is_control', false, $rowNo, $outcome);
            $account = [
                'code' => $code, 'name' => $row['name'] ?? '', 'type' => strtolower($row['type'] ?? ''), 'normal_side' => strtolower($row['normal_side'] ?? ''),
                'parent_code' => ($row['parent_code'] ?? '') === '' ? null : $row['parent_code'],
                'is_postable' => $this->boolean($row, 'is_postable', true, $rowNo, $outcome), 'is_control' => $isControl,
                'control_subledger' => ($row['control_subledger'] ?? '') === '' ? null : strtolower($row['control_subledger']),
                'currency' => ($row['currency'] ?? '') === '' ? null : strtoupper($row['currency']),
                'role' => ($row['role'] ?? '') === '' ? null : $row['role'],
            ];

            match (true) {
                $code === '' => $outcome->addError($rowNo, 'code', 'Account code is required.'),
                in_array($code, $existingCodes, true) => $outcome->addError($rowNo, 'code', "Account {$code} already exists; imports never update accounts."),
                isset($accounts[$code]) => $outcome->addError($rowNo, 'code', "Account code {$code} appears more than once in the file."),
                default => null,
            };
            if ($account['name'] === '') {
                $outcome->addError($rowNo, 'name', 'Account name is required.');
            }
            if (AccountType::tryFrom($account['type']) === null) {
                $outcome->addError($rowNo, 'type', 'Type must be one of: '.implode(', ', array_column(AccountType::cases(), 'value')).'.');
            }
            if (Side::tryFrom($account['normal_side']) === null) {
                $outcome->addError($rowNo, 'normal_side', 'Normal side must be debit or credit.');
            }
            if ($account['parent_code'] !== null && ! in_array($account['parent_code'], [...$existingCodes, ...$fileCodes], true)) {
                $outcome->addError($rowNo, 'parent_code', "Parent account {$account['parent_code']} is neither in the file nor in the entity.");
            }
            if ($isControl && ! in_array($account['control_subledger'], self::SUBLEDGERS, true)) {
                $outcome->addError($rowNo, 'control_subledger', 'A control account needs a subledger: '.implode(', ', self::SUBLEDGERS).'.');
            }
            if ($account['currency'] !== null && preg_match('/^[A-Z]{3}$/', $account['currency']) !== 1) {
                $outcome->addError($rowNo, 'currency', 'Currency must be a three-letter ISO code.');
            }
            if ($account['role'] !== null && (! in_array($account['role'], $knownRoles, true) || isset($seenRoles[$account['role']]))) {
                $outcome->addError($rowNo, 'role', "Role {$account['role']} is unknown or mapped twice in the file.");
            }
            if ($account['role'] !== null) {
                $seenRoles[$account['role']] = true;
            }
            if ($code !== '' && ! isset($accounts[$code])) {
                $accounts[$code] = $account;
            }
        }

        return $accounts;
    }

    /** @param array<string, string> $row */
    private function boolean(array $row, string $field, bool $default, int $rowNo, ImportOutcome $outcome): bool
    {
        $text = strtolower($row[$field] ?? '');
        if ($text === '') {
            return $default;
        }
        if (! array_key_exists($text, self::BOOLEANS)) {
            $outcome->addError($rowNo, $field, "{$field} must be true or false.");

            return $default;
        }

        return self::BOOLEANS[$text];
    }

    /**
     * @param array<string, array{code: string, name: string, type: string, normal_side: string, parent_code: string|null, is_postable: bool, is_control: bool, control_subledger: string|null, currency: string|null, role: string|null}> $accounts
     */
    private function commit(array $accounts, string $entityId, string $actorUserId): int
    {
        return DB::transaction(function () use ($accounts, $entityId, $actorUserId): int {
            $tenantId = TenantContext::id();
            $ids = DB::table('accounts')->where('entity_id', $entityId)->pluck('id', 'code')->map(fn (mixed $id): string => (string) $id)->all();
            foreach (array_keys($accounts) as $code) {
                $ids[$code] = (string) Str::uuid7();
            }
            $bookId = (string) DB::table('books')->where('is_primary', true)->value('id');
            foreach ($accounts as $code => $a) {
                DB::table('accounts')->insert([
                    'id' => $ids[$code], 'tenant_id' => $tenantId, 'entity_id' => $entityId, 'code' => $code, 'name' => $a['name'],
                    'type' => $a['type'], 'normal_side' => $a['normal_side'], 'parent_id' => $a['parent_code'] === null ? null : $ids[$a['parent_code']],
                    'is_postable' => $a['is_postable'], 'is_control' => $a['is_control'], 'control_subledger' => $a['control_subledger'],
                    'currency' => $a['currency'], 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($a['role'] !== null) {
                    DB::table('account_role_mappings')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'entity_id' => $entityId,
                        'book_id' => $bookId, 'role_code' => $a['role'], 'account_id' => $ids[$code], 'effective_from' => CarbonImmutable::today()->toDateString()]);
                }
            }
            $this->audit->record('chart_of_accounts.imported', AuditSubject::of('legal_entity', $entityId), null,
                ['accounts' => array_keys($accounts)], null, 'accounting.manage_coa', Actor::user($actorUserId));

            return count($accounts);
        });
    }
}
