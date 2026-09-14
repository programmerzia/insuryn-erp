<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\ChartOfAccounts;

use App\Modules\Accounting\Application\Imports\ImportOutcome;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The rules for a new account (design §9.3 "COA import"), shared by the chart-of-accounts import, the setup wizard (through the import) and
 * Accounting → Chart of accounts (UX U2), so each path accepts and refuses exactly the same accounts with the same words. A code that already exists
 * in the entity is an error, never an update. Rows come as text, as a CSV cell or a form field carries them.
 */
final class ChartOfAccountsRules
{
    public const SUBLEDGERS = ['premium', 'claims', 'commission', 'customer', 'agent', 'bank', 'ap', 'ar', 'suspense'];

    private const BOOLEANS = ['true' => true, '1' => true, 'yes' => true, 'false' => false, '0' => false, 'no' => false];

    /**
     * Checks new accounts against each other and the entity's chart; every problem is added to $outcome under its row number and field.
     *
     * @param array<int, array<string, string>> $rows row number => cells (code, name, type, normal_side, parent_code, is_postable, is_control, control_subledger, currency, role)
     * @return array<string, array{code: string, name: string, type: string, normal_side: string, parent_code: string|null, is_postable: bool, is_control: bool, control_subledger: string|null, currency: string|null, role: string|null}>
     */
    public function checkNew(array $rows, string $entityId, ImportOutcome $outcome, bool $fromImport = true): array
    {
        $existingCodes = DB::table('accounts')->where('entity_id', $entityId)->pluck('code')->map(fn (mixed $c): string => (string) $c)->all();
        $fileCodes = array_map(fn (array $row): string => $row['code'] ?? '', $rows);
        $knownRoles = DB::table('account_roles')->pluck('code')->map(fn (mixed $c): string => (string) $c)->all();

        $accounts = [];
        $seenRoles = [];
        foreach ($rows as $rowNo => $row) {
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
                in_array($code, $existingCodes, true) => $outcome->addError($rowNo, 'code', $fromImport ? "Account {$code} already exists; imports never update accounts." : "Account {$code} already exists."),
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
                $outcome->addError($rowNo, 'parent_code', $fromImport ? "Parent account {$account['parent_code']} is neither in the file nor in the entity." : "Parent account {$account['parent_code']} is not in the chart.");
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

    /**
     * Inserts checked accounts (parents resolved within the batch or the entity) and their role mappings in the primary book from today.
     * Runs inside the caller's transaction.
     *
     * @param array<string, array{code: string, name: string, type: string, normal_side: string, parent_code: string|null, is_postable: bool, is_control: bool, control_subledger: string|null, currency: string|null, role: string|null}> $accounts
     * @return array<string, string> code => new account id
     */
    public function insertNew(array $accounts, string $entityId): array
    {
        $tenantId = TenantContext::id();
        $ids = DB::table('accounts')->where('entity_id', $entityId)->pluck('id', 'code')->map(fn (mixed $id): string => (string) $id)->all();
        $created = [];
        foreach (array_keys($accounts) as $code) {
            $ids[$code] = $created[$code] = (string) Str::uuid7();
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

        return $created;
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
}
