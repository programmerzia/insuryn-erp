<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Imports;

use App\Modules\Accounting\Application\ChartOfAccounts\ChartOfAccountsRules;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Imports\CsvTable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Chart-of-accounts CSV import (design §9.3 "COA import", spec §7 import pipeline). New accounts only:
 * a code that already exists in the entity is an error, never an update. Optional `role` maps the account
 * to a semantic account role in the primary book from today. The rules for each account are ChartOfAccountsRules, shared with Accounting → Chart
 * of accounts (UX U2).
 */
final class ChartOfAccountsImport
{
    public const TYPE = 'chart-of-accounts';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
        private readonly ChartOfAccountsRules $rules,
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

        $accounts = $this->rules->checkNew($table->rows, $entityId, $outcome);
        $outcome->preview = ['accounts_to_create' => count($accounts), 'rows' => array_values($accounts)];
        if ($mode === ImportMode::Commit && ! $outcome->hasErrors()) {
            $outcome->result = ['accounts_created' => $this->commit($accounts, $entityId, $actorUserId)];
        }

        return $outcome;
    }

    /**
     * @param array<string, array{code: string, name: string, type: string, normal_side: string, parent_code: string|null, is_postable: bool, is_control: bool, control_subledger: string|null, currency: string|null, role: string|null}> $accounts
     */
    private function commit(array $accounts, string $entityId, string $actorUserId): int
    {
        return DB::transaction(function () use ($accounts, $entityId, $actorUserId): int {
            $this->rules->insertNew($accounts, $entityId);
            $this->audit->record('chart_of_accounts.imported', AuditSubject::of('legal_entity', $entityId), null,
                ['accounts' => array_keys($accounts)], null, 'accounting.manage_coa', Actor::user($actorUserId));

            return count($accounts);
        });
    }
}
