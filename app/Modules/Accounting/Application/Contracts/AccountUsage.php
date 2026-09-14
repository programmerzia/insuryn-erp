<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Contracts;

/**
 * UX U2: a context outside the kernel that posts to a chart account directly (not through an account role) says so before the account is
 * deactivated. Implementations are tagged with this interface in the container.
 */
interface AccountUsage
{
    /** Why the account cannot be deactivated yet, as a sentence for people ("The City Bank bank account posts to account 1020. Close that bank account first."), or null. */
    public function blocksDeactivation(string $accountId, string $accountCode): ?string;
}
