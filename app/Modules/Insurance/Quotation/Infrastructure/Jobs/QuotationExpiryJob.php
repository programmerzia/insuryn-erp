<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Quotation\Infrastructure\Jobs;

use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Platform\Tenancy\BusinessClock;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Slice R4: issued quotations past their validity expire, nightly per tenant (D-07 loop). */
final class QuotationExpiryJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('batch');
    }

    public function handle(QuotationService $quotations): void
    {
        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            // Slice 2.1b: the company's today, read inside the tenant (D-54).
            TenantContext::run((string) $tenantId, fn (): int => $quotations->expireDue(app(BusinessClock::class)->today()));
        }
    }
}
