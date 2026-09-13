<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Incentives;

use Carbon\CarbonImmutable;
use DomainException;

/** ASSUMPTION A-24: incentive and target periods are calendar months, quarters (Jan, Apr, Jul, Oct) and years (fiscal periods are LATER). */
final readonly class IncentivePeriod
{
    public const TYPES = ['monthly', 'quarterly', 'annual'];

    private function __construct(public string $type, public CarbonImmutable $start, public CarbonImmutable $end) {}

    /** @throws DomainException when $start is not the first day of such a period */
    public static function starting(string $type, CarbonImmutable $start): self
    {
        $valid = match ($type) {
            'monthly' => $start->day === 1,
            'quarterly' => $start->day === 1 && in_array($start->month, [1, 4, 7, 10], true),
            'annual' => $start->day === 1 && $start->month === 1,
            default => false,
        };
        if (! $valid) {
            throw new DomainException("A {$type} period starts on the first day of its ".($type === 'annual' ? 'year' : ($type === 'quarterly' ? 'quarter' : 'month')).'.');
        }

        return new self($type, $start, self::endOf($type, $start));
    }

    /** The period of this type that ends on $day, or null when $day ends none (e.g. 31 August for a quarterly plan). */
    public static function endingOn(string $type, CarbonImmutable $day): ?self
    {
        if (! $day->isSameDay($day->endOfMonth())) {
            return null;
        }
        $start = match ($type) {
            'monthly' => $day->startOfMonth(),
            'quarterly' => in_array($day->month, [3, 6, 9, 12], true) ? $day->startOfMonth()->subMonths(2) : null,
            'annual' => $day->month === 12 ? $day->startOfYear() : null,
            default => null,
        };

        return $start === null ? null : new self($type, $start->startOfDay(), self::endOf($type, $start));
    }

    private static function endOf(string $type, CarbonImmutable $start): CarbonImmutable
    {
        return match ($type) {
            'quarterly' => $start->addMonths(2)->endOfMonth()->startOfDay(),
            'annual' => $start->endOfYear()->startOfDay(),
            default => $start->endOfMonth()->startOfDay(),
        };
    }
}
