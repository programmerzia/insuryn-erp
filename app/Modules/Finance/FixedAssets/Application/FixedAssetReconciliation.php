<?php

declare(strict_types=1);

namespace App\Modules\Finance\FixedAssets\Application;

use App\Modules\Accounting\Application\LedgerQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design addendum v2 §B.7 / PD-17: the fixed asset register reconciled to the GL. DECISION D-116: per GL account used by the asset classes — the cost
 * accounts against the register's cost, the accumulated depreciation accounts against its accumulated depreciation (credit balance) — because each class
 * posts to its own accounts and the kernel's reconciliation compares one mapped control account per subledger.
 */
final class FixedAssetReconciliation
{
    public function __construct(private readonly LedgerQuery $ledger, private readonly FixedAssetRegisterQuery $register) {}

    /**
     * @return list<array{account_id: string, code: string, name: string, kind: string, register_minor: int, gl_minor: int, variance_minor: int}>
     */
    public function lines(string $entityId, string $bookId, CarbonImmutable $asOf): array
    {
        $register = $this->register->register($entityId, $asOf);
        /** @var array<string, string> $kinds */
        $kinds = [];
        /** @var array<string, int> $expected */
        $expected = [];
        foreach (DB::table('asset_classes')->where('entity_id', $entityId)->get(['cost_account_id', 'accumulated_account_id']) as $class) {
            $kinds[(string) $class->cost_account_id] = 'cost';
            $kinds[(string) $class->accumulated_account_id] = 'accumulated';
        }
        foreach ($register as $row) {
            $expected[$row['cost_account_id']] = ($expected[$row['cost_account_id']] ?? 0) + $row['cost_minor'];
            $expected[$row['accumulated_account_id']] = ($expected[$row['accumulated_account_id']] ?? 0) + $row['accumulated_minor'];
        }
        $accounts = DB::table('accounts')->whereIn('id', array_keys($kinds))->get(['id', 'code', 'name'])->keyBy('id');
        $lines = [];
        foreach ($kinds as $accountId => $kind) {
            $amount = $expected[$accountId] ?? 0;
            $balance = $this->ledger->balance($accountId, $bookId, $asOf);
            $gl = $kind === 'cost' ? $balance : -$balance;
            $account = $accounts[$accountId] ?? null;
            $lines[] = ['account_id' => $accountId, 'code' => (string) ($account->code ?? ''), 'name' => (string) ($account->name ?? ''), 'kind' => $kind,
                'register_minor' => $amount, 'gl_minor' => $gl, 'variance_minor' => $amount - $gl];
        }
        usort($lines, fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $lines;
    }
}
