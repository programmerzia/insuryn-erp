<?php

declare(strict_types=1);

namespace Tests\Support\Property;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Tiny generator for property tests: PHP's own seeded engine (Xoshiro256**), isolated from mt_rand and random_bytes, so the same seed
 * gives the same values on every machine and nothing the application draws shifts the sequence. No shrinking.
 */
final class SeededGenerator
{
    private readonly Randomizer $random;

    public function __construct(public readonly int $seed)
    {
        $this->random = new Randomizer(new Xoshiro256StarStar($seed));
    }

    public function int(int $min, int $max): int
    {
        return $this->random->getInt($min, $max);
    }

    /** True with probability $percent / 100. */
    public function chance(int $percent): bool
    {
        return $this->random->getInt(1, 100) <= $percent;
    }

    /**
     * @template T
     *
     * @param list<T> $values
     * @return T
     */
    public function pick(array $values): mixed
    {
        if ($values === []) {
            throw new \LogicException('Nothing to pick from.');
        }

        return $values[$this->random->getInt(0, count($values) - 1)];
    }

    /**
     * A key drawn with probability proportional to its weight (weights ≥ 0, at least one positive).
     *
     * @param non-empty-array<string, int> $weights
     */
    public function weighted(array $weights): string
    {
        $roll = $this->random->getInt(1, max(1, array_sum($weights)));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0 && $weight > 0) {
                return $key;
            }
        }

        return (string) array_key_last(array_filter($weights, fn (int $w): bool => $w > 0));
    }
}
