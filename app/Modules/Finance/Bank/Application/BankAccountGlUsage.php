<?php

declare(strict_types=1);

namespace App\Modules\Finance\Bank\Application;

use App\Modules\Accounting\Application\Contracts\AccountUsage;
use Illuminate\Support\Facades\DB;

/** UX U2: an active bank account's GL account stays active while the bank account is open. */
final class BankAccountGlUsage implements AccountUsage
{
    public function blocksDeactivation(string $accountId, string $accountCode): ?string
    {
        $bank = DB::table('bank_accounts')->where('gl_account_id', $accountId)->where('status', 'active')->orderBy('bank_name')->value('bank_name');

        return $bank === null ? null : "The {$bank} bank account posts to account {$accountCode}. Close that bank account first.";
    }
}
