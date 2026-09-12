<?php

declare(strict_types=1);

use App\Modules\Accounting\Http\Presenters\MinorUnits;

it('formats minor units with grouping and the currency scale, without floats', function (int $minor, string $currency, string $expected): void {
    expect(MinorUnits::format($minor, $currency))->toBe($expected);
})->with([
    'zero' => [0, 'BDT', '0.00'],
    'less than one unit' => [5, 'BDT', '0.05'],
    'thousands' => [123_456, 'BDT', '1,234.56'],
    'millions' => [400_000_000, 'BDT', '4,000,000.00'],
    'negative' => [-50_000, 'BDT', '-500.00'],
    'zero-decimal currency' => [1_234_567, 'JPY', '1,234,567'],
    'beyond float precision' => [PHP_INT_MAX, 'BDT', '92,233,720,368,547,758.07'],
]);
