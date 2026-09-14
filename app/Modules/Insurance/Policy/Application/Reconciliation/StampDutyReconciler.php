<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application\Reconciliation;

/** Gap fix GA-43: stamp duty on policies (D-37; POLICY_ISSUED and POLICY_ENDORSED credit it, a cancellation does not give it back) against `stamp_duty_payable`. */
final class StampDutyReconciler extends PolicyDutyReconciler
{
    public function subledger(): string
    {
        return 'stamp_duty';
    }

    public function accountRoles(): array
    {
        return ['stamp_duty_payable'];
    }

    protected function amountExpression(): string
    {
        return "case t.type when 'cancellation' then 0 when 'renewal' then 0 else t.stamp_duty_delta_minor end";
    }
}
