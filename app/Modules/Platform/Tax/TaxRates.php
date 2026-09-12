<?php

declare(strict_types=1);

namespace App\Modules\Platform\Tax;

use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Design §2.1 tax_rates: effective-dated rate by jurisdiction and tax type (spec §7 tax engine, MVP-sized). */
final class TaxRates
{
    /**
     * Rate in basis points (1500 = 15%). A missing rate is an error, never an assumed zero (OPEN #2: tax rules are configuration).
     *
     * @throws BusinessRuleViolation TAX_RATE_MISSING
     */
    public function rateOn(string $jurisdiction, string $taxType, CarbonImmutable $date): int
    {
        return $this->rate($jurisdiction, $taxType, $date, false);
    }

    /**
     * Withholding rate in basis points (e.g. tax withheld from agent commission, spec §4 "withholding tax via tax engine").
     *
     * @throws BusinessRuleViolation TAX_RATE_MISSING
     */
    public function withholdingRateOn(string $jurisdiction, string $taxType, CarbonImmutable $date): int
    {
        return $this->rate($jurisdiction, $taxType, $date, true);
    }

    private function rate(string $jurisdiction, string $taxType, CarbonImmutable $date, bool $withholding): int
    {
        $day = $date->toDateString();
        $rate = DB::table('tax_rates')->where('jurisdiction', $jurisdiction)->where('tax_type', $taxType)->where('withholding', $withholding)
            ->where('effective_from', '<=', $day)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $day))
            ->orderByDesc('effective_from')->value('rate_bp');
        if ($rate === null) {
            throw new BusinessRuleViolation('TAX_RATE_MISSING', 'No '.($withholding ? 'withholding ' : '')."{$taxType} rate for {$jurisdiction} on {$day}.");
        }

        return (int) $rate;
    }
}
