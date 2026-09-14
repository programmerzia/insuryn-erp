<?php

declare(strict_types=1);

namespace App\Modules\Finance\FixedAssets\Application;

use App\Modules\Accounting\Application\Queries\FiscalPeriodQuery;
use App\Modules\Accounting\Application\Queries\FiscalPeriodView;
use App\Modules\Finance\FixedAssets\Domain\DepreciationCalculator;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design addendum v2 §B.7 monthly depreciation batch: preview the month (nothing written), then post — one asset_depreciation row and one
 * DEPRECIATION_POSTED event per asset. INVARIANT: one row per asset and period, so posting a month again inserts and posts nothing; only open months post.
 */
final class DepreciationRun
{
    public const PERMISSION = 'fa.post_depreciation';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly FiscalPeriodQuery $periods,
        private readonly FixedAssetAccountingEvents $accounting,
        private readonly Audit $audit,
    ) {}

    /**
     * The month's depreciation not yet posted, per asset.
     *
     * @return list<array{asset_id: string, number: string, description: string, class_code: string, branch_code: string, amount_minor: int, accumulated_minor: int, nbv_minor: int}>
     */
    public function preview(FiscalPeriodView $period): array
    {
        $ends = $period->ends;
        $assets = DB::table('fixed_assets as f')->join('asset_classes as c', 'c.id', '=', 'f.class_id')->join('branches as b', 'b.id', '=', 'f.branch_id')
            ->where('f.entity_id', $period->entityId)->where('f.acquired_on', '<=', $ends->toDateString())
            ->where(fn ($q) => $q->whereNull('f.disposed_on')->orWhere('f.disposed_on', '>', $ends->toDateString()))
            ->where(fn ($q) => $q->whereNull('f.opening_as_of')->orWhere('f.opening_as_of', '<', $period->starts->toDateString()))
            ->whereNotExists(fn ($q) => $q->from('asset_depreciation as d')->whereColumn('d.asset_id', 'f.id')->where('d.period_id', $period->id))
            ->orderBy('f.number')
            ->get(['f.id', 'f.number', 'f.description', 'c.code as class_code', 'b.code as branch_code', 'f.method', 'f.cost_minor', 'f.residual_minor', 'f.useful_life_months', 'f.rate_bp',
                'f.acquired_on', 'f.opening_accumulated_minor']);
        $before = DB::table('asset_depreciation')->whereIn('asset_id', $assets->pluck('id')->all())->where('period_ends', '<', $period->starts->toDateString())
            ->groupBy('asset_id')->selectRaw('asset_id, sum(amount_minor) as total')->get()
            ->mapWithKeys(fn (object $r): array => [(string) $r->asset_id => (int) $r->total])->all();

        $rows = [];
        foreach ($assets as $a) {
            $accumulatedBefore = (int) $a->opening_accumulated_minor + (int) ($before[(string) $a->id] ?? 0);
            $amount = DepreciationCalculator::monthly((string) $a->method, (int) $a->cost_minor, (int) $a->residual_minor, $a->useful_life_months === null ? null : (int) $a->useful_life_months,
                $a->rate_bp === null ? null : (int) $a->rate_bp, $accumulatedBefore, CarbonImmutable::parse((string) $a->acquired_on), $ends);
            if ($amount <= 0) {
                continue;
            }
            $rows[] = ['asset_id' => (string) $a->id, 'number' => (string) $a->number, 'description' => (string) $a->description, 'class_code' => (string) $a->class_code,
                'branch_code' => (string) $a->branch_code, 'amount_minor' => $amount, 'accumulated_minor' => $accumulatedBefore + $amount, 'nbv_minor' => (int) $a->cost_minor - $accumulatedBefore - $amount];
        }

        return $rows;
    }

    /**
     * Posts the month's depreciation. Returns the number of assets depreciated (0 when the month was already posted).
     *
     * @throws BusinessRuleViolation ASSET_PERIOD_NOT_OPEN
     */
    public function post(string $periodId, string $actorUserId): int
    {
        $period = $this->periods->find($periodId) ?? abort(404);
        $this->permissions->authorize($actorUserId, self::PERMISSION, AuthorizationScope::entity($period->entityId));

        return $this->postPeriod($period, $actorUserId);
    }

    /** The close task's path (design §B.2.8 task 9): authorized by the close task's own permission. */
    public function postPeriod(FiscalPeriodView $period, string $actorUserId): int
    {
        if (! $period->isOpen()) {
            throw new BusinessRuleViolation('ASSET_PERIOD_NOT_OPEN', 'Depreciation posts only into an open month. Reopen the month or choose an open one.');
        }

        return DB::transaction(function () use ($period, $actorUserId): int {
            DB::table('fiscal_periods')->where('id', $period->id)->lockForUpdate()->value('id'); // one batch per period at a time
            $rows = $this->preview($period);
            if ($rows === []) {
                return 0;
            }
            $runId = (string) Str::uuid7();
            DB::table('asset_depreciation_runs')->insert(['id' => $runId, 'tenant_id' => TenantContext::id(), 'entity_id' => $period->entityId, 'period_id' => $period->id,
                'period_ends' => $period->ends->toDateString(), 'assets_count' => count($rows), 'total_minor' => array_sum(array_column($rows, 'amount_minor')),
                'posted_by' => $actorUserId, 'posted_at' => now()]);
            foreach ($rows as $row) {
                $asset = DB::table('fixed_assets')->where('id', $row['asset_id'])->first() ?? throw new \LogicException('Asset missing.');
                $rowId = (string) Str::uuid7();
                DB::table('asset_depreciation')->insert(['id' => $rowId, 'tenant_id' => TenantContext::id(), 'asset_id' => $row['asset_id'], 'run_id' => $runId, 'period_id' => $period->id,
                    'period_ends' => $period->ends->toDateString(), 'branch_id' => $asset->branch_id, 'amount_minor' => $row['amount_minor'], 'accumulated_minor' => $row['accumulated_minor'],
                    'nbv_minor' => $row['nbv_minor']]);
                if ($row['nbv_minor'] <= (int) $asset->residual_minor) {
                    DB::table('fixed_assets')->where('id', $row['asset_id'])->update(['status' => 'fully_depreciated', 'updated_at' => now()]);
                }
                $this->accounting->depreciated($asset, $period->id, $period->ends, $row['amount_minor'], $rowId);
            }
            $this->audit->record('depreciation.posted', AuditSubject::of('asset_depreciation_run', $runId), null,
                ['period_id' => $period->id, 'assets' => count($rows), 'total_minor' => array_sum(array_column($rows, 'amount_minor'))], null, self::PERMISSION, Actor::user($actorUserId));

            return count($rows);
        });
    }

    /** Assets on the books in the month that have no depreciation row for it although one is due (the close task's blocking condition). */
    public function missing(FiscalPeriodView $period): int
    {
        return count($this->preview($period));
    }
}
