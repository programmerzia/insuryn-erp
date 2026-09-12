<?php

declare(strict_types=1);

use App\Modules\Insurance\Policy\Domain\EarningLayer;
use App\Modules\Insurance\Policy\Domain\EarningSchedule;
use App\Modules\Insurance\Product\Domain\Enums\EarningMethod;
use Carbon\CarbonImmutable;

/**
 * Design §9.1 property test "random policy terms/amounts: Σearned == net premium", and §4.3 worked example.
 * Cases come from a fixed seed, so a failure is reproducible from its dataset name.
 */
dataset('random policies', function (): iterable {
    mt_srand(20_260_913);
    for ($case = 1; $case <= 300; $case++) {
        $method = mt_rand(0, 1) === 0 ? EarningMethod::Daily365 : EarningMethod::Monthly;
        $inception = CarbonImmutable::parse('2024-01-01')->addDays(mt_rand(0, 1_000));
        $termMonths = mt_rand(1, 24);
        $expiry = $inception->addMonthsNoOverflow($termMonths)->subDay();
        $net = mt_rand(1, 9_999_999_999);
        $layers = [new EarningLayer($net, $inception, $expiry)];
        if (mt_rand(0, 2) === 0) { // an endorsement part-way through, increase or decrease
            $effective = $inception->addDays(mt_rand(0, (int) $inception->diffInDays($expiry)));
            $delta = mt_rand(-intdiv($net, 2), $net);
            $layers[] = new EarningLayer($delta, $effective, $expiry);
        }
        yield "case {$case} {$method->value} {$inception->toDateString()} {$termMonths}m" => [$method, $layers];
    }
});

it('earns exactly the net premium over the term, in any method, with endorsements', function (EarningMethod $method, array $layers): void {
    /** @var list<EarningLayer> $layers */
    $net = array_sum(array_map(fn (EarningLayer $l): int => $l->netMinor, $layers));
    $expiry = $layers[0]->end;

    $byMonth = EarningSchedule::byCalendarMonth($method, $layers);

    expect(array_sum($byMonth))->toBe($net)
        ->and(EarningSchedule::earnedBefore($method, $layers, $expiry->addDay()))->toBe($net)
        ->and(EarningSchedule::earnedBefore($method, $layers, $layers[0]->start))->toBe(0);
})->with('random policies');

it('never earns more than the premium and keeps earning monotonic for a single layer', function (EarningMethod $method, array $layers): void {
    /** @var list<EarningLayer> $layers */
    $layer = $layers[0];
    $previous = 0;
    for ($cutoff = $layer->start; $cutoff->lessThanOrEqualTo($layer->end->addDay()); $cutoff = $cutoff->addDays(17)) {
        $earned = EarningSchedule::earnedBefore($method, [$layer], $cutoff);
        expect($earned)->toBeGreaterThanOrEqual($previous)->toBeLessThanOrEqual($layer->netMinor);
        $previous = $earned;
    }
})->with('random policies');

it('matches design §4.3: 30 September days of a 365-day cover under daily_365', function (): void {
    $layer = new EarningLayer(10_434_800, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2027-08-31'));

    expect(EarningSchedule::byCalendarMonth(EarningMethod::Daily365, [$layer])['2026-09'])->toBe(857_655); // 104,348 × 30/365 = 8,576.55
});

it('credits monthly earning when each earning month ends, the last month absorbing the residual', function (): void {
    $midMonth = new EarningLayer(1_000, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-12-14'));

    expect(EarningSchedule::byCalendarMonth(EarningMethod::Monthly, [$midMonth]))->toBe(['2026-10' => 333, '2026-11' => 333, '2026-12' => 334]);
});
