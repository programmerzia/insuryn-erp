<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Regulatory\Application\Provisions;

use App\Modules\Insurance\Regulatory\Application\RegulatoryPeriod;
use App\Modules\Insurance\Reports\Application\OutstandingClaimsQuery;
use App\Modules\Insurance\Reports\Application\PremiumRegisterQuery;
use App\Modules\Insurance\Reports\Application\UnearnedPremiumQuery;
use Illuminate\Support\Facades\DB;

/**
 * Market gap G5: the figures of a quarter's technical provisions per class — (a) unearned premium at the quarter end from the unearned premium register, (b) IBNR by
 * the percentage method (A-264) and, where history allows, the paid chain ladder (A-265), with the method chosen per class, and (c) a premium deficiency check
 * note (A-266). Nothing is written here.
 */
final class TechnicalProvisionCalculator
{
    public const PERCENTAGE = 'percentage';

    public const CHAIN_LADDER = 'chain_ladder';

    public function __construct(
        private readonly PremiumRegisterQuery $premiums,
        private readonly UnearnedPremiumQuery $unearned,
        private readonly OutstandingClaimsQuery $outstanding,
        private readonly PaidChainLadder $chainLadder,
    ) {}

    /**
     * @param array<string, string> $methods class → percentage | chain_ladder (missing: chain ladder when available, else percentage)
     * @param array<string, int> $prior class → IBNR provision of the prior posted run
     * @return array{classes: list<array<string, mixed>>, triangles: array<string, array<string, mixed>>, total_ibnr_minor: int, total_upr_minor: int, notes: list<string>}
     */
    public function calculate(string $entityId, RegulatoryPeriod $quarter, array $methods, array $prior): array
    {
        $baseQuarters = max(1, (int) config('erp.regulatory.provisions.premium_base_quarters', 4));
        $baseStart = $quarter->start->subMonthsNoOverflow(3 * ($baseQuarters - 1));
        $netPremium = [];
        foreach ($this->premiums->register($entityId, $baseStart, $quarter->end)['rows'] as $row) {
            $netPremium[$row['class']] = ($netPremium[$row['class']] ?? 0) + $row['net_minor'];
        }
        $upr = array_column($this->unearned->unearned($entityId, $quarter->end)['by_class'], 'unearned_minor', 'group');
        $open = $this->outstanding->outstanding($entityId, $quarter->end)['rows'];
        $claimClass = DB::table('claims as c')->join('policies as p', 'p.id', '=', 'c.policy_id')->join('products as pr', 'pr.id', '=', 'p.product_id')
            ->whereIn('c.id', array_column($open, 'claim_id'))->pluck('pr.lob', 'c.id');
        $caseReserves = [];
        foreach ($open as $row) {
            $class = (string) ($claimClass[$row['claim_id']] ?? 'unknown');
            $caseReserves[$class] = ($caseReserves[$class] ?? 0) + $row['outstanding_reserve_minor'] + $row['approved_unpaid_minor'];
        }
        $paid = $this->chainLadder->incrementalPaid($entityId, $quarter->end);
        $labels = DB::table('product_classes')->pluck('name_en', 'code')->all();

        $classes = array_values(array_unique([...array_keys($netPremium), ...array_keys($upr), ...array_keys($caseReserves), ...array_keys($paid), ...array_keys($prior)]));
        sort($classes);
        $lines = [];
        $triangles = [];
        [$totalIbnr, $totalUpr] = [0, 0];
        foreach ($classes as $class) {
            $class = (string) $class;
            $rate = (int) config("erp.regulatory.provisions.ibnr_percentage_bp.{$class}", config('erp.regulatory.provisions.ibnr_percentage_bp.default', 0));
            $base = max(0, $netPremium[$class] ?? 0);
            $percentageIbnr = intdiv($base * $rate, 10_000);
            $triangle = $this->chainLadder->project($paid[$class] ?? [], $quarter->end);
            $case = $caseReserves[$class] ?? 0;
            $chainIbnr = max(0, $triangle['unpaid_minor'] - $case);
            $requested = $methods[$class] ?? null;
            $method = match (true) {
                $requested === self::PERCENTAGE => self::PERCENTAGE,
                $triangle['available'] && ($requested === null || $requested === self::CHAIN_LADDER) => self::CHAIN_LADDER,
                default => self::PERCENTAGE,
            };
            $ibnr = $method === self::CHAIN_LADDER ? $chainIbnr : $percentageIbnr;
            $unearned = (int) ($upr[$class] ?? 0);
            $lossRatio = (int) config("erp.regulatory.provisions.expected_loss_ratio_bp.{$class}", config('erp.regulatory.provisions.expected_loss_ratio_bp.default', 0));
            $maintenance = (int) config('erp.regulatory.provisions.maintenance_expense_ratio_bp', 0);
            $expectedCost = intdiv($unearned * ($lossRatio + $maintenance), 10_000);
            $deficiency = max(0, $expectedCost - $unearned);
            $label = (string) ($labels[$class] ?? ucfirst(str_replace('_', ' ', $class)));
            if ($base === 0 && $unearned === 0 && $case === 0 && $triangle['paid_to_date_minor'] === 0 && ($prior[$class] ?? 0) === 0) {
                continue;
            }
            $lines[] = ['class' => $class, 'label' => $label, 'net_premium_base_minor' => $base, 'percentage_bp' => $rate, 'percentage_ibnr_minor' => $percentageIbnr,
                'chain_ladder_available' => $triangle['available'], 'chain_ladder_ibnr_minor' => $chainIbnr, 'paid_to_date_minor' => $triangle['paid_to_date_minor'],
                'ultimate_minor' => $triangle['ultimate_minor'], 'case_reserves_minor' => $case, 'method' => $method,
                'fell_back' => $requested === self::CHAIN_LADDER && ! $triangle['available'], 'ibnr_minor' => $ibnr, 'prior_ibnr_minor' => $prior[$class] ?? 0,
                'movement_minor' => $ibnr - ($prior[$class] ?? 0), 'upr_minor' => $unearned, 'expected_loss_ratio_bp' => $lossRatio, 'maintenance_expense_bp' => $maintenance,
                'expected_cost_minor' => $expectedCost, 'deficiency_minor' => $deficiency,
                'deficiency_note' => $deficiency > 0
                    ? "{$label}: expected claims and expenses on unexpired cover exceed the unearned premium — a premium deficiency reserve may be needed (review)."
                    : "{$label}: unearned premium covers expected claims and expenses on unexpired cover; no premium deficiency."];
            $triangles[$class] = $triangle;
            $totalIbnr += $ibnr;
            $totalUpr += $unearned;
        }

        return ['classes' => $lines, 'triangles' => $triangles, 'total_ibnr_minor' => $totalIbnr, 'total_upr_minor' => $totalUpr, 'notes' => [
            "IBNR percentage method: a placeholder rate per class on net written premium (excluding VAT, after cancellations) over the {$baseQuarters} quarters to the quarter end (ASSUMPTION A-264, verify).",
            'Chain ladder: paid claims by accident quarter, volume-weighted development factors, no tail; IBNR = ultimate − paid − open case reserves (ASSUMPTION A-265).',
            'Premium deficiency: unearned premium against unearned premium × (expected loss ratio + maintenance expense ratio), placeholder ratios (ASSUMPTION A-266). A note only; nothing is posted.',
        ]];
    }
}
