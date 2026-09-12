<?php

declare(strict_types=1);

namespace App\Modules\Finance\Bank\Application;

use App\Modules\Accounting\Application\Contracts\CloseCheckResult;
use App\Modules\Accounting\Application\Contracts\CloseTaskCheck;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;
use App\Modules\Finance\Bank\Domain\Models\BankAccount;
use App\Modules\Finance\Bank\Domain\Models\BankStatementLine;

/** Design §5.7 task 3: blocks while any bank statement line of the entity dated on or before the period end is neither matched nor explained. */
final class BankReconciliationCloseCheck implements CloseTaskCheck
{
    public function taskCode(): string
    {
        return 'bank_reconciliation';
    }

    public function check(FiscalPeriodView $period, string $actorUserId): CloseCheckResult
    {
        $unmatched = BankStatementLine::query()->whereIn('bank_account_id', BankAccount::query()->where('entity_id', $period->entityId)->select('id'))
            ->where('match_status', 'unmatched')->where('posted_on', '<=', $period->ends->toDateString())->count();
        $details = ['unmatched_lines' => $unmatched];

        return $unmatched === 0
            ? CloseCheckResult::passed('Every bank statement line is matched or explained.', $details)
            : CloseCheckResult::blocked("{$unmatched} bank statement line(s) are unmatched and unexplained.", $details);
    }
}
