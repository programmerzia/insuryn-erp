<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Contracts;

/**
 * Gap fix GA-43 (DECISION D-82): a reconciliation of an account that is not a subledger control account — the unearned premium reserve, VAT
 * payable, stamp duty payable. It names the account roles whose GL balance the register explains, instead of a `subledger_controls` row, so the
 * accounts stay ordinary accounts (manual journals may post to them without accounting.post_to_control). Tagged as a SubledgerReconciler like the
 * others: it runs nightly, in the close and at the lock.
 */
interface AccountBalanceReconciler extends SubledgerReconciler
{
    /** @return list<string> the account roles whose balance (normal side) the register explains */
    public function accountRoles(): array;

    /**
     * Whether GL lines without the item dimension are outside the register: true for a payable the register builds up from policies while the
     * payments to the government are posted by manual journal (they carry no policy); false when any such line is a difference to explain.
     */
    public function excludesUnattributedLines(): bool;
}
