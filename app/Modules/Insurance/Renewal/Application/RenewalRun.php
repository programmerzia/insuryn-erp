<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Renewal\Application;

use Carbon\CarbonImmutable;

/**
 * The nightly renewal run for the current tenant (Phase 3 design §4, slice R9): build the expiry register, offer the renewal quotations due (T-45), send the
 * notices and reminders due. Each step is idempotent, so a rerun on the same day changes nothing.
 */
final class RenewalRun
{
    public function __construct(
        private readonly ExpiryRegister $register,
        private readonly RenewalQuotations $quotations,
        private readonly RenewalNotices $notices,
    ) {}

    /** @return array{open: int, quotations: int, notices: int} */
    public function run(CarbonImmutable $today): array
    {
        $open = $this->register->build($today);
        $quotations = $this->quotations->offerDue($today);
        $notices = $this->notices->sendDue($today);

        return ['open' => $open, 'quotations' => $quotations, 'notices' => $notices];
    }
}
