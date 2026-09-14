<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application\Reconciliation;

/** Gap fix GA-43: VAT and levies on premium (POLICY_ISSUED, POLICY_ENDORSED credit it; POLICY_CANCELLED debits the tax reversal) against `premium_tax_payable`. */
final class PremiumTaxReconciler extends PolicyDutyReconciler
{
    public function subledger(): string
    {
        return 'premium_tax';
    }

    public function accountRoles(): array
    {
        return ['premium_tax_payable'];
    }

    protected function amountExpression(): string
    {
        return "case t.type when 'cancellation' then -coalesce((t.amounts->>'tax_reversal')::bigint, 0) when 'renewal' then 0 else t.tax_delta_minor end";
    }
}
