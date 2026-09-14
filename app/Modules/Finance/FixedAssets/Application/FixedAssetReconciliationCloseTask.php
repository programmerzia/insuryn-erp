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

/** Design addendum v2 §B.2.8 task 9 `fixed_asset_reconciliation`: the register's cost and accumulated depreciation equal the GL at the month end (D-116). */
final class FixedAssetReconciliationCloseTask implements CloseTaskCheck, CloseTaskContributor
{
    public function __construct(private readonly FixedAssetReconciliation $reconciliation) {}

    public function taskCode(): string
    {
        return 'fixed_asset_reconciliation';
    }

    /** @return list<CloseTaskDefinition> */
    public function definitions(): array
    {
        return [$this->definition()];
    }

    public function definition(): CloseTaskDefinition
    {
        return new CloseTaskDefinition(12, 'fixed_asset_reconciliation', CloseTaskKind::Check, ['depreciation'], 'accounting', 'periods.soft_lock');
    }

    public function appliesTo(string $entityId): bool
    {
        return DB::table('fixed_assets')->where('entity_id', $entityId)->exists();
    }

    public function check(FiscalPeriodView $period, string $actorUserId): CloseCheckResult
    {
        $lines = $this->reconciliation->lines($period->entityId, $period->bookId, $period->ends);
        $differences = array_values(array_filter($lines, fn (array $l): bool => $l['variance_minor'] !== 0));
        $details = ['accounts' => count($lines), 'differences' => array_map(fn (array $l): array => ['account' => $l['code'], 'variance_minor' => $l['variance_minor']], $differences)];

        return $differences === []
            ? CloseCheckResult::passed('The fixed asset register reconciles to the ledger.', $details)
            : CloseCheckResult::blocked(count($differences).' asset account(s) differ from the fixed asset register.', $details);
    }
}
