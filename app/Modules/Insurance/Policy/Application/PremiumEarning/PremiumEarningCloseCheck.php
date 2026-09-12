<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Policy\Application\PremiumEarning;

use App\Modules\Accounting\Application\Contracts\CloseCheckResult;
use App\Modules\Accounting\Application\Contracts\CloseTaskCheck;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;

/** Design §5.7 task 1: runs the premium earning batch for the period (a rerun is a no-op) and blocks if any policy still lacks its earning row. */
final class PremiumEarningCloseCheck implements CloseTaskCheck
{
    public function __construct(private readonly PremiumEarningRun $earning) {}

    public function taskCode(): string
    {
        return 'premium_earning';
    }

    public function check(FiscalPeriodView $period, string $actorUserId): CloseCheckResult
    {
        $result = $this->earning->run($period->id);
        $missing = $this->earning->missingEarning($period);
        $details = ['policies_earned' => $result->policiesEarned, 'earned_minor' => $result->earnedMinor, 'missing_policies' => $missing];

        return $missing === []
            ? CloseCheckResult::passed('Every policy on cover has its earning row.', $details)
            : CloseCheckResult::blocked(count($missing).' policy(ies) have no earning row for the period.', $details);
    }
}
