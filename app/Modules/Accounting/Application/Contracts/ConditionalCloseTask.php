<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Contracts;

use App\Modules\Accounting\Application\Queries\FiscalPeriodView;

/**
 * Market gap G5 (DECISION D-111): a close task the catalogue lists only in some periods — the quarterly technical provisions in a quarter's last month, once
 * the company runs them. PeriodCloseService asks each tagged check implementing this whether its task belongs to the period's close run; a task no check claims
 * is never listed, so tenants and periods without it close as before.
 */
interface ConditionalCloseTask extends CloseTaskCheck
{
    public function appliesTo(FiscalPeriodView $period): bool;
}
