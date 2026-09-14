<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Regulatory\Application;

use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;

/**
 * A regulatory reporting period: a calendar quarter (`2026-Q3`, July–September 2026) or a calendar year (`2026`). ASSUMPTION A-262: IDRA returns follow
 * calendar quarters and the calendar year, whatever the entity's own fiscal year.
 */
final readonly class RegulatoryPeriod
{
    private function __construct(
        public string $key,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    /** @throws BusinessRuleViolation REGULATORY_PERIOD_INVALID */
    public static function fromKey(string $key): self
    {
        if (preg_match('/^(\d{4})-Q([1-4])$/', $key, $m) === 1) {
            $start = CarbonImmutable::create((int) $m[1], ((int) $m[2] - 1) * 3 + 1, 1)?->startOfDay() ?? throw self::invalid();

            return new self($key, $start, $start->addMonthsNoOverflow(2)->endOfMonth()->startOfDay());
        }
        if (preg_match('/^\d{4}$/', $key) === 1) {
            $start = CarbonImmutable::create((int) $key, 1, 1)?->startOfDay() ?? throw self::invalid();

            return new self($key, $start, $start->endOfYear()->startOfDay());
        }

        throw self::invalid();
    }

    public static function quarterOf(CarbonImmutable $day): self
    {
        return self::fromKey($day->year.'-Q'.$day->quarter);
    }

    public function isQuarter(): bool
    {
        return str_contains($this->key, 'Q');
    }

    /** "Q3 2026 (July–September)" or "Year 2026". */
    public function label(): string
    {
        return $this->isQuarter()
            ? 'Q'.$this->start->quarter.' '.$this->start->year.' ('.$this->start->format('F').'–'.$this->end->format('F').')'
            : 'Year '.$this->start->year;
    }

    /** The quarter before this one (for a quarter). */
    public function previousQuarter(): self
    {
        return self::quarterOf($this->start->subDay());
    }

    /**
     * Quarter keys to pick from: the $count quarters up to the one containing $today, newest first, then the last two years.
     *
     * @return list<string>
     */
    public static function choices(CarbonImmutable $today, int $count = 6): array
    {
        $keys = [];
        $quarter = self::quarterOf($today);
        for ($i = 0; $i < $count; $i++) {
            $keys[] = $quarter->key;
            $quarter = $quarter->previousQuarter();
        }

        return [...$keys, (string) $today->year, (string) ($today->year - 1)];
    }

    private static function invalid(): BusinessRuleViolation
    {
        return new BusinessRuleViolation('REGULATORY_PERIOD_INVALID', 'Choose a quarter such as 2026-Q3 or a year such as 2026.');
    }
}
