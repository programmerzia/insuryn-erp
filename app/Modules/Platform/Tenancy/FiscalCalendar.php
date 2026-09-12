<?php

declare(strict_types=1);

namespace App\Modules\Platform\Tenancy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Fiscal year of a business date for the current tenant (design §0: tenants.fiscal_year_start_month). */
final class FiscalCalendar
{
    /** Fiscal years are named by the calendar year they start in: with a July start, 2027-03-01 is in 2026. */
    public function fiscalYear(CarbonImmutable $businessDate): int
    {
        $startMonth = (int) (DB::table('tenants')->where('id', TenantContext::id())->value('fiscal_year_start_month') ?? 1);

        return $businessDate->month >= $startMonth ? $businessDate->year : $businessDate->year - 1;
    }
}
