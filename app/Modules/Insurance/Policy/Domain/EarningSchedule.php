<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Domain;

use App\Modules\Insurance\Product\Domain\Enums\EarningMethod;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * How net premium becomes earned (design §4.3, §4.4, §2.4 earning_method). Pure: no database.
 *
 * - daily_365: each day of cover earns net / cover-days (actual days of the layer, so leap-year terms stay exact).
 * - monthly: the layer is cut into earning months from its start date; each earns round(net / months) and the last
 *   earning month absorbs the rounding residual (§4.3). A calendar month is credited with the earning months that
 *   END in it (revenue is never recognised ahead of cover); a part month earns its share pro-rata by days.
 * Every amount is rounded half-even and the last piece absorbs the rounding, so Σ = net exactly.
 */
final class EarningSchedule
{
    /**
     * Earned premium per calendar month ('Y-m').
     *
     * @param list<EarningLayer> $layers
     * @return array<string, int>
     */
    public static function byCalendarMonth(EarningMethod $method, array $layers): array
    {
        $months = [];
        foreach ($layers as $layer) {
            foreach (self::layerPieces($method, $layer) as [$monthKey, $amount]) {
                $months[$monthKey] = ($months[$monthKey] ?? 0) + $amount;
            }
        }
        ksort($months);

        return $months;
    }

    /**
     * Premium earned by cover before $cutoff (exclusive) — e.g. a cancellation date.
     *
     * @param list<EarningLayer> $layers
     */
    public static function earnedBefore(EarningMethod $method, array $layers, CarbonImmutable $cutoff): int
    {
        $earned = 0;
        foreach ($layers as $layer) {
            $earned += match ($method) {
                EarningMethod::Daily365 => self::dailyEarnedBefore($layer, $cutoff),
                EarningMethod::Monthly => self::monthlyEarnedBefore($layer, $cutoff),
                EarningMethod::TwentyFourths => throw new InvalidArgumentException('The 24ths earning method is not implemented.'),
            };
        }

        return $earned;
    }

    /** @return list<array{string, int}> [calendar month, amount] */
    private static function layerPieces(EarningMethod $method, EarningLayer $layer): array
    {
        return match ($method) {
            EarningMethod::Daily365 => self::dailyPieces($layer),
            EarningMethod::Monthly => self::monthlyPieces($layer),
            EarningMethod::TwentyFourths => throw new InvalidArgumentException('The 24ths earning method is not implemented.'),
        };
    }

    /** @return list<array{string, int}> */
    private static function dailyPieces(EarningLayer $layer): array
    {
        $coverDays = self::days($layer->start, $layer->end);
        $pieces = [];
        $allocated = 0;
        for ($month = $layer->start->startOfMonth(); $month->lessThanOrEqualTo($layer->end); $month = $month->addMonthNoOverflow()) {
            $from = $month->max($layer->start);
            $to = $month->endOfMonth()->startOfDay()->min($layer->end);
            $isLast = $to->equalTo($layer->end);
            $amount = $isLast ? $layer->netMinor - $allocated : PremiumMath::prorate($layer->netMinor, self::days($from, $to), $coverDays);
            $allocated += $amount;
            $pieces[] = [$month->format('Y-m'), $amount];
        }

        return $pieces;
    }

    /** @return list<array{string, int}> */
    private static function monthlyPieces(EarningLayer $layer): array
    {
        $count = self::earningMonthCount($layer);
        $pieces = [];
        for ($k = 0; $k < $count; $k++) {
            $amount = self::monthAmount($layer, $count, $k);
            $pieces[] = [self::earningMonthEnd($layer, $k)->format('Y-m'), $amount];
        }

        return $pieces;
    }

    private static function dailyEarnedBefore(EarningLayer $layer, CarbonImmutable $cutoff): int
    {
        if ($cutoff->lessThanOrEqualTo($layer->start)) {
            return 0;
        }
        if ($cutoff->greaterThan($layer->end)) {
            return $layer->netMinor;
        }

        return PremiumMath::prorate($layer->netMinor, self::days($layer->start, $cutoff->subDay()), self::days($layer->start, $layer->end));
    }

    private static function monthlyEarnedBefore(EarningLayer $layer, CarbonImmutable $cutoff): int
    {
        if ($cutoff->lessThanOrEqualTo($layer->start)) {
            return 0;
        }
        if ($cutoff->greaterThan($layer->end)) {
            return $layer->netMinor;
        }
        $count = self::earningMonthCount($layer);
        $completed = 0;
        while ($completed < $count && self::earningMonthEnd($layer, $completed)->lessThan($cutoff)) {
            $completed++;
        }
        $earned = 0;
        for ($k = 0; $k < $completed; $k++) {
            $earned += self::monthAmount($layer, $count, $k);
        }
        if ($completed < $count) {
            $monthStart = $layer->start->addMonthsNoOverflow($completed);
            $monthDays = self::days($monthStart, self::earningMonthEnd($layer, $completed));
            $earned += PremiumMath::prorate(self::monthAmount($layer, $count, $completed), self::days($monthStart, $cutoff->subDay()), $monthDays);
        }

        return $earned;
    }

    /** Earning month $k of $count: an equal rounded share; the last month absorbs the residual. */
    private static function monthAmount(EarningLayer $layer, int $count, int $k): int
    {
        $share = PremiumMath::prorate($layer->netMinor, 1, $count);

        return $k === $count - 1 ? $layer->netMinor - $share * ($count - 1) : $share;
    }

    private static function earningMonthCount(EarningLayer $layer): int
    {
        $count = 0;
        while ($layer->start->addMonthsNoOverflow($count)->lessThanOrEqualTo($layer->end)) {
            $count++;
        }

        return max(1, $count);
    }

    private static function earningMonthEnd(EarningLayer $layer, int $k): CarbonImmutable
    {
        return $layer->start->addMonthsNoOverflow($k + 1)->subDay()->min($layer->end);
    }

    private static function days(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) $from->diffInDays($to) + 1;
    }
}
