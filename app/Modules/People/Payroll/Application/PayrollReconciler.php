<?php

declare(strict_types=1);

namespace App\Modules\People\Payroll\Application;

use App\Modules\Accounting\Application\Contracts\AccountBalanceReconciler;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design addendum §B.10.7 `payroll` reconciliation: what the company owes its employees in net pay (`salary_payable`), per employee, rebuilt from dated rows —
 * nets of runs posted on or before the date, less the commission already in them, plus commission paid through payroll on or before the date (its
 * COMMISSION_PAYOUT_TO_PAYROLL credit), less nets of runs paid on or before the date. Every GL line on the account counts (no exclusions): a manual journal
 * to salary payable is a difference to explain.
 */
final class PayrollReconciler implements AccountBalanceReconciler
{
    public function subledger(): string
    {
        return 'payroll';
    }

    public function accountRoles(): array
    {
        return ['salary_payable'];
    }

    public function excludesUnattributedLines(): bool
    {
        return false;
    }

    public function itemDimension(): string
    {
        return 'employee';
    }

    public function balanceAt(string $entityId, CarbonImmutable $asOf): Money
    {
        $currency = (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency');

        return Money::ofMinor(array_sum(array_column($this->itemsAt($entityId, $asOf), 'amount_minor')), $currency);
    }

    /** @return list<array{object_type: string, object_id: string, amount_minor: int}> */
    public function itemsAt(string $entityId, CarbonImmutable $asOf): array
    {
        $day = $asOf->toDateString();
        $amounts = [];
        $slips = DB::table('payslips as p')->join('payroll_runs as r', 'r.id', '=', 'p.run_id')->where('r.entity_id', $entityId)->whereIn('r.status', ['posted', 'paid'])
            ->where('r.posted_on', '<=', $day)
            ->get(['p.employee_id', 'p.net_minor', 'p.commission_minor', 'r.status', 'r.paid_on']);
        foreach ($slips as $s) {
            $owed = (int) $s->net_minor - (int) $s->commission_minor;
            if ($s->status === 'paid' && $s->paid_on !== null && (string) $s->paid_on <= $day) {
                $owed -= (int) $s->net_minor;
            }
            $amounts[(string) $s->employee_id] = ($amounts[(string) $s->employee_id] ?? 0) + $owed;
        }
        $commission = DB::table('payroll_inputs')->where('source_type', 'commission_statement')->where('pre_accrued', true)->where('status', '<>', 'cancelled')
            ->where('accrued_on', '<=', $day)->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->get(['employee_id', 'employee_ref', 'amount_minor']);
        foreach ($commission as $c) {
            $key = (string) ($c->employee_id ?? $c->employee_ref ?? 'unknown');
            $amounts[$key] = ($amounts[$key] ?? 0) + (int) $c->amount_minor;
        }
        ksort($amounts);
        $items = [];
        foreach ($amounts as $employeeId => $amount) {
            if ($amount !== 0) {
                $items[] = ['object_type' => 'employee', 'object_id' => (string) $employeeId, 'amount_minor' => $amount];
            }
        }

        return $items;
    }
}
