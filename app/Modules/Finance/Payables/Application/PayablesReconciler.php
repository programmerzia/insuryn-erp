<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Application;

use App\Modules\Accounting\Application\Contracts\AccountBalanceReconciler;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AP subledger reconciliation (addendum v2 §B.4 "Reconciliation and close", close task `ap_reconciliation`): what is owed per supplier (payee party) —
 * the payable of bills posted on or before the date, less bills cancelled on or before it, less payment run items released with a pay date on or before
 * it — against the `accounts_payable` account per `payee_party` dimension.
 *
 * DECISION D-103: an AccountBalanceReconciler (D-82 pattern) rather than a `subledger_controls` control account, so accounts payable stays an ordinary
 * account and existing tenants need no chart change. Lines without a payee (commission payouts routed to AP since D6, which the commission subledger
 * owns, or a manual correction) are outside the register.
 */
final class PayablesReconciler implements AccountBalanceReconciler
{
    public function subledger(): string
    {
        return 'ap';
    }

    public function itemDimension(): string
    {
        return 'payee_party';
    }

    /** @return list<string> */
    public function accountRoles(): array
    {
        return ['accounts_payable'];
    }

    public function excludesUnattributedLines(): bool
    {
        return true;
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
        $posted = DB::table('ap_bills as b')->join('suppliers as s', 's.id', '=', 'b.supplier_id')->where('b.entity_id', $entityId)
            ->whereNotNull('b.accounting_date')->where('b.accounting_date', '<=', $day)
            ->selectRaw('s.party_id::text as object_id, b.payable_minor as amount');
        $cancelled = DB::table('ap_bills as b')->join('suppliers as s', 's.id', '=', 'b.supplier_id')->where('b.entity_id', $entityId)
            ->whereNotNull('b.accounting_date')->where('b.status', 'cancelled')->where('b.cancelled_on', '<=', $day)
            ->selectRaw('s.party_id::text as object_id, -b.payable_minor as amount');
        $paid = DB::table('payment_run_items as i')->join('payment_runs as r', 'r.id', '=', 'i.run_id')->where('r.entity_id', $entityId)
            ->where('i.status', 'released')->where('r.pay_date', '<=', $day)
            ->selectRaw('i.payee_party_id::text as object_id, -i.amount_minor as amount');
        $rows = DB::query()->fromSub($posted->unionAll($cancelled)->unionAll($paid), 'm')->groupBy('object_id')->orderBy('object_id')
            ->selectRaw('object_id, sum(amount) as amount')->get();
        $items = [];
        foreach ($rows as $row) {
            if ((int) $row->amount !== 0) {
                $items[] = ['object_type' => 'supplier', 'object_id' => (string) $row->object_id, 'amount_minor' => (int) $row->amount];
            }
        }

        return $items;
    }
}
