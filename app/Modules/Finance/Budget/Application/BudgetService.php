<?php

declare(strict_types=1);

namespace App\Modules\Finance\Budget\Application;

use App\Modules\Accounting\Application\Reports\AccountMovementQuery;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Money\MinorUnits;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design addendum v2 §B.8.1 budgets: a version per fiscal year prepared by one person (`budget.prepare`) and approved by another (`budget.approve`,
 * SoD object rule), lines per income or expense account × branch × fiscal month. Lines are entered in the grid, pasted from a spreadsheet (tab-separated)
 * or copied from last year's actuals ± a percentage. Approving supersedes the previous approved version; an approved version never changes.
 */
final class BudgetService
{
    public const PREPARE = 'budget.prepare';

    public const APPROVE = 'budget.approve';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly SodGuard $sod,
        private readonly AccountMovementQuery $movements,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation BUDGET_YEAR_UNKNOWN */
    public function create(string $entityId, int $fiscalYear, string $code, string $name, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, self::PREPARE, AuthorizationScope::entity($entityId));
        if (! DB::table('fiscal_periods')->where('entity_id', $entityId)->where('year', $fiscalYear)->exists()) {
            throw new BusinessRuleViolation('BUDGET_YEAR_UNKNOWN', 'That fiscal year is not set up. Open the fiscal year first, then prepare its budget.');
        }

        return DB::transaction(function () use ($entityId, $fiscalYear, $code, $name, $actorUserId): string {
            $version = (int) DB::table('budgets')->where('entity_id', $entityId)->where('fiscal_year', $fiscalYear)->where('code', $code)->max('version') + 1;
            $id = (string) Str::uuid7();
            DB::table('budgets')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId, 'fiscal_year' => $fiscalYear, 'code' => $code, 'name' => $name,
                'version' => $version, 'status' => 'draft', 'prepared_by' => $actorUserId, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('budget.created', AuditSubject::of('budget', $id), null, ['fiscal_year' => $fiscalYear, 'code' => $code, 'version' => $version], null, self::PREPARE, Actor::user($actorUserId));

            return $id;
        });
    }

    /**
     * Replaces the grid rows of one branch: each row is an account and its twelve monthly amounts (minor units).
     *
     * @param list<array{account_id: string, amounts: list<int>}> $rows
     *
     * @throws BusinessRuleViolation BUDGET_NOT_DRAFT | BUDGET_ACCOUNT_INVALID | INVALID_AMOUNT
     */
    public function saveBranchRows(string $budgetId, string $branchId, array $rows, string $actorUserId): int
    {
        $budget = $this->draft($budgetId, $actorUserId);
        $accounts = $this->budgetAccounts((string) $budget->entity_id);
        foreach ($rows as $row) {
            if (! isset($accounts[$row['account_id']])) {
                throw new BusinessRuleViolation('BUDGET_ACCOUNT_INVALID', 'Budget lines are for active income and expense accounts. Choose another account.');
            }
            if (count($row['amounts']) !== 12 || array_filter($row['amounts'], fn (int $a): bool => $a < 0) !== []) {
                throw new BusinessRuleViolation('INVALID_AMOUNT', 'Enter twelve monthly amounts of zero or more.');
            }
        }

        return DB::transaction(function () use ($budgetId, $branchId, $rows, $actorUserId): int {
            $written = 0;
            foreach ($rows as $row) {
                DB::table('budget_lines')->where('budget_id', $budgetId)->where('branch_id', $branchId)->where('account_id', $row['account_id'])->delete();
                foreach ($row['amounts'] as $index => $amount) {
                    if ($amount === 0) {
                        continue;
                    }
                    DB::table('budget_lines')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'budget_id' => $budgetId, 'account_id' => $row['account_id'],
                        'branch_id' => $branchId, 'period_no' => $index + 1, 'amount_minor' => $amount]);
                    $written++;
                }
            }
            DB::table('budgets')->where('id', $budgetId)->update(['updated_at' => now()]);
            $this->audit->record('budget.lines_saved', AuditSubject::of('budget', $budgetId), null, ['branch_id' => $branchId, 'accounts' => count($rows), 'lines' => $written], null, self::PREPARE, Actor::user($actorUserId));

            return $written;
        });
    }

    /**
     * Rows pasted from a spreadsheet: tab-separated, the account code in the first column (optionally followed by its name in the second), then twelve
     * monthly amounts in fiscal-month order; a header row and blank lines are skipped.
     *
     * @return array{rows: int, errors: list<string>}
     *
     * @throws BusinessRuleViolation BUDGET_NOT_DRAFT
     */
    public function paste(string $budgetId, string $branchId, string $text, string $actorUserId): array
    {
        $budget = $this->draft($budgetId, $actorUserId);
        $currency = (string) DB::table('legal_entities')->where('id', $budget->entity_id)->value('base_currency');
        $byCode = array_flip($this->budgetAccounts((string) $budget->entity_id));
        $rows = [];
        $errors = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $number => $line) {
            $cells = array_map('trim', explode("\t", $line));
            if (trim($line) === '' || ! isset($byCode[$cells[0]])) {
                if (trim($line) !== '' && $number > 0) {
                    $errors[] = 'Line '.($number + 1).': no income or expense account with code "'.$cells[0].'".';
                }
                continue;
            }
            $amounts = array_slice($cells, count($cells) >= 14 ? 2 : 1, 12);
            $minor = array_map(fn (string $cell): ?int => $cell === '' || $cell === '-' ? 0 : MinorUnits::fromMajor(str_replace(' ', '', $cell), $currency), $amounts);
            if (count($minor) !== 12 || in_array(null, $minor, true)) {
                $errors[] = 'Line '.($number + 1).': give twelve monthly amounts like 125,000.00.';
                continue;
            }
            $rows[] = ['account_id' => $byCode[$cells[0]], 'amounts' => array_map(fn (?int $a): int => (int) $a, $minor)];
        }
        if ($errors === [] && $rows !== []) {
            $this->saveBranchRows($budgetId, $branchId, $rows, $actorUserId);
        }

        return ['rows' => $errors === [] ? count($rows) : 0, 'errors' => $errors];
    }

    /**
     * Last fiscal year's actuals per account × branch × month, changed by $percentBp (500 = +5%, −300 = −3%), replace the draft's lines for those accounts.
     *
     * @throws BusinessRuleViolation BUDGET_NOT_DRAFT | BUDGET_NO_ACTUALS
     */
    public function copyLastYearActuals(string $budgetId, int $percentBp, string $actorUserId): int
    {
        $budget = $this->draft($budgetId, $actorUserId);
        $lastYear = DB::table('fiscal_periods')->where('entity_id', $budget->entity_id)->where('year', (int) $budget->fiscal_year - 1)->orderBy('period')->get(['period', 'starts', 'ends']);
        $thisYear = DB::table('fiscal_periods')->where('entity_id', $budget->entity_id)->where('year', (int) $budget->fiscal_year)->orderBy('period')->first(['starts']);
        // Without last year's periods (a first year on the system) the twelve months before this year's start stand in for it.
        $first = $lastYear->first();
        $start = $first !== null ? CarbonImmutable::parse((string) $first->starts) : CarbonImmutable::parse((string) ($thisYear->starts ?? $budget->fiscal_year.'-01-01'))->subYear();
        $end = $start->addYear()->subDay();
        $accounts = $this->budgetAccounts((string) $budget->entity_id);
        $grid = [];
        foreach ($this->movements->byAccountBranchMonth((string) $budget->entity_id, $start, $end) as $m) {
            if ($m['branch_id'] === null || ! isset($accounts[$m['account_id']]) || $m['amount_minor'] <= 0) {
                continue;
            }
            $month = CarbonImmutable::parse($m['month'].'-01');
            $periodNo = ($month->year - $start->year) * 12 + $month->month - $start->month + 1;
            $amount = intdiv($m['amount_minor'] * (10_000 + $percentBp) + 5_000, 10_000);
            $grid[$m['branch_id']][$m['account_id']] ??= array_fill(0, 12, 0);
            $grid[$m['branch_id']][$m['account_id']][min(12, max(1, $periodNo)) - 1] += max(0, $amount);
        }
        if ($grid === []) {
            throw new BusinessRuleViolation('BUDGET_NO_ACTUALS', 'Last year has no posted income or expense by branch to copy. Enter the budget in the grid or paste it instead.');
        }
        $written = 0;
        foreach ($grid as $branchId => $rows) {
            $written += $this->saveBranchRows($budgetId, (string) $branchId, array_map(fn (string $accountId, array $amounts): array => ['account_id' => $accountId, 'amounts' => $amounts],
                array_keys($rows), $rows), $actorUserId);
        }

        return $written;
    }

    /** @throws BusinessRuleViolation BUDGET_NOT_DRAFT | BUDGET_EMPTY */
    public function submit(string $budgetId, string $actorUserId): void
    {
        $this->draft($budgetId, $actorUserId);
        if (! DB::table('budget_lines')->where('budget_id', $budgetId)->exists()) {
            throw new BusinessRuleViolation('BUDGET_EMPTY', 'The budget has no amounts yet. Enter them before sending it for approval.');
        }
        DB::transaction(function () use ($budgetId, $actorUserId): void {
            DB::table('budgets')->where('id', $budgetId)->update(['status' => 'submitted', 'submitted_at' => now(), 'updated_at' => now()]);
            $this->audit->record('budget.submitted', AuditSubject::of('budget', $budgetId), ['status' => 'draft'], ['status' => 'submitted'], null, self::PREPARE, Actor::user($actorUserId));
        });
    }

    /** Approves a submitted version and supersedes the year's previously approved version of the same code. @throws BusinessRuleViolation BUDGET_NOT_SUBMITTED */
    public function approve(string $budgetId, string $actorUserId): void
    {
        $budget = $this->decidable($budgetId, $actorUserId);
        DB::transaction(function () use ($budget, $actorUserId): void {
            DB::table('budgets')->where('entity_id', $budget->entity_id)->where('fiscal_year', $budget->fiscal_year)->where('code', $budget->code)->where('status', 'approved')
                ->update(['status' => 'superseded', 'updated_at' => now()]);
            DB::table('budgets')->where('id', $budget->id)->update(['status' => 'approved', 'approved_by' => $actorUserId, 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit->record('budget.approved', AuditSubject::of('budget', (string) $budget->id), ['status' => 'submitted'], ['status' => 'approved'], null, self::APPROVE, Actor::user($actorUserId));
        });
    }

    /** @throws BusinessRuleViolation BUDGET_NOT_SUBMITTED */
    public function returnToDraft(string $budgetId, string $reason, string $actorUserId): void
    {
        $budget = $this->decidable($budgetId, $actorUserId);
        DB::transaction(function () use ($budget, $reason, $actorUserId): void {
            DB::table('budgets')->where('id', $budget->id)->update(['status' => 'draft', 'note' => $reason, 'updated_at' => now()]);
            $this->audit->record('budget.returned', AuditSubject::of('budget', (string) $budget->id), ['status' => 'submitted'], ['status' => 'draft'], $reason, self::APPROVE, Actor::user($actorUserId));
        });
    }

    /** A revision: a new draft version with the lines of this one. */
    public function newVersion(string $budgetId, string $actorUserId): string
    {
        $budget = DB::table('budgets')->where('id', $budgetId)->first() ?? abort(404);
        $id = $this->create((string) $budget->entity_id, (int) $budget->fiscal_year, (string) $budget->code, (string) $budget->name, $actorUserId);
        DB::statement('INSERT INTO budget_lines (id, tenant_id, budget_id, account_id, branch_id, period_no, amount_minor)
            SELECT gen_random_uuid(), tenant_id, ?, account_id, branch_id, period_no, amount_minor FROM budget_lines WHERE budget_id = ?', [$id, $budgetId]);

        return $id;
    }

    /** @return array<string, string> account id → code, for active postable income and expense accounts */
    public function budgetAccounts(string $entityId): array
    {
        return DB::table('accounts')->where('entity_id', $entityId)->whereIn('type', ['income', 'expense'])->where('is_postable', true)->where('status', 'active')
            ->orderBy('code')->pluck('code', 'id')->mapWithKeys(fn (mixed $code, mixed $id): array => [(string) $id => (string) $code])->all();
    }

    private function draft(string $budgetId, string $actorUserId): \stdClass
    {
        $budget = DB::table('budgets')->where('id', $budgetId)->first() ?? abort(404);
        $this->permissions->authorize($actorUserId, self::PREPARE, AuthorizationScope::entity((string) $budget->entity_id));
        if ($budget->status !== 'draft') {
            throw new BusinessRuleViolation('BUDGET_NOT_DRAFT', 'This budget version is no longer a draft. Make a new version to change it.');
        }

        return $budget;
    }

    private function decidable(string $budgetId, string $actorUserId): \stdClass
    {
        $budget = DB::table('budgets')->where('id', $budgetId)->first() ?? abort(404);
        $this->permissions->authorize($actorUserId, self::APPROVE, AuthorizationScope::entity((string) $budget->entity_id));
        if ($budget->prepared_by === $actorUserId) {
            throw new BusinessRuleViolation('MAKER_CHECKER', 'You prepared this budget, so someone else has to approve it.');
        }
        $this->sod->assert($actorUserId, self::APPROVE, AuditSubject::of('budget', $budgetId));
        if ($budget->status !== 'submitted') {
            throw new BusinessRuleViolation('BUDGET_NOT_SUBMITTED', 'Only a budget sent for approval can be approved or returned. Refresh the page.');
        }

        return $budget;
    }
}
