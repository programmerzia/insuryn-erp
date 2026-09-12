<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Application;

use App\Modules\Accounting\Application\Contracts\CloseCheckResult;
use App\Modules\Accounting\Application\Contracts\CloseTaskCheck;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;

/**
 * Design §5.7 task 2: blocks while open suspense (as it stands now) received on or before the period end is older than
 * erp.close.suspense_max_age_days at the period end; a person may waive it by skipping the task with a reason.
 */
final class SuspenseReviewCloseCheck implements CloseTaskCheck
{
    public function __construct(private readonly SuspenseQuery $suspense) {}

    public function taskCode(): string
    {
        return 'suspense_review';
    }

    public function check(FiscalPeriodView $period, string $actorUserId): CloseCheckResult
    {
        $maxAge = (int) config('erp.close.suspense_max_age_days', 30);
        $old = array_values(array_filter($this->suspense->ageing($period->entityId, $period->ends)['items'], fn (array $item): bool => $item['days'] > $maxAge));
        $details = ['max_age_days' => $maxAge, 'items_over_threshold' => count($old), 'amount_over_threshold_minor' => array_sum(array_column($old, 'open_minor')),
            'receipt_numbers' => array_column($old, 'receipt_number')];

        return $old === []
            ? CloseCheckResult::passed("No open suspense older than {$maxAge} days.", $details)
            : CloseCheckResult::blocked(count($old)." suspense item(s) older than {$maxAge} days.", $details);
    }
}
