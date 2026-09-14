<?php

declare(strict_types=1);

namespace App\Modules\Finance\FixedAssets\Application;

use App\Modules\Accounting\Application\Close\CloseTaskDefinition;
use App\Modules\Accounting\Application\Close\CloseTaskKind;
use App\Modules\Accounting\Application\Contracts\CloseCheckResult;
use App\Modules\Accounting\Application\Contracts\CloseTaskCheck;
use App\Modules\Accounting\Application\Contracts\CloseTaskContributor;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;
use Illuminate\Support\Facades\DB;

/**
 * Design addendum v2 §B.2.8 task 9 `depreciation` (a check that runs the batch): posts the month's depreciation for every asset on the books and passes
 * when none is left without its row. Listed only for an entity with a fixed asset register (D-115).
 */
final class DepreciationCloseTask implements CloseTaskCheck, CloseTaskContributor
{
    public function __construct(private readonly DepreciationRun $run) {}

    public function taskCode(): string
    {
        return 'depreciation';
    }

    /** @return list<CloseTaskDefinition> */
    public function definitions(): array
    {
        return [$this->definition()];
    }

    public function definition(): CloseTaskDefinition
    {
        return new CloseTaskDefinition(12, 'depreciation', CloseTaskKind::Check, [], 'accounting', 'periods.soft_lock');
    }

    public function appliesTo(string $entityId): bool
    {
        return DB::table('fixed_assets')->where('entity_id', $entityId)->exists();
    }

    public function check(FiscalPeriodView $period, string $actorUserId): CloseCheckResult
    {
        $posted = $period->isOpen() ? $this->run->postPeriod($period, $actorUserId) : 0;
        $missing = $this->run->missing($period);
        $details = ['assets_depreciated' => $posted, 'assets_missing' => $missing];

        return $missing === 0
            ? CloseCheckResult::passed($posted === 0 ? 'Every asset on the books has its depreciation for the month.' : "Depreciation posted for {$posted} asset(s).", $details)
            : CloseCheckResult::blocked("{$missing} asset(s) have no depreciation for the month.", $details);
    }
}
