<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Close;

use App\Modules\Accounting\Application\Contracts\CloseCheckResult;
use App\Modules\Accounting\Application\LedgerQuery;
use App\Modules\Accounting\Application\Posting\DraftLine;
use App\Modules\Accounting\Application\Posting\JournalDraft;
use App\Modules\Accounting\Application\Posting\JournalWriter;
use App\Modules\Accounting\Application\Posting\LineDimensions;
use App\Modules\Accounting\Application\Posting\PostingContextLoader;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Gap fix GA-15: the year-end close. In the close of a fiscal year's last month, every income and expense account's balance at the year end is
 * moved to the account mapped to `retained_earnings` (design §3.4 seeded role) by one closing journal dated the year end, so the new year's profit
 * and loss starts from zero and the balance sheet carries the year's result in equity.
 *
 * DECISION D-81: the closing journal is a kernel journal (kind `closing`) written through the JournalWriter like manual journals and reversals — its
 * lines depend on which accounts carry a balance, which a posting rule's fixed roles cannot express, and it is no business module's event. It posts
 * straight away (it is computed from the ledger, nothing for a checker to judge beyond the close itself, which the finance manager runs), into the
 * last month while that month is open, or soft-locked for a holder of accounting.post_in_soft_locked.
 *
 * ASSUMPTION A-203: the balances closed are the cumulative balances at the year end (every earlier year was closed the same way, so this is the
 * year's result; a year never closed before is closed together with it). Running it again posts only what changed since (nothing when nothing did).
 */
final class YearEndClose
{
    public const RETAINED_EARNINGS_ROLE = 'retained_earnings';

    public function __construct(
        private readonly LedgerQuery $ledger,
        private readonly JournalWriter $writer,
        private readonly PostingContextLoader $contexts,
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /** Whether $period is the last month of its fiscal year (the only close run that gets the year-end task). */
    public static function isLastPeriodOfYear(FiscalPeriodView $period): bool
    {
        $last = DB::table('fiscal_periods')->where('entity_id', $period->entityId)->where('book_id', $period->bookId)->where('year', $period->year)->max('ends');

        return $last !== null && (string) $last === $period->ends->toDateString();
    }

    /**
     * The closing journal's lines as they stand now: one line per income or expense account with a balance, on the opposite side, and the
     * difference to retained earnings. Empty when there is nothing to close.
     *
     * @return list<array{account_id: string, code: string, name: string, side: string, amount_minor: int, role: string|null}>
     *
     * @throws BusinessRuleViolation RETAINED_EARNINGS_UNMAPPED
     */
    public function lines(FiscalPeriodView $period): array
    {
        $lines = [];
        $profit = 0;
        foreach ($this->ledger->trialBalance($period->entityId, $period->bookId, $period->ends) as $row) {
            if (! in_array($row['type'], ['income', 'expense'], true)) {
                continue;
            }
            $net = $row['debit'] - $row['credit'];
            if ($net === 0) {
                continue;
            }
            $profit -= $net;
            $lines[] = ['account_id' => $row['account_id'], 'code' => $row['code'], 'name' => $row['name'], 'side' => $net > 0 ? 'credit' : 'debit',
                'amount_minor' => abs($net), 'role' => null];
        }
        if ($lines === []) {
            return [];
        }
        $retained = $this->retainedEarningsAccount($period);
        if ($profit !== 0) {
            $lines[] = ['account_id' => $retained->id, 'code' => $retained->code, 'name' => $retained->name, 'side' => $profit > 0 ? 'credit' : 'debit',
                'amount_minor' => abs($profit), 'role' => self::RETAINED_EARNINGS_ROLE];
        }

        return $lines;
    }

    /**
     * Posts the closing journal for the fiscal year that ends with $period.
     *
     * @throws BusinessRuleViolation NOT_YEAR_END | RETAINED_EARNINGS_UNMAPPED (and the posting refusals PERIOD_CLOSED / PERIOD_SOFT_LOCKED)
     */
    public function close(FiscalPeriodView $period, string $actorUserId): CloseCheckResult
    {
        if (! self::isLastPeriodOfYear($period)) {
            throw new BusinessRuleViolation('NOT_YEAR_END', "Period {$period->year}-{$period->period} is not the last month of its fiscal year.");
        }

        return DB::transaction(function () use ($period, $actorUserId): CloseCheckResult {
            // Serialise closes of the same year: the second waits and then finds nothing left to close.
            DB::table('fiscal_periods')->where('id', $period->id)->lockForUpdate()->value('id');
            $year = $this->fiscalYearLabel($period);
            $lines = $this->lines($period);
            if ($lines === []) {
                return CloseCheckResult::passed("Income and expense for fiscal year {$year} are already closed to retained earnings.", ['journal_id' => null, 'lines' => 0]);
            }
            $currency = (string) DB::table('legal_entities')->where('id', $period->entityId)->value('base_currency');
            $mayPostSoftLocked = $this->permissions->has($actorUserId, 'accounting.post_in_soft_locked', AuthorizationScope::entity($period->entityId));
            $posting = $this->contexts->period($period->entityId, $period->bookId, $period->ends, $mayPostSoftLocked);
            $draftLines = [];
            foreach ($lines as $i => $line) {
                $draftLines[] = new DraftLine($i + 1, $line['account_id'], Side::from($line['side']), $line['amount_minor'], $currency, $line['amount_minor'], $line['role'],
                    $line['role'] === null ? "Close {$line['code']} to retained earnings" : "Result of fiscal year {$year}", LineDimensions::fromValues([]));
            }
            $journal = $this->writer->post([
                'entity_id' => $period->entityId, 'book_id' => $period->bookId, 'period_id' => $posting->id,
                'transaction_date' => $period->ends, 'posting_date' => $period->ends, 'effective_date' => $period->ends,
                'kind' => JournalKind::Closing->value, 'reason' => "Year-end close of fiscal year {$year}", 'description' => "Year-end close FY {$year}",
                'source_type' => 'fiscal_year_close', 'source_id' => $period->id, 'currency' => $currency, 'created_by' => $actorUserId, 'approved_by' => $actorUserId,
            ], JournalDraft::balanced('Year-end close', $draftLines));
            $result = array_sum(array_map(fn (array $l): int => $l['role'] === null ? 0 : ($l['side'] === 'credit' ? $l['amount_minor'] : -$l['amount_minor']), $lines));
            $this->audit->record('fiscal_year.closed', AuditSubject::of('journal', $journal->id), null,
                ['fiscal_year' => $year, 'period_id' => $period->id, 'number' => $journal->number, 'net_result_minor' => $result, 'lines' => count($lines)], null, 'periods.lock', Actor::user($actorUserId));

            return CloseCheckResult::passed("Income and expense for fiscal year {$year} closed to retained earnings in {$journal->number}.",
                ['journal_id' => $journal->id, 'journal_number' => $journal->number, 'lines' => count($lines), 'net_result_minor' => $result]);
        });
    }

    /** "2026" for a year within one calendar year, "2026-27" for July–June. */
    private function fiscalYearLabel(FiscalPeriodView $period): string
    {
        $starts = (string) DB::table('fiscal_periods')->where('entity_id', $period->entityId)->where('book_id', $period->bookId)->where('year', $period->year)->min('starts');
        $firstYear = (int) substr($starts, 0, 4);
        $lastYear = (int) $period->ends->format('Y');

        return $firstYear === $lastYear ? (string) $firstYear : $firstYear.'-'.substr((string) $lastYear, 2);
    }

    /** @return object{id: string, code: string, name: string} */
    private function retainedEarningsAccount(FiscalPeriodView $period): object
    {
        $day = $period->ends->toDateString();
        /** @var object{id: string, code: string, name: string}|null $account */
        $account = DB::table('account_role_mappings as m')->join('accounts as a', 'a.id', '=', 'm.account_id')
            ->where('m.entity_id', $period->entityId)->where('m.book_id', $period->bookId)->where('m.role_code', self::RETAINED_EARNINGS_ROLE)
            ->where('m.effective_from', '<=', $day)->where(fn ($q) => $q->whereNull('m.effective_to')->orWhere('m.effective_to', '>', $day))
            ->first(['a.id', 'a.code', 'a.name']);

        return $account ?? throw new BusinessRuleViolation('RETAINED_EARNINGS_UNMAPPED',
            'No account is mapped to retained earnings. Map one in Accounting → Account roles, then run the year-end close again.');
    }
}
