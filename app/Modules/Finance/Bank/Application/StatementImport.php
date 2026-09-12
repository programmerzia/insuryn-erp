<?php

declare(strict_types=1);

namespace App\Modules\Finance\Bank\Application;

use App\Modules\Finance\Bank\Domain\Models\BankAccount;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Imports\CsvTable;
use App\Modules\Platform\Money\MinorUnits;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Bank statement CSV import (spec §5 Bank, design §2.4). Idempotent: each line is identified by a hash of the account, date, amount,
 * reference, description and its occurrence number among identical lines in the file, so re-importing a file or an overlapping
 * statement adds only lines not seen before, while genuinely repeated lines (two identical charges on a day) are both kept.
 * All rows are validated first; a file with any invalid row imports nothing.
 */
final class StatementImport
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation STATEMENT_UNREADABLE when the file has no usable header */
    public function import(string $bankAccountId, string $contents, string $fileName, string $actorUserId): StatementImportResult
    {
        $bankAccount = BankAccount::query()->findOrFail($bankAccountId);
        $this->permissions->authorize($actorUserId, 'bank.import', AuthorizationScope::entity($bankAccount->entity_id));
        try {
            /** @var array<string, string> $columns */
            $columns = config('erp.imports.bank_statement');
            $table = CsvTable::parse($contents, $columns, ['posted_on', 'amount'], (int) config('erp.imports.max_rows'));
        } catch (InvalidArgumentException $e) {
            throw new BusinessRuleViolation('STATEMENT_UNREADABLE', $e->getMessage());
        }

        [$lines, $errors] = $this->parseRows($bankAccount, $table->rows);
        if ($errors !== []) {
            return new StatementImportResult(0, 0, $errors);
        }

        return DB::transaction(function () use ($bankAccount, $lines, $fileName, $actorUserId): StatementImportResult {
            $now = CarbonImmutable::now();
            $rows = array_map(fn (array $line): array => $line + ['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'bank_account_id' => $bankAccount->id,
                'source_file' => $fileName, 'match_status' => 'unmatched', 'imported_by' => $actorUserId, 'imported_at' => $now], $lines);
            $imported = 0;
            foreach (array_chunk($rows, 500) as $chunk) {
                $imported += DB::table('bank_statement_lines')->insertOrIgnore($chunk);
            }
            $result = new StatementImportResult($imported, count($rows) - $imported);
            $this->audit->record('bank_statement.imported', AuditSubject::of('bank_account', $bankAccount->id), null,
                ['file' => $fileName, 'imported' => $result->imported, 'duplicates' => $result->duplicates], null, 'bank.import', Actor::user($actorUserId));

            return $result;
        });
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @return array{0: list<array{posted_on: string, amount_minor: int, reference: string|null, description: string|null, raw: string, line_hash: string}>, 1: array<int, string>}
     */
    private function parseRows(BankAccount $bankAccount, array $rows): array
    {
        $dateFormat = (string) config('erp.imports.bank_statement_date_format', 'Y-m-d');
        $lines = [];
        $errors = [];
        $occurrences = [];
        foreach ($rows as $rowNo => $row) {
            $date = DateTimeImmutable::createFromFormat('!'.$dateFormat, $row['posted_on'] ?? '');
            $amount = MinorUnits::fromSignedMajor($row['amount'] ?? '', $bankAccount->currency);
            if ($date === false || $date->format($dateFormat) !== ($row['posted_on'] ?? '')) {
                $errors[$rowNo] = "Date '".($row['posted_on'] ?? '')."' is not in the format {$dateFormat}.";
                continue;
            }
            if ($amount === null || $amount === 0) {
                $errors[$rowNo] = "Amount '".($row['amount'] ?? '')."' is not a non-zero amount in {$bankAccount->currency}.";
                continue;
            }
            $reference = ($row['reference'] ?? '') === '' ? null : $row['reference'];
            $description = ($row['description'] ?? '') === '' ? null : $row['description'];
            $identity = implode('|', [$bankAccount->id, $date->format('Y-m-d'), $amount, $reference ?? '', $description ?? '']);
            $occurrences[$identity] = ($occurrences[$identity] ?? 0) + 1;
            $lines[] = ['posted_on' => $date->format('Y-m-d'), 'amount_minor' => $amount, 'reference' => $reference, 'description' => $description,
                'raw' => json_encode($row, JSON_THROW_ON_ERROR), 'line_hash' => hash('sha256', $identity.'|'.$occurrences[$identity])];
        }

        return [$lines, $errors];
    }
}
