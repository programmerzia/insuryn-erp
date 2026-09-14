<?php

declare(strict_types=1);

namespace App\Modules\Finance\FixedAssets\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Design addendum v2 §B.7 fixed asset register as at a date: cost, accumulated depreciation and net book value per asset, by class and branch. */
final class FixedAssetRegisterQuery
{
    /** Accumulated depreciation of an asset at the end of $asOf: opening accumulated (from its cut-over) plus depreciation rows of months ended by then. */
    public function accumulated(\stdClass $asset, CarbonImmutable $asOf): int
    {
        $opening = $asset->opening_as_of !== null && (string) $asset->opening_as_of <= $asOf->toDateString() ? (int) $asset->opening_accumulated_minor : 0;

        return $opening + (int) DB::table('asset_depreciation')->where('asset_id', $asset->id)->where('period_ends', '<=', $asOf->toDateString())->sum('amount_minor');
    }

    /**
     * Assets on the books at the end of $asOf (acquired by then, not disposed by then).
     *
     * @return list<array{id: string, number: string, description: string, class_id: string, class_code: string, class_name: string, branch_id: string, branch_code: string,
     *     location: string|null, custodian: string|null, acquired_on: string, method: string, status: string, cost_minor: int, accumulated_minor: int, nbv_minor: int, currency: string,
     *     cost_account_id: string, accumulated_account_id: string}>
     */
    public function register(string $entityId, CarbonImmutable $asOf, ?string $classId = null, ?string $branchId = null): array
    {
        $day = $asOf->toDateString();
        $rows = DB::table('fixed_assets as f')->join('asset_classes as c', 'c.id', '=', 'f.class_id')->join('branches as b', 'b.id', '=', 'f.branch_id')
            ->leftJoinSub(DB::table('asset_depreciation')->where('period_ends', '<=', $day)->groupBy('asset_id')->selectRaw('asset_id, sum(amount_minor) as depreciated'), 'd', 'd.asset_id', '=', 'f.id')
            ->where('f.entity_id', $entityId)->where('f.acquired_on', '<=', $day)
            ->where(fn ($q) => $q->whereNull('f.disposed_on')->orWhere('f.disposed_on', '>', $day))
            ->when($classId !== null, fn ($q) => $q->where('f.class_id', $classId))->when($branchId !== null, fn ($q) => $q->where('f.branch_id', $branchId))
            ->orderBy('c.code')->orderBy('f.number')
            ->get(['f.id', 'f.number', 'f.description', 'f.class_id', 'c.code as class_code', 'c.name as class_name', 'f.branch_id', 'b.code as branch_code', 'f.location', 'f.custodian',
                'f.acquired_on', 'f.method', 'f.status', 'f.cost_minor', 'f.currency', 'f.opening_accumulated_minor', 'f.opening_as_of', 'd.depreciated', 'c.cost_account_id', 'c.accumulated_account_id']);

        $register = [];
        foreach ($rows as $r) {
            $opening = $r->opening_as_of !== null && (string) $r->opening_as_of <= $day ? (int) $r->opening_accumulated_minor : 0;
            $accumulated = $opening + (int) ($r->depreciated ?? 0);
            $status = (string) $r->status === 'disposed' ? 'in_service' : (string) $r->status; // disposed after the date: still on the books then
            $register[] = ['id' => (string) $r->id, 'number' => (string) $r->number, 'description' => (string) $r->description, 'class_id' => (string) $r->class_id,
                'class_code' => (string) $r->class_code, 'class_name' => (string) $r->class_name, 'branch_id' => (string) $r->branch_id, 'branch_code' => (string) $r->branch_code,
                'location' => $r->location === null ? null : (string) $r->location, 'custodian' => $r->custodian === null ? null : (string) $r->custodian,
                'acquired_on' => (string) $r->acquired_on, 'method' => (string) $r->method, 'status' => $status, 'cost_minor' => (int) $r->cost_minor,
                'accumulated_minor' => $accumulated, 'nbv_minor' => (int) $r->cost_minor - $accumulated, 'currency' => (string) $r->currency,
                'cost_account_id' => (string) $r->cost_account_id, 'accumulated_account_id' => (string) $r->accumulated_account_id];
        }

        return $register;
    }

    /**
     * Subtotals of the register by a key (class_name or branch_code).
     *
     * @param list<array{cost_minor: int, accumulated_minor: int, nbv_minor: int, class_name: string, branch_code: string}> $register
     * @return list<array{group: string, assets: int, cost_minor: int, accumulated_minor: int, nbv_minor: int}>
     */
    public static function totalsBy(array $register, string $key): array
    {
        $groups = [];
        foreach ($register as $row) {
            $group = (string) $row[$key];
            $groups[$group] ??= ['group' => $group, 'assets' => 0, 'cost_minor' => 0, 'accumulated_minor' => 0, 'nbv_minor' => 0];
            $groups[$group]['assets']++;
            $groups[$group]['cost_minor'] += $row['cost_minor'];
            $groups[$group]['accumulated_minor'] += $row['accumulated_minor'];
            $groups[$group]['nbv_minor'] += $row['nbv_minor'];
        }
        ksort($groups);

        return array_values($groups);
    }
}
