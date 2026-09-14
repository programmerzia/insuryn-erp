<?php

declare(strict_types=1);

namespace App\Modules\Finance\FixedAssets\Application;

use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Finance\Bank\Application\BankAccountQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Accounting Event Mapper for fixed assets (design addendum v2 §B.7), called inside the source transaction. Every event points the asset roles at the
 * asset class's own accounts (PD-4 account overrides) and carries the dimensions branch and asset.
 */
final class FixedAssetAccountingEvents
{
    public function __construct(
        private readonly SubmitAccountingEvent $submit,
        private readonly BankAccountQuery $bankAccounts,
    ) {}

    /** FA_ACQUIRED: DR class cost / CR the bank account paid from, or accounts payable. */
    public function acquired(\stdClass $asset): void
    {
        $class = $this->assetClass((string) $asset->class_id);
        $cost = (int) $asset->cost_minor;
        $byBank = $asset->paid_via === 'bank';
        $overrides = ['fixed_asset_cost' => (string) $class->cost_account_id];
        if ($byBank) {
            $overrides['bank_main'] = $this->bankAccounts->glAccountFor((string) $asset->bank_account_id, (string) $asset->entity_id, (string) $asset->currency);
        }
        $on = CarbonImmutable::parse((string) $asset->acquired_on);
        ($this->submit)(
            entityId: (string) $asset->entity_id, eventType: 'FA_ACQUIRED', sourceType: 'fixed_asset', sourceId: (string) $asset->id,
            idempotencyKey: 'FA_ACQUIRED:'.$asset->id, transactionDate: $on, effectiveDate: $on, currency: (string) $asset->currency,
            payload: ['cost' => $cost, 'paid_by_bank' => $byBank ? $cost : 0, 'on_credit' => $byBank ? 0 : $cost, 'fixed_asset_id' => (string) $asset->id,
                'asset_number' => (string) $asset->number, 'reference' => $asset->invoice_ref, 'bank_account_id' => $asset->bank_account_id, 'account_overrides' => $overrides],
            dimensions: ['branch' => (string) $asset->branch_id, 'asset' => (string) $asset->id],
        );
    }

    /** DEPRECIATION_POSTED: DR class depreciation expense / CR class accumulated depreciation, dated the period end. */
    public function depreciated(\stdClass $asset, string $periodId, CarbonImmutable $periodEnds, int $amountMinor, string $rowId): void
    {
        $class = $this->assetClass((string) $asset->class_id);
        ($this->submit)(
            entityId: (string) $asset->entity_id, eventType: 'DEPRECIATION_POSTED', sourceType: 'asset_depreciation', sourceId: $rowId,
            idempotencyKey: "DEPRECIATION_POSTED:{$asset->id}:{$periodId}", transactionDate: $periodEnds, effectiveDate: $periodEnds, currency: (string) $asset->currency,
            payload: ['amount' => $amountMinor, 'fixed_asset_id' => (string) $asset->id, 'period_id' => $periodId, 'asset_number' => (string) $asset->number,
                'account_overrides' => ['depreciation_expense' => (string) $class->expense_account_id, 'accumulated_depreciation' => (string) $class->accumulated_account_id]],
            dimensions: ['branch' => (string) $asset->branch_id, 'asset' => (string) $asset->id],
        );
    }

    /** FA_TRANSFERRED: cost and accumulated depreciation move between branches (net zero per account). */
    public function transferred(\stdClass $asset, string $movementId, string $fromBranch, string $toBranch, int $accumulatedMinor, CarbonImmutable $movedOn): void
    {
        $class = $this->assetClass((string) $asset->class_id);
        ($this->submit)(
            entityId: (string) $asset->entity_id, eventType: 'FA_TRANSFERRED', sourceType: 'asset_movement', sourceId: $movementId,
            idempotencyKey: 'FA_TRANSFERRED:'.$movementId, transactionDate: $movedOn, effectiveDate: $movedOn, currency: (string) $asset->currency,
            payload: ['cost' => (int) $asset->cost_minor, 'accumulated' => $accumulatedMinor, 'from_branch' => $fromBranch, 'to_branch' => $toBranch,
                'asset_movement_id' => $movementId, 'asset_number' => (string) $asset->number,
                'account_overrides' => ['fixed_asset_cost' => (string) $class->cost_account_id, 'accumulated_depreciation' => (string) $class->accumulated_account_id]],
            dimensions: ['branch' => $toBranch, 'asset' => (string) $asset->id],
        );
    }

    /** FA_DISPOSED: DR accumulated, DR bank (proceeds), DR loss or CR gain, CR cost. */
    public function disposed(\stdClass $asset, \stdClass $disposal): void
    {
        $class = $this->assetClass((string) $asset->class_id);
        $overrides = ['fixed_asset_cost' => (string) $class->cost_account_id, 'accumulated_depreciation' => (string) $class->accumulated_account_id];
        if ($class->disposal_account_id !== null) {
            $overrides['asset_disposal_gain_loss'] = (string) $class->disposal_account_id;
        }
        if ((int) $disposal->proceeds_minor > 0) {
            $overrides['bank_main'] = $this->bankAccounts->glAccountFor((string) $disposal->bank_account_id, (string) $asset->entity_id, (string) $asset->currency);
        }
        $on = CarbonImmutable::parse((string) $disposal->disposal_date);
        ($this->submit)(
            entityId: (string) $asset->entity_id, eventType: 'FA_DISPOSED', sourceType: 'asset_disposal', sourceId: (string) $disposal->id,
            idempotencyKey: 'FA_DISPOSED:'.$disposal->id, transactionDate: $on, effectiveDate: $on, currency: (string) $asset->currency,
            payload: ['cost' => (int) $disposal->cost_minor, 'accumulated' => (int) $disposal->accumulated_minor, 'proceeds' => (int) $disposal->proceeds_minor,
                'loss' => -(int) $disposal->gain_loss_minor, 'asset_disposal_id' => (string) $disposal->id, 'asset_number' => (string) $asset->number,
                'reference' => (string) $disposal->number, 'bank_account_id' => $disposal->bank_account_id, 'account_overrides' => $overrides],
            dimensions: ['branch' => (string) $asset->branch_id, 'asset' => (string) $asset->id],
        );
    }

    private function assetClass(string $classId): \stdClass
    {
        return DB::table('asset_classes')->where('id', $classId)->first() ?? throw new \LogicException('Asset class missing.');
    }
}
