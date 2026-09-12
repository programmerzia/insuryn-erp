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
        $day = $date->toDateString();
        $rate = DB::table('tax_rates')->where('jurisdiction', $jurisdiction)->where('tax_type', $taxType)->where('withholding', false)
            ->where('effective_from', '<=', $day)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $day))
            ->orderByDesc('effective_from')->value('rate_bp');
        if ($rate === null) {
            throw new BusinessRuleViolation('TAX_RATE_MISSING', "No {$taxType} rate for {$jurisdiction} on {$day}.");
        }

        return (int) $rate;
    }
}
