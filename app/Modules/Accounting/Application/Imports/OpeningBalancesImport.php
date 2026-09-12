<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Imports;

use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Accounting\Domain\MinorUnits;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Imports\CsvTable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Opening balances CSV → one kind=opening journal (design §2.2 journals.kind, §9.3 Phase 0). The commit creates
 * and submits the journal through ManualJournalService, so approval, maker ≠ checker, balance and control-account
 * rules all apply; it is posted when approved. "Never silently create imbalance" (spec §7): an unbalanced file is refused.
 */
final class OpeningBalancesImport
{
    public const TYPE = 'opening-balances';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ManualJournalService $journals,
    ) {}

    public function run(string $csv, string $entityId, CarbonImmutable $openingDate, ImportMode $mode, string $actorUserId): ImportOutcome
    {
        $this->permissions->authorize($actorUserId, 'accounting.create_manual_journal', AuthorizationScope::entity($entityId));
        $outcome = new ImportOutcome(self::TYPE, $mode);
        try {
            $table = CsvTable::parse($csv, (array) config('erp.imports.opening_balances'), ['account_code', 'debit', 'credit'], (int) config('erp.imports.max_rows'));
        } catch (InvalidArgumentException $e) {
            $outcome->addError(0, 'file', $e->getMessage());

            return $outcome;
        }

        $currency = (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency');
        $lines = $this->validate($table, $entityId, $currency, $outcome);
        [$debit, $credit] = [$this->total($lines, Side::Debit), $this->total($lines, Side::Credit)];
        if ($debit !== $credit) {
            $outcome->addError(0, 'balance', 'Debits '.MinorUnits::format($debit, $currency).' and credits '.MinorUnits::format($credit, $currency).' do not balance.');
        }
        if (count($lines) < 2 && ! $outcome->hasErrors()) {
            $outcome->addError(0, 'file', 'Opening balances need at least two lines.');
        }
        $outcome->preview = ['lines' => count($lines), 'opening_date' => $openingDate->toDateString(), 'currency' => $currency,
            'total_debit' => MinorUnits::format($debit, $currency), 'total_credit' => MinorUnits::format($credit, $currency)];

        if ($mode === ImportMode::Commit && ! $outcome->hasErrors()) {
            $outcome->result = $this->commit($lines, $entityId, $openingDate, $currency, $actorUserId);
        }

        return $outcome;
    }

    /** @return list<ManualJournalLine> */
    private function validate(CsvTable $table, string $entityId, string $currency, ImportOutcome $outcome): array
    {
        $accounts = DB::table('accounts')->where('entity_id', $entityId)->get(['id', 'code', 'is_postable'])->keyBy('code');
        $branches = DB::table('branches')->where('entity_id', $entityId)->pluck('id', 'code');

        $lines = [];
        foreach ($table->rows as $rowNo => $row) {
            $account = $accounts->get($row['account_code'] ?? '');
            if ($account === null) {
                $outcome->addError($rowNo, 'account_code', "Account {$row['account_code']} does not exist in the entity.");
            } elseif (! (bool) $account->is_postable) {
                $outcome->addError($rowNo, 'account_code', "Account {$row['account_code']} does not accept postings.");
            }
            $branchCode = $row['branch_code'] ?? '';
            if ($branchCode !== '' && ! $branches->has($branchCode)) {
                $outcome->addError($rowNo, 'branch_code', "Branch {$branchCode} does not exist in the entity.");
            }
            $side = $this->side($row, $currency, $rowNo, $outcome);
            if ($account === null || $side === null || $outcome->rowHasErrors($rowNo)) {
                continue;
            }
            $lines[] = new ManualJournalLine((string) $account->id, $side[0], $side[1],
                $branchCode === '' ? [] : ['branch' => (string) $branches->get($branchCode)], ($row['memo'] ?? '') === '' ? null : $row['memo']);
        }

        return $lines;
    }

    /**
     * @param array<string, string> $row
     * @return array{Side, int}|null
     */
    private function side(array $row, string $currency, int $rowNo, ImportOutcome $outcome): ?array
    {
        $parsed = [];
        foreach ([Side::Debit, Side::Credit] as $side) {
            $text = $row[$side->value] ?? '';
            if ($text === '') {
                continue;
            }
            $minor = MinorUnits::fromMajor($text, $currency);
            if ($minor === null) {
                $outcome->addError($rowNo, $side->value, "\"{$text}\" is not a non-negative amount in {$currency}.");

                return null;
            }
            if ($minor > 0) {
                $parsed[] = [$side, $minor];
            }
        }
        if (count($parsed) !== 1) {
            $outcome->addError($rowNo, 'credit', 'Enter a positive amount in exactly one of debit or credit.');

            return null;
        }

        return $parsed[0];
    }

    /** @param list<ManualJournalLine> $lines */
    private function total(array $lines, Side $side): int
    {
        return array_sum(array_map(fn (ManualJournalLine $line): int => $line->side === $side ? $line->amountMinor : 0, $lines));
    }

    /**
     * @param list<ManualJournalLine> $lines
     * @return array{journal_id: string, approval_id: string|null, status: string}
     */
    private function commit(array $lines, string $entityId, CarbonImmutable $openingDate, string $currency, string $actorUserId): array
    {
        return DB::transaction(function () use ($lines, $entityId, $openingDate, $currency, $actorUserId): array {
            $journal = $this->journals->create(new ManualJournalRequest($entityId, $openingDate, 'Opening balances',
                JournalKind::Opening, 'Opening balances imported as of '.$openingDate->toDateString(), $currency, $lines), $actorUserId);
            $approvalId = $this->journals->submit($journal->id, $actorUserId);

            return ['journal_id' => $journal->id, 'approval_id' => $approvalId, 'status' => 'pending_approval'];
        });
    }
}
