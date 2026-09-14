<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Regulatory\Application\Provisions;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Market gap G5, IBNR method 2 (ASSUMPTION A-265): a paid chain ladder by accident quarter. Paid claims come from the claims subledger (claim payments released,
 * by the claim's loss date and the payment date) and from `claims_paid_history` (paid claims from before this system). The triangle covers the last
 * `triangle_quarters` accident quarters to the valuation quarter; development factors are volume-weighted (Σ next column ÷ Σ this column over the accident
 * quarters that have both), no tail factor. Ultimate = paid to date × the factors still to come; IBNR = ultimate − paid to date − open case reserves, never below
 * zero. With paid claims in fewer than `chain_ladder_min_accident_quarters` accident quarters the method is not available (the class uses the percentage).
 * Arithmetic is exact (brick/math); factors are shown to four decimals.
 */
final class PaidChainLadder
{
    /**
     * Incremental paid claims per class: class → accident quarter index → development quarter → amount (minor units).
     *
     * @return array<string, array<int, array<int, int>>>
     */
    public function incrementalPaid(string $entityId, CarbonImmutable $valuationEnd): array
    {
        $index = 'extract(year from %1$s)::int * 4 + extract(quarter from %1$s)::int - 1';
        $system = DB::table('claim_payments as cp')->join('claims as c', 'c.id', '=', 'cp.claim_id')->join('policies as p', 'p.id', '=', 'c.policy_id')
            ->join('products as pr', 'pr.id', '=', 'p.product_id')->where('c.entity_id', $entityId)->where('cp.status', 'paid')->where('cp.paid_on', '<=', $valuationEnd->toDateString())
            ->groupByRaw('1, 2, 3')->selectRaw('pr.lob as class, '.sprintf($index, 'c.loss_date').' as aq, '.sprintf($index, 'cp.paid_on').' as pq, sum(cp.amount_minor) as paid');
        $history = DB::table('claims_paid_history')->where('entity_id', $entityId)->where('paid_quarter_start', '<=', $valuationEnd->toDateString())
            ->groupByRaw('1, 2, 3')->selectRaw('class, '.sprintf($index, 'accident_quarter_start').' as aq, '.sprintf($index, 'paid_quarter_start').' as pq, sum(paid_minor) as paid');
        $cells = [];
        foreach ($system->unionAll($history)->get() as $row) {
            $dev = max(0, (int) $row->pq - (int) $row->aq);
            $cells[(string) $row->class][(int) $row->aq][$dev] = ($cells[(string) $row->class][(int) $row->aq][$dev] ?? 0) + (int) $row->paid;
        }

        return $cells;
    }

    /**
     * @param array<int, array<int, int>> $incremental accident quarter index → development quarter → paid
     * @return array{available: bool, accident_quarters_with_paid: int, paid_to_date_minor: int, ultimate_minor: int, unpaid_minor: int, dev_labels: list<string>, factors: list<string>,
     *     rows: list<array{accident_quarter: string, cells: list<int|null>, paid_to_date_minor: int, ultimate_minor: int, unpaid_minor: int}>}
     */
    public function project(array $incremental, CarbonImmutable $valuationEnd): array
    {
        $quarters = max(2, (int) config('erp.regulatory.provisions.triangle_quarters', 8));
        $valuation = $valuationEnd->year * 4 + $valuationEnd->quarter - 1;
        $first = $valuation - $quarters + 1;
        /** @var array<int, array<int, int>> $cumulative accident row (0 = oldest) → development → cumulative paid */
        $cumulative = [];
        for ($i = 0; $i < $quarters; $i++) {
            $running = 0;
            for ($k = 0; $k <= $quarters - 1 - $i; $k++) {
                $running += $incremental[$first + $i][$k] ?? 0;
                $cumulative[$i][$k] = $running;
            }
        }

        $factors = [];
        for ($k = 0; $k < $quarters - 1; $k++) {
            [$numerator, $denominator] = [0, 0];
            for ($i = 0; $i < $quarters - 1 - $k; $i++) {
                $numerator += $cumulative[$i][$k + 1];
                $denominator += $cumulative[$i][$k];
            }
            $factors[$k] = $denominator > 0 ? BigRational::of($numerator)->dividedBy($denominator) : BigRational::of(1);
        }

        $rows = [];
        $withPaid = 0;
        [$paidTotal, $ultimateTotal] = [0, 0];
        for ($i = 0; $i < $quarters; $i++) {
            $latest = $quarters - 1 - $i;
            $paid = $cumulative[$i][$latest];
            $ultimate = BigRational::of($paid);
            for ($k = $latest; $k < $quarters - 1; $k++) {
                $ultimate = $ultimate->multipliedBy($factors[$k]);
            }
            $ultimateMinor = $ultimate->toScale(0, RoundingMode::HalfUp)->getUnscaledValue()->toInt();
            $withPaid += $paid > 0 ? 1 : 0;
            $paidTotal += $paid;
            $ultimateTotal += $ultimateMinor;
            $aq = $first + $i;
            $rows[] = ['accident_quarter' => intdiv($aq, 4).'-Q'.($aq % 4 + 1), 'cells' => array_map(fn (int $k): ?int => $k <= $latest ? $cumulative[$i][$k] : null, range(0, $quarters - 1)),
                'paid_to_date_minor' => $paid, 'ultimate_minor' => $ultimateMinor, 'unpaid_minor' => $ultimateMinor - $paid];
        }

        return ['available' => $withPaid >= (int) config('erp.regulatory.provisions.chain_ladder_min_accident_quarters', 4), 'accident_quarters_with_paid' => $withPaid,
            'paid_to_date_minor' => $paidTotal, 'ultimate_minor' => $ultimateTotal, 'unpaid_minor' => $ultimateTotal - $paidTotal,
            'dev_labels' => array_map(fn (int $k): string => (string) $k, range(0, $quarters - 1)),
            'factors' => array_map(fn (BigRational $f): string => (string) $f->toScale(4, RoundingMode::HalfUp), array_values($factors)), 'rows' => $rows];
    }
}
