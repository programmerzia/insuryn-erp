<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application\Reconciliation;

use App\Modules\Accounting\Application\Contracts\AccountBalanceReconciler;
use App\Modules\Insurance\Collections\Application\Reconciliation\SubledgerItems;
use App\Modules\Insurance\Reports\Application\UnearnedPremiumQuery;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Gap fix GA-43: the unearned premium register (F5, UnearnedPremiumQuery) against the `unearned_premium` account, per policy, as a close task.
 * Every line on the account counts: a manual journal or reversal on it is not in the register and shows as the variance (A-61).
 */
final class UnearnedPremiumReconciler implements AccountBalanceReconciler
{
    public function __construct(private readonly UnearnedPremiumQuery $register) {}

    public function subledger(): string
    {
        return 'unearned_premium';
    }

    public function itemDimension(): string
    {
        return 'policy';
    }

    public function accountRoles(): array
    {
        return ['unearned_premium'];
    }

    public function excludesUnattributedLines(): bool
    {
        return false;
    }

    public function balanceAt(string $entityId, CarbonImmutable $asOf): Money
    {
        return SubledgerItems::total($entityId, $this->itemsAt($entityId, $asOf));
    }

    /** @return list<array{object_type: string, object_id: string, amount_minor: int}> */
    public function itemsAt(string $entityId, CarbonImmutable $asOf): array
    {
        return SubledgerItems::of('policy', array_map(fn (array $row): object => (object) ['object_id' => $row['policy_id'], 'amount' => $row['unearned_minor']],
            $this->register->unearned($entityId, $asOf)['rows']));
    }
}
