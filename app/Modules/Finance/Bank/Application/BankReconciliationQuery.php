<?php

declare(strict_types=1);

namespace App\Modules\Finance\Bank\Application;

use App\Modules\Finance\Bank\Domain\Models\BankAccount;
use App\Modules\Finance\Bank\Domain\Models\BankStatementLine;
use Carbon\CarbonImmutable;

/** The unmatched queue for one bank account (spec §5 exception queue, design §5.7 task 3). */
final class BankReconciliationQuery
{
    public function __construct(private readonly BankMatcher $matcher) {}

    /**
     * @return array{statement_lines: list<array{id: string, posted_on: string, amount_minor: int, reference: string|null, description: string|null}>,
     *     journal_lines: list<array{journal_line_id: string, journal_id: string, journal_number: string|null, posting_date: string, amount_minor: int, currency: string,
     *     reference: string|null, receipt_number: string|null, source_type: string|null, source_id: string|null}>}
     */
    public function unmatched(string $bankAccountId, CarbonImmutable $asOf): array
    {
        $bankAccount = BankAccount::query()->findOrFail($bankAccountId);
        $statementLines = [];
        $unmatched = BankStatementLine::query()->where('bank_account_id', $bankAccountId)->where('match_status', 'unmatched')
            ->where('posted_on', '<=', $asOf->toDateString())->orderBy('posted_on')->orderBy('id')->get();
        foreach ($unmatched as $line) {
            $statementLines[] = ['id' => $line->id, 'posted_on' => $line->posted_on->toDateString(), 'amount_minor' => $line->amount_minor,
                'reference' => $line->reference, 'description' => $line->description];
        }

        return ['statement_lines' => $statementLines, 'journal_lines' => $this->matcher->unmatchedJournalLines($bankAccount->gl_account_id, null, $asOf)];
    }
}
