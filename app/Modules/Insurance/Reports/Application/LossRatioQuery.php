<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Reports\Application;

use App\Modules\Accounting\Application\Reports\FinancialStatementsQuery;
use App\Modules\Insurance\Policy\Domain\PremiumMath;
use Carbon\CarbonImmutable;

/**
 * Loss ratio by product, branch or agent for a date range, from the GL (spec §4 "loss ratio by any dimension"): incurred claims = claims_expense
 * movement (reserves set less released) − claims_recovery_income; earned premium = premium_income movement; ratio in basis points, half-even,
 * null when nothing was earned. Interpretation: incurred is net of recoveries. Each row drills to the three accounts' activity for its value.
 */
final class LossRatioQuery
{
    public const DIMENSIONS = ['product', 'branch', 'agent'];

    public function __construct(private readonly FinancialStatementsQuery $statements) {}

    /**
     * @return array{entity_id: string, from: string, to: string, by: string, totals: array{earned_premium_minor: int, incurred_claims_minor: int, loss_ratio_bp: int|null},
     *     rows: list<array{dimension_value: string|null, earned_premium_minor: int, claims_expense_minor: int, recoveries_minor: int, incurred_claims_minor: int,
     *     loss_ratio_bp: int|null, drill: array<string, string>}>}
     *
     * @throws \InvalidArgumentException when $by is not product, branch or agent
     */
    public function lossRatio(string $entityId, CarbonImmutable $from, CarbonImmutable $to, string $by): array
    {
        if (! in_array($by, self::DIMENSIONS, true)) {
            throw new \InvalidArgumentException("Loss ratio is reported by product, branch or agent, not '{$by}'.");
        }
        $movements = [];
        foreach (['premium_income', 'claims_expense', 'claims_recovery_income'] as $role) {
            $movements[$role] = $this->statements->roleMovementByDimension($entityId, $role, $from, $to, $by);
        }
        $values = array_keys($movements['premium_income']['by_dimension'] + $movements['claims_expense']['by_dimension'] + $movements['claims_recovery_income']['by_dimension']);
        sort($values);

        $rows = [];
        foreach ($values as $value) {
            $key = (string) $value;
            $earned = $movements['premium_income']['by_dimension'][$key] ?? 0;
            $expense = $movements['claims_expense']['by_dimension'][$key] ?? 0;
            $recoveries = $movements['claims_recovery_income']['by_dimension'][$key] ?? 0;
            $rows[] = ['dimension_value' => $key === '' ? null : $key, 'earned_premium_minor' => $earned, 'claims_expense_minor' => $expense, 'recoveries_minor' => $recoveries,
                'incurred_claims_minor' => $expense - $recoveries, 'loss_ratio_bp' => self::ratio($expense - $recoveries, $earned),
                'drill' => $this->drill($movements, $entityId, $from, $to, $by, $key)];
        }
        $earnedTotal = array_sum(array_column($rows, 'earned_premium_minor'));
        $incurredTotal = array_sum(array_column($rows, 'incurred_claims_minor'));

        return ['entity_id' => $entityId, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'by' => $by,
            'totals' => ['earned_premium_minor' => $earnedTotal, 'incurred_claims_minor' => $incurredTotal, 'loss_ratio_bp' => self::ratio($incurredTotal, $earnedTotal)], 'rows' => $rows];
    }

    private static function ratio(int $incurred, int $earned): ?int
    {
        if ($earned <= 0) {
            return null;
        }

        return PremiumMath::prorate($incurred, 10_000, $earned);
    }

    /**
     * @param array<string, array{account_ids: list<string>, by_dimension: array<string, int>}> $movements
     * @return array<string, string> role → account activity URL filtered to this dimension value
     */
    private function drill(array $movements, string $entityId, CarbonImmutable $from, CarbonImmutable $to, string $by, string $value): array
    {
        $drill = [];
        foreach ($movements as $role => $movement) {
            foreach ($movement['account_ids'] as $accountId) {
                $drill[$role] = "/api/reports/accounts/{$accountId}/activity?entity_id={$entityId}&from={$from->toDateString()}&to={$to->toDateString()}&dimension={$by}&value={$value}";
            }
        }

        return $drill;
    }
}
