<?php

declare(strict_types=1);

namespace App\Modules\Finance\Bank\Application;

use App\Modules\Finance\Bank\Domain\Models\BankAccount;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;

/** How other contexts learn where a bank account's cash posts (design §4.2). */
final class BankAccountQuery
{
    /** @throws BusinessRuleViolation INVALID_BANK_ACCOUNT when the account is unknown, closed, of another entity or another currency */
    public function glAccountFor(string $bankAccountId, string $entityId, string $currency): string
    {
        $bankAccount = BankAccount::query()->find($bankAccountId);
        if ($bankAccount === null || $bankAccount->status !== 'active' || $bankAccount->entity_id !== $entityId || $bankAccount->currency !== $currency) {
            throw new BusinessRuleViolation('INVALID_BANK_ACCOUNT', "Bank account {$bankAccountId} is not an active {$currency} account of this entity.");
        }

        return $bankAccount->gl_account_id;
    }
}
