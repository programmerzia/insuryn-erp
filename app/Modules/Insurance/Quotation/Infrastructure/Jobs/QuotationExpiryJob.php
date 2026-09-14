<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Quotation\Infrastructure\Jobs;

use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Platform\Jobs\RunsNightly;
use App\Modules\Platform\Tenancy\BusinessClock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Slice R4: issued quotations past their validity expire, nightly per tenant (D-07 loop). Gap fix GA-05: recorded; finance can run it now. */
final class QuotationExpiryJob implements ShouldQueue
{
    use Queueable, RunsNightly;

    public const KEY = 'quotation_expiry';

    public function __construct()
    {
        $this->onQueue('batch');
    }

    public function handle(QuotationService $quotations): void
    {
        // Slice 2.1b: the company's today, read inside the tenant (D-54).
        $this->eachTenant(fn (): int => $this->logged(self::KEY, fn (): int => $quotations->expireDue(app(BusinessClock::class)->today())));
    }
}
