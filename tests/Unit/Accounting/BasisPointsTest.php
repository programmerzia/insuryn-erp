<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\BasisPoints;

it('applies basis points to minor units with half-even rounding', function (int $minorUnits, int $basisPoints, int $expected): void {
    expect(BasisPoints::of($minorUnits, $basisPoints))->toBe($expected);
})->with([
    'design §4.5 commission 10% of 50,000.00' => [5_000_000, 1_000, 500_000],
    'design §4.5 withholding 5% of 5,000.00' => [500_000, 500, 25_000],
    'rounds above half up' => [12_345, 1_500, 1_852],
    'half to even, down' => [25, 1_000, 2],
    'half to even, up' => [3, 5_000, 2],
    'half at zero stays zero' => [1, 5_000, 0],
    'negative half to even' => [-3, 5_000, -2],
    'negative below half' => [-12_345, 1_500, -1_852],
]);
