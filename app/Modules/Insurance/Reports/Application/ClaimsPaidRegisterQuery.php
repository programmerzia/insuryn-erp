<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Application;

use App\Modules\Accounting\Application\Queries\SourceJournalQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Claims paid register (spec §4 Claims reports): payments paid in a date range, each drilling to its approval and payment journals. */
final class ClaimsPaidRegisterQuery
{
    public function __construct(private readonly SourceJournalQuery $journals) {}

    /**
     * @return array{entity_id: string, from: string, to: string, totals: array{amount_minor: int},
     *     rows: list<array{claim_payment_id: string, paid_on: string, claim_id: string, claim_number: string, policy_id: string, policy_number: string|null, product_code: string,
     *     branch_id: string, payee_party_id: string, amount_minor: int, currency: string, journals: list<array{journal_id: string, journal_number: string|null, posting_date: string, status: string, url: string}>}>}
     */
    public function register(string $entityId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $payments = DB::table('claim_payments as cp')->join('claims as c', 'c.id', '=', 'cp.claim_id')->join('policies as p', 'p.id', '=', 'c.policy_id')
            ->join('products as pr', 'pr.id', '=', 'p.product_id')->where('c.entity_id', $entityId)->where('cp.status', 'paid')
            ->whereBetween('cp.paid_on', [$from->toDateString(), $to->toDateString()])->orderBy('cp.paid_on')->orderBy('c.number')->orderBy('cp.id')
            ->get(['cp.id', 'cp.paid_on', 'c.id as claim_id', 'c.number', 'p.id as policy_id', 'p.number as policy_number', 'pr.code', 'c.branch_id', 'cp.payee_party_id', 'cp.amount_minor', 'cp.currency']);
        $journals = $this->journals->bySource('claim_payment', $payments->pluck('id')->map(fn ($id): string => (string) $id)->all());

        $rows = [];
        foreach ($payments as $payment) {
            $rows[] = ['claim_payment_id' => (string) $payment->id, 'paid_on' => (string) $payment->paid_on, 'claim_id' => (string) $payment->claim_id, 'claim_number' => (string) $payment->number,
                'policy_id' => (string) $payment->policy_id, 'policy_number' => $payment->policy_number === null ? null : (string) $payment->policy_number, 'product_code' => (string) $payment->code,
                'branch_id' => (string) $payment->branch_id, 'payee_party_id' => (string) $payment->payee_party_id, 'amount_minor' => (int) $payment->amount_minor,
                'currency' => (string) $payment->currency, 'journals' => $journals[(string) $payment->id] ?? []];
        }

        return ['entity_id' => $entityId, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'totals' => ['amount_minor' => array_sum(array_column($rows, 'amount_minor'))], 'rows' => $rows];
    }
}
