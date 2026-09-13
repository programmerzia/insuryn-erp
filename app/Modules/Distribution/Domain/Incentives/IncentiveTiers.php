<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Incentives;

use DomainException;

/**
 * Distribution design note §4 "incentive tiers computed at period end": a tier applies from an achievement (actual / target, basis points)
 * upwards; the highest tier reached pays its bonus — a fixed amount, or basis points of the metric's value (money metrics only).
 */
final readonly class IncentiveTiers
{
    public const METRICS = ['premium', 'policies', 'persistency', 'collections'];
    private const MONEY_METRICS = ['premium', 'collections'];

    /** @param list<array{achievement_bp_from: int, bonus: array{type: string, value: int}}> $tiers ascending */
    private function __construct(public string $metric, public array $tiers) {}

    /**
     * @param array<mixed> $tiers
     *
     * @throws DomainException
     */
    public static function fromArray(string $metric, array $tiers): self
    {
        if (! in_array($metric, self::METRICS, true)) {
            throw new DomainException("Metric {$metric} is not premium, policies, persistency or collections.");
        }
        if ($tiers === []) {
            throw new DomainException('A plan needs at least one tier.');
        }
        $clean = [];
        foreach ($tiers as $tier) {
            $from = is_array($tier) ? ($tier['achievement_bp_from'] ?? null) : null;
            $bonus = is_array($tier) ? ($tier['bonus'] ?? null) : null;
            if (! is_int($from) || $from < 1 || ! is_array($bonus) || ! in_array($bonus['type'] ?? null, ['fixed_minor', 'percent_of_metric'], true)
                || ! is_int($bonus['value'] ?? null) || $bonus['value'] <= 0 || ($bonus['type'] === 'percent_of_metric' && $bonus['value'] > 10_000)) {
                throw new DomainException('Each tier needs achievement_bp_from ≥ 1 and a bonus {type: fixed_minor | percent_of_metric, value > 0}.');
            }
            if ($bonus['type'] === 'percent_of_metric' && ! in_array($metric, self::MONEY_METRICS, true)) {
                throw new DomainException("A percentage bonus needs a money metric (premium or collections), not {$metric}.");
            }
            $clean[] = ['achievement_bp_from' => $from, 'bonus' => ['type' => (string) $bonus['type'], 'value' => (int) $bonus['value']]];
        }
        usort($clean, fn (array $a, array $b): int => $a['achievement_bp_from'] <=> $b['achievement_bp_from']);
        if (count(array_unique(array_column($clean, 'achievement_bp_from'))) !== count($clean)) {
            throw new DomainException('Two tiers cannot start at the same achievement.');
        }

        return new self($metric, $clean);
    }

    /** @return array{achievement_bp: int, tier: int|null, bonus_minor: int} tier is 1-based */
    public function bonusFor(int $targetValue, int $actualValue): array
    {
        $achievement = $targetValue <= 0 ? 0 : intdiv(max(0, $actualValue) * 10_000, $targetValue);
        $reached = null;
        foreach ($this->tiers as $index => $tier) {
            if ($achievement >= $tier['achievement_bp_from']) {
                $reached = $index;
            }
        }
        if ($reached === null) {
            return ['achievement_bp' => $achievement, 'tier' => null, 'bonus_minor' => 0];
        }
        $bonus = $this->tiers[$reached]['bonus'];
        $amount = $bonus['type'] === 'fixed_minor' ? $bonus['value'] : intdiv(max(0, $actualValue) * $bonus['value'], 10_000);

        return ['achievement_bp' => $achievement, 'tier' => $reached + 1, 'bonus_minor' => $amount];
    }
}
