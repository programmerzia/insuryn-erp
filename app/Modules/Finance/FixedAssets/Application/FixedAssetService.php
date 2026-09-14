<?php

declare(strict_types=1);

namespace App\Modules\Finance\FixedAssets\Application;

use App\Modules\Accounting\Application\Queries\AccountLineQuery;
use App\Modules\Accounting\Application\Queries\FiscalPeriodQuery;
use App\Modules\Finance\FixedAssets\Domain\DepreciationCalculator;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design addendum v2 §B.7 register: asset classes, manual capitalisation (FA_ACQUIRED), assets brought in with the opening balances (no event, PD-16),
 * transfers between branches (FA_TRANSFERRED) and disposals by sale or write-off (FA_DISPOSED). Everything needs `fa.manage` (ASSUMPTION A-276).
 */
final class FixedAssetService
{
    public const PERMISSION = 'fa.manage';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly AccountLineQuery $accounts,
        private readonly FiscalPeriodQuery $periods,
        private readonly DocumentNumberer $numberer,
        private readonly FixedAssetAccountingEvents $accounting,
        private readonly FixedAssetRegisterQuery $register,
        private readonly Audit $audit,
    ) {}

    /**
     * @param array{code: string, name: string, method: string, useful_life_months: int|null, rate_bp: int|null, residual_bp: int, capitalisation_threshold_minor: int,
     *     cost_account_id: string, accumulated_account_id: string, expense_account_id: string, disposal_account_id: string|null} $data
     *
     * @throws BusinessRuleViolation ASSET_CLASS_INVALID
     */
    public function saveClass(string $entityId, array $data, string $actorUserId, ?string $classId = null): string
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::entity($entityId));
        if (! in_array($data['method'], ['straight_line', 'reducing_balance'], true)
            || ($data['method'] === 'straight_line' && (int) $data['useful_life_months'] <= 0) || ($data['method'] === 'reducing_balance' && ((int) $data['rate_bp'] <= 0 || (int) $data['rate_bp'] > 10_000))) {
            throw new BusinessRuleViolation('ASSET_CLASS_INVALID', 'Give a straight-line class its useful life in months, or a reducing-balance class its yearly rate.');
        }
        foreach (['cost_account_id' => 'asset', 'accumulated_account_id' => 'asset', 'expense_account_id' => 'expense', 'disposal_account_id' => null] as $field => $type) {
            $accountId = $data[$field];
            if ($accountId === null) {
                continue;
            }
            $account = $this->accounts->account($accountId);
            if ($account === null || $account->entityId !== $entityId || ! $account->acceptsPostings() || ($type !== null && $account->type !== $type)) {
                throw new BusinessRuleViolation('ASSET_CLASS_INVALID', 'Choose active postable accounts: asset accounts for cost and accumulated depreciation, an expense account for depreciation.');
            }
        }
        $row = ['code' => $data['code'], 'name' => $data['name'], 'method' => $data['method'],
            'useful_life_months' => $data['method'] === 'straight_line' ? $data['useful_life_months'] : null, 'rate_bp' => $data['method'] === 'reducing_balance' ? $data['rate_bp'] : null,
            'residual_bp' => $data['residual_bp'], 'capitalisation_threshold_minor' => $data['capitalisation_threshold_minor'], 'cost_account_id' => $data['cost_account_id'],
            'accumulated_account_id' => $data['accumulated_account_id'], 'expense_account_id' => $data['expense_account_id'], 'disposal_account_id' => $data['disposal_account_id'], 'updated_at' => now()];

        return DB::transaction(function () use ($entityId, $row, $actorUserId, $classId): string {
            if ($classId === null) {
                $classId = (string) Str::uuid7();
                DB::table('asset_classes')->insert($row + ['id' => $classId, 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId, 'status' => 'active', 'created_at' => now()]);
            } else {
                // §B.7: a class change applies to assets capitalised afterwards; existing assets keep the method and life they were registered with.
                DB::table('asset_classes')->where('id', $classId)->where('entity_id', $entityId)->update($row);
            }
            $this->audit->record('asset_class.saved', AuditSubject::of('asset_class', $classId), null, $row, null, self::PERMISSION, Actor::user($actorUserId));

            return $classId;
        });
    }

    /**
     * ASSUMPTION A-271: the default classes (config erp.fixed_assets.default_classes) on the accounts mapped to the asset roles; classes whose code exists are left alone.
     *
     * @throws BusinessRuleViolation ASSET_CLASS_INVALID when the asset roles are not mapped
     */
    public function addDefaultClasses(string $entityId, string $actorUserId): int
    {
        $mapped = fn (string $role): ?string => ($id = DB::table('account_role_mappings')->where('entity_id', $entityId)->where('role_code', $role)->whereNull('effective_to')->value('account_id')) === null ? null : (string) $id;
        [$cost, $accumulated, $expense, $disposal] = [$mapped('fixed_asset_cost'), $mapped('accumulated_depreciation'), $mapped('depreciation_expense'), $mapped('asset_disposal_gain_loss')];
        if ($cost === null || $accumulated === null || $expense === null) {
            throw new BusinessRuleViolation('ASSET_CLASS_INVALID', 'Map the fixed asset account roles in Accounting → Account roles first.');
        }
        $added = 0;
        foreach ((array) config('erp.fixed_assets.default_classes', []) as $code => [$name, $method, $lifeOrRate, $residualPercent, $thresholdMajor]) {
            if (DB::table('asset_classes')->where('entity_id', $entityId)->where('code', $code)->exists()) {
                continue;
            }
            $this->saveClass($entityId, ['code' => (string) $code, 'name' => (string) $name, 'method' => (string) $method,
                'useful_life_months' => $method === 'straight_line' ? (int) $lifeOrRate : null, 'rate_bp' => $method === 'reducing_balance' ? (int) $lifeOrRate * 100 : null,
                'residual_bp' => (int) $residualPercent * 100, 'capitalisation_threshold_minor' => (int) $thresholdMajor * 100, 'cost_account_id' => $cost,
                'accumulated_account_id' => $accumulated, 'expense_account_id' => $expense, 'disposal_account_id' => $disposal], $actorUserId);
            $added++;
        }

        return $added;
    }

    /**
     * Manual capitalisation: numbers the asset (FA-{fy}-n) and posts FA_ACQUIRED.
     *
     * @param array{class_id: string, branch_id: string, description: string, serial_no?: string|null, location?: string|null, custodian?: string|null, supplier?: string|null,
     *     invoice_ref?: string|null, acquired_on: string, cost_minor: int, paid_via: string, bank_account_id?: string|null} $data
     *
     * @throws BusinessRuleViolation ASSET_BELOW_THRESHOLD | ASSET_PERIOD_NOT_OPEN | ASSET_BANK_ACCOUNT_REQUIRED | INVALID_AMOUNT
     */
    public function acquire(string $entityId, array $data, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::branch($entityId, $data['branch_id']));
        $class = $this->activeClass($entityId, $data['class_id']);
        $acquiredOn = CarbonImmutable::parse($data['acquired_on']);
        if ($data['cost_minor'] <= 0) {
            throw new BusinessRuleViolation('INVALID_AMOUNT', 'Enter the asset\'s cost as a positive amount.');
        }
        if ($data['cost_minor'] < (int) $class->capitalisation_threshold_minor) {
            throw new BusinessRuleViolation('ASSET_BELOW_THRESHOLD', 'This cost is below the class\'s capitalisation threshold, so it is an expense, not an asset. Post it as an expense instead.');
        }
        if (! in_array($data['paid_via'], ['bank', 'payable'], true) || ($data['paid_via'] === 'bank' && ($data['bank_account_id'] ?? null) === null)) {
            throw new BusinessRuleViolation('ASSET_BANK_ACCOUNT_REQUIRED', 'Choose the bank account the asset was paid from, or record it as bought on credit.');
        }
        $this->assertOpenOn($entityId, $acquiredOn);
        $number = $this->numberer->reserve(new DocumentNumberScope($entityId, null, 'fixed_asset', 'FA', $acquiredOn), $actorUserId);

        return DB::transaction(function () use ($entityId, $data, $class, $acquiredOn, $number, $actorUserId): string {
            $id = $this->insertAsset($entityId, $data, $class, $number->number, 'manual', $data['paid_via'], 0, null, $actorUserId);
            $this->numberer->markUsed($number->id, 'fixed_asset', $id);
            $asset = DB::table('fixed_assets')->where('id', $id)->first() ?? throw new \LogicException('Asset missing.');
            $this->accounting->acquired($asset);
            $this->audit->record('fixed_asset.acquired', AuditSubject::of('fixed_asset', $id), null,
                ['number' => $number->number, 'cost_minor' => $data['cost_minor'], 'acquired_on' => $acquiredOn->toDateString(), 'paid_via' => $data['paid_via']], null, self::PERMISSION, Actor::user($actorUserId));

            return $id;
        });
    }

    /**
     * PD-16: an asset brought in with the opening balances — its cost and accumulated depreciation at the cut-over are in the opening journal, so no event.
     *
     * @param array{class_id: string, branch_id: string, description: string, serial_no?: string|null, location?: string|null, custodian?: string|null, supplier?: string|null,
     *     invoice_ref?: string|null, acquired_on: string, cost_minor: int} $data
     */
    public function registerOpening(string $entityId, array $data, int $openingAccumulatedMinor, CarbonImmutable $openingAsOf, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::branch($entityId, $data['branch_id']));
        $class = $this->activeClass($entityId, $data['class_id']);
        $number = $this->numberer->reserve(new DocumentNumberScope($entityId, null, 'fixed_asset', 'FA', $openingAsOf), $actorUserId);

        return DB::transaction(function () use ($entityId, $data, $class, $number, $openingAccumulatedMinor, $openingAsOf, $actorUserId): string {
            $id = $this->insertAsset($entityId, $data, $class, $number->number, 'opening', 'opening', $openingAccumulatedMinor, $openingAsOf, $actorUserId);
            $this->numberer->markUsed($number->id, 'fixed_asset', $id);
            $this->audit->record('fixed_asset.opening_registered', AuditSubject::of('fixed_asset', $id), null,
                ['number' => $number->number, 'cost_minor' => $data['cost_minor'], 'opening_accumulated_minor' => $openingAccumulatedMinor, 'opening_as_of' => $openingAsOf->toDateString()],
                null, self::PERMISSION, Actor::user($actorUserId));

            return $id;
        });
    }

    /** @throws BusinessRuleViolation ASSET_NOT_IN_SERVICE | ASSET_SAME_BRANCH | ASSET_PERIOD_NOT_OPEN */
    public function transfer(string $assetId, string $toBranchId, CarbonImmutable $movedOn, ?string $toLocation, string $reason, string $actorUserId): string
    {
        $asset = $this->asset($assetId);
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::branch((string) $asset->entity_id, (string) $asset->branch_id));
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::branch((string) $asset->entity_id, $toBranchId));
        if ($asset->branch_id === $toBranchId) {
            throw new BusinessRuleViolation('ASSET_SAME_BRANCH', 'The asset is already at that branch. Choose another branch.');
        }
        $this->assertOpenOn((string) $asset->entity_id, $movedOn);

        return DB::transaction(function () use ($assetId, $toBranchId, $movedOn, $toLocation, $reason, $actorUserId): string {
            $asset = DB::table('fixed_assets')->where('id', $assetId)->lockForUpdate()->first() ?? throw new \LogicException('Asset missing.');
            if ($asset->status === 'disposed') {
                throw new BusinessRuleViolation('ASSET_NOT_IN_SERVICE', 'This asset has been disposed of, so it cannot be moved.');
            }
            $accumulated = $this->register->accumulated($asset, $movedOn);
            $id = (string) Str::uuid7();
            DB::table('asset_movements')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'asset_id' => $assetId, 'moved_on' => $movedOn->toDateString(),
                'from_branch_id' => $asset->branch_id, 'to_branch_id' => $toBranchId, 'from_location' => $asset->location, 'to_location' => $toLocation, 'reason' => $reason,
                'cost_minor' => $asset->cost_minor, 'accumulated_minor' => $accumulated, 'moved_by' => $actorUserId]);
            DB::table('fixed_assets')->where('id', $assetId)->update(['branch_id' => $toBranchId, 'location' => $toLocation ?? $asset->location, 'updated_at' => now()]);
            $this->accounting->transferred($asset, $id, (string) $asset->branch_id, $toBranchId, $accumulated, $movedOn);
            $this->audit->record('fixed_asset.transferred', AuditSubject::of('fixed_asset', $assetId), ['branch_id' => $asset->branch_id], ['branch_id' => $toBranchId, 'moved_on' => $movedOn->toDateString()],
                $reason, self::PERMISSION, Actor::user($actorUserId));

            return $id;
        });
    }

    /**
     * Sale or write-off: gain or loss = proceeds − net book value on the disposal date; the disposal month is not depreciated.
     *
     * @throws BusinessRuleViolation ASSET_NOT_IN_SERVICE | ASSET_DEPRECIATED_AFTER_DISPOSAL | ASSET_BANK_ACCOUNT_REQUIRED | ASSET_PERIOD_NOT_OPEN | INVALID_AMOUNT
     */
    public function dispose(string $assetId, string $kind, CarbonImmutable $disposalDate, int $proceedsMinor, ?string $bankAccountId, string $reason, string $actorUserId): string
    {
        $asset = $this->asset($assetId);
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::branch((string) $asset->entity_id, (string) $asset->branch_id));
        if (! in_array($kind, ['sale', 'write_off'], true) || $proceedsMinor < 0 || ($kind === 'write_off' && $proceedsMinor !== 0)) {
            throw new BusinessRuleViolation('INVALID_AMOUNT', 'A sale has proceeds of zero or more; a write-off has none.');
        }
        if ($proceedsMinor > 0 && $bankAccountId === null) {
            throw new BusinessRuleViolation('ASSET_BANK_ACCOUNT_REQUIRED', 'Choose the bank account the sale proceeds went into.');
        }
        $this->assertOpenOn((string) $asset->entity_id, $disposalDate);
        $number = $this->numberer->reserve(new DocumentNumberScope((string) $asset->entity_id, null, 'asset_disposal', 'ADS', $disposalDate), $actorUserId);

        return DB::transaction(function () use ($assetId, $kind, $disposalDate, $proceedsMinor, $bankAccountId, $reason, $actorUserId, $number): string {
            $asset = DB::table('fixed_assets')->where('id', $assetId)->lockForUpdate()->first() ?? throw new \LogicException('Asset missing.');
            if ($asset->status === 'disposed') {
                throw new BusinessRuleViolation('ASSET_NOT_IN_SERVICE', 'This asset has already been disposed of.');
            }
            if (DB::table('asset_depreciation')->where('asset_id', $assetId)->where('period_ends', '>=', $disposalDate->startOfMonth()->toDateString())->exists()) {
                throw new BusinessRuleViolation('ASSET_DEPRECIATED_AFTER_DISPOSAL', 'Depreciation is already posted for the month of this disposal or later. Date the disposal after the last depreciated month.');
            }
            $cost = (int) $asset->cost_minor;
            $accumulated = $this->register->accumulated($asset, $disposalDate);
            $nbv = $cost - $accumulated;
            $id = (string) Str::uuid7();
            DB::table('asset_disposals')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'asset_id' => $assetId, 'number' => $number->number,
                'disposal_date' => $disposalDate->toDateString(), 'kind' => $kind, 'proceeds_minor' => $proceedsMinor, 'bank_account_id' => $proceedsMinor > 0 ? $bankAccountId : null,
                'cost_minor' => $cost, 'accumulated_minor' => $accumulated, 'nbv_minor' => $nbv, 'gain_loss_minor' => $proceedsMinor - $nbv, 'reason' => $reason, 'disposed_by' => $actorUserId]);
            $this->numberer->markUsed($number->id, 'asset_disposal', $id);
            DB::table('fixed_assets')->where('id', $assetId)->update(['status' => 'disposed', 'disposed_on' => $disposalDate->toDateString(), 'updated_at' => now()]);
            $this->accounting->disposed($asset, DB::table('asset_disposals')->where('id', $id)->first() ?? throw new \LogicException('Disposal missing.'));
            $this->audit->record('fixed_asset.disposed', AuditSubject::of('fixed_asset', $assetId), ['status' => $asset->status],
                ['status' => 'disposed', 'kind' => $kind, 'proceeds_minor' => $proceedsMinor, 'nbv_minor' => $nbv, 'number' => $number->number], $reason, self::PERMISSION, Actor::user($actorUserId));

            return $id;
        });
    }

    /** @param array<string, mixed> $data */
    private function insertAsset(string $entityId, array $data, \stdClass $class, string $number, string $sourceType, string $paidVia, int $openingAccumulated, ?CarbonImmutable $openingAsOf, string $actorUserId): string
    {
        $id = (string) Str::uuid7();
        $cost = (int) $data['cost_minor'];
        $residual = DepreciationCalculator::residual($cost, (int) $class->residual_bp);
        DB::table('fixed_assets')->insert(['id' => $id, 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId, 'branch_id' => $data['branch_id'], 'class_id' => $class->id,
            'number' => $number, 'description' => $data['description'], 'serial_no' => $data['serial_no'] ?? null, 'location' => $data['location'] ?? null,
            'custodian' => $data['custodian'] ?? null, 'supplier' => $data['supplier'] ?? null, 'invoice_ref' => $data['invoice_ref'] ?? null, 'acquired_on' => $data['acquired_on'],
            'currency' => (string) DB::table('legal_entities')->where('id', $entityId)->value('base_currency'), 'cost_minor' => $cost, 'residual_minor' => $residual,
            'method' => $class->method, 'useful_life_months' => $class->useful_life_months, 'rate_bp' => $class->rate_bp, 'source_type' => $sourceType, 'paid_via' => $paidVia,
            'bank_account_id' => $paidVia === 'bank' ? ($data['bank_account_id'] ?? null) : null, 'opening_accumulated_minor' => $openingAccumulated,
            'opening_as_of' => $openingAsOf?->toDateString(), 'status' => $openingAccumulated >= $cost - $residual ? 'fully_depreciated' : 'in_service',
            'created_by' => $actorUserId, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function activeClass(string $entityId, string $classId): \stdClass
    {
        $class = DB::table('asset_classes')->where('id', $classId)->where('entity_id', $entityId)->where('status', 'active')->first();
        if ($class === null) {
            throw new BusinessRuleViolation('ASSET_CLASS_INVALID', 'Choose an active asset class.');
        }

        return $class;
    }

    private function asset(string $assetId): \stdClass
    {
        return DB::table('fixed_assets')->where('id', $assetId)->first() ?? abort(404);
    }

    private function assertOpenOn(string $entityId, CarbonImmutable $date): void
    {
        $period = $this->periods->containing($entityId, $date);
        if ($period === null || ! $period->isOpen()) {
            throw new BusinessRuleViolation('ASSET_PERIOD_NOT_OPEN', 'That date is in a month that is not open for posting. Choose a date in an open month.');
        }
    }
}
