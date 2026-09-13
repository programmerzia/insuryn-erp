<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Setup;

use App\Modules\Accounting\Application\AccountRoles\AccountRoleMappingService;
use App\Modules\Accounting\Application\Imports\ChartOfAccountsImport;
use App\Modules\Accounting\Application\Imports\ImportMode;
use App\Modules\Accounting\Application\Imports\ImportOutcome;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Setup wizard step 3 (session S1): a chart-of-accounts template to review and edit, imported through the ordinary COA import (so the same
 * validation, role mappings and audit apply). Accounts carrying an account role in the template must stay — the posting rules need them —
 * and the template's control accounts are registered with their subledgers for reconciliation. Templates are CSV files in
 * resources/setup/chart-of-accounts.
 */
final class ChartOfAccountsSetup
{
    public const TEMPLATES = ['non-life-insurance' => ['name' => 'Non-life insurance', 'description' => 'Motor, fire, marine and other general insurance: premium, unearned premium, VAT, claims reserves, commission, suspense and bank.']];

    public function __construct(
        private readonly ChartOfAccountsImport $import,
        private readonly AccountRoleMappingService $mappings,
    ) {}

    /** @return list<array{code: string, name: string, type: string, normal_side: string, is_control: bool, control_subledger: string|null, role: string|null}> */
    public function template(string $templateId): array
    {
        if (! isset(self::TEMPLATES[$templateId])) {
            throw new InvalidArgumentException("Unknown chart of accounts template {$templateId}.");
        }
        $lines = file(resource_path("setup/chart-of-accounts/{$templateId}.csv"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $header = array_map(fn (?string $h): string => (string) $h, str_getcsv((string) array_shift($lines), escape: ''));
        $rows = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line, escape: '');
            $cell = fn (string $name): string => (string) ($cells[(int) array_search($name, $header, true)] ?? '');
            $rows[] = ['code' => $cell('code'), 'name' => $cell('name'), 'type' => $cell('type'), 'normal_side' => $cell('normal_side'), 'is_control' => $cell('is_control') === 'true',
                'control_subledger' => $cell('control_subledger') === '' ? null : $cell('control_subledger'), 'role' => $cell('role') === '' ? null : $cell('role')];
        }

        return $rows;
    }

    /**
     * @param list<array{code: string, name: string, type: string, normal_side: string, is_control: bool, control_subledger: string|null, role: string|null}> $rows
     *
     * @throws BusinessRuleViolation SETUP_COMPANY_MISSING | SETUP_FISCAL_YEAR_MISSING | SETUP_ROLE_ACCOUNT_MISSING | SETUP_ROLES_UNMAPPED
     */
    public function import(string $templateId, array $rows, string $actorUserId): ImportOutcome
    {
        $entityId = DB::table('legal_entities')->orderBy('created_at')->value('id');
        if (! is_string($entityId)) {
            throw new BusinessRuleViolation('SETUP_COMPANY_MISSING', 'Save the company first: the chart of accounts belongs to it.');
        }
        $bookId = DB::table('books')->where('is_primary', true)->value('id');
        if (! is_string($bookId)) {
            throw new BusinessRuleViolation('SETUP_FISCAL_YEAR_MISSING', 'Open the fiscal year first: it creates the book the accounts are mapped in.');
        }
        $missing = array_values(array_diff(array_filter(array_column($this->template($templateId), 'role')), array_filter(array_column($rows, 'role'))));
        if ($missing !== []) {
            $names = DB::table('account_roles')->whereIn('code', $missing)->orderBy('code')->pluck('description')->map(fn (mixed $d): string => (string) $d)->all();
            throw new BusinessRuleViolation('SETUP_ROLE_ACCOUNT_MISSING', 'Keep an account for: '.implode('; ', $names).'. The accounting behind policies, receipts and claims posts to them.');
        }

        // Fix F4: the import and its control accounts commit only when every account role the posting rules in force use has an account.
        return DB::transaction(function () use ($rows, $entityId, $bookId, $actorUserId): ImportOutcome {
            $outcome = $this->import->run($this->csv($rows), $entityId, ImportMode::Commit, $actorUserId);
            if ($outcome->result === null) {
                return $outcome;
            }
            foreach ($rows as $row) {
                if ($row['is_control'] && $row['control_subledger'] !== null && $row['role'] !== null) {
                    DB::table('subledger_controls')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(),
                        'entity_id' => $entityId, 'book_id' => $bookId, 'subledger' => $row['control_subledger'], 'control_account_role' => $row['role']]);
                }
            }
            $unmapped = $this->mappings->unmappedRoles($entityId, $bookId, CarbonImmutable::today());
            if ($unmapped !== []) {
                throw new BusinessRuleViolation('SETUP_ROLES_UNMAPPED', 'The posting rules need an account for: '.implode('; ', array_column($unmapped, 'description'))
                    .'. Add an account for each, with that purpose, before creating the chart.');
            }

            return $outcome;
        });
    }

    /** @param list<array{code: string, name: string, type: string, normal_side: string, is_control: bool, control_subledger: string|null, role: string|null}> $rows */
    private function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+') ?: throw new \RuntimeException('Could not open a temporary stream.');
        fputcsv($stream, ['code', 'name', 'type', 'normal_side', 'is_postable', 'is_control', 'control_subledger', 'role'], escape: '');
        foreach ($rows as $row) {
            fputcsv($stream, [$row['code'], $row['name'], $row['type'], $row['normal_side'], 'true', $row['is_control'] ? 'true' : 'false', $row['control_subledger'] ?? '', $row['role'] ?? ''], escape: '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }
}
