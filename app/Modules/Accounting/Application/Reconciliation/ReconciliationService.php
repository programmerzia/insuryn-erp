<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Reconciliation;

use App\Modules\Accounting\Application\Contracts\SubledgerReconciler;
use App\Modules\Accounting\Application\LedgerQuery;
use App\Modules\Accounting\Domain\Models\FiscalPeriod;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design §6.3. For each subledger: subledger balance (its reconciler) vs GL balance of its control accounts (subledger_controls roles,
 * mapped on the date, normal side), variance = subledger − GL, persisted as a reconciliation run. A variance records one exception per
 * object whose subledger and GL amounts differ (GL lines missing the subledger's dimension are listed per journal) and blocks the period
 * lock (FiscalPeriodService). A clean rerun resolves the period's earlier variance runs for that subledger.
 */
final class ReconciliationService
{
    /** @param iterable<SubledgerReconciler> $reconcilers */
    public function __construct(
        private readonly LedgerQuery $ledger,
        private readonly iterable $reconcilers,
    ) {}

    /**
     * Reconciles every registered subledger for the period, as of its end unless $asOf is given.
     *
     * @return list<string> ids of the runs recorded (subledgers without control accounts are skipped)
     */
    public function runAll(string $periodId, ?CarbonImmutable $asOf = null): array
    {
        $runIds = [];
        foreach ($this->reconcilers as $reconciler) {
            $runId = $this->run($reconciler, $periodId, $asOf);
            if ($runId !== null) {
                $runIds[] = $runId;
            }
        }

        return $runIds;
    }

    /**
     * Every registered subledger's variance (subledger − GL) as the ledger stands now, as of the period end, without recording a run. Used by
     * the period lock, which must not trust runs recorded before later postings.
     *
     * @return array<string, int> subledger → non-zero variance
     */
    public function currentVariances(string $periodId): array
    {
        $period = FiscalPeriod::query()->findOrFail($periodId);
        $asOf = CarbonImmutable::parse($period->ends);
        $variances = [];
        foreach ($this->reconcilers as $reconciler) {
            $measured = $this->measure($reconciler, $period, $asOf);
            if ($measured !== null && $measured['variance'] !== 0) {
                $variances[$reconciler->subledger()] = $measured['variance'];
            }
        }

        return $variances;
    }

    /** @return string|null the run id, or null when the entity maps no control account to the subledger */
    public function run(SubledgerReconciler $reconciler, string $periodId, ?CarbonImmutable $asOf = null): ?string
    {
        $period = FiscalPeriod::query()->findOrFail($periodId);
        $asOf ??= CarbonImmutable::parse($period->ends);
        $measured = $this->measure($reconciler, $period, $asOf);
        if ($measured === null) {
            return null;
        }
        ['subledger' => $subledger, 'gl' => $gl, 'variance' => $variance] = $measured;

        return DB::transaction(function () use ($reconciler, $period, $asOf, $subledger, $gl, $variance): string {
            $runId = (string) Str::uuid7();
            DB::table('reconciliation_runs')->insert(['id' => $runId, 'tenant_id' => TenantContext::id(), 'entity_id' => $period->entity_id,
                'subledger' => $reconciler->subledger(), 'period_id' => $period->id, 'run_at' => CarbonImmutable::now(), 'subledger_balance_minor' => $subledger,
                'gl_balance_minor' => $gl['total'], 'variance_minor' => $variance, 'status' => $variance === 0 ? 'clean' : 'variance']);
            if ($variance !== 0) {
                $this->recordExceptions($runId, $reconciler, $period->entity_id, $asOf, $gl);
            } else {
                DB::table('reconciliation_runs')->where('entity_id', $period->entity_id)->where('period_id', $period->id)
                    ->where('subledger', $reconciler->subledger())->where('status', 'variance')
                    ->update(['status' => 'resolved', 'resolution_note' => "Resolved by clean rerun {$runId} as of {$asOf->toDateString()}."]);
            }

            return $runId;
        });
    }

    /** @return array{subledger: int, gl: array{total: int, by_dimension: array<string, int>, unattributed_by_journal: array<string, int>}, variance: int}|null null without control accounts */
    private function measure(SubledgerReconciler $reconciler, FiscalPeriod $period, CarbonImmutable $asOf): ?array
    {
        $accountIds = $this->controlAccounts($period, $reconciler->subledger(), $asOf);
        if ($accountIds === []) {
            return null;
        }
        $subledger = $reconciler->balanceAt($period->entity_id, $asOf)->getMinorAmount()->toInt();
        $gl = $this->ledger->normalBalanceByDimension($accountIds, $period->book_id, $asOf, $reconciler->itemDimension());

        return ['subledger' => $subledger, 'gl' => $gl, 'variance' => $subledger - $gl['total']];
    }

    /** @param array{total: int, by_dimension: array<string, int>, unattributed_by_journal: array<string, int>} $gl */
    private function recordExceptions(string $runId, SubledgerReconciler $reconciler, string $entityId, CarbonImmutable $asOf, array $gl): void
    {
        $expected = [];
        foreach ($reconciler->itemsAt($entityId, $asOf) as $item) {
            $expected[$item['object_id']] = ($expected[$item['object_id']] ?? 0) + $item['amount_minor'];
        }
        $rows = [];
        foreach (array_keys($expected + $gl['by_dimension']) as $objectId) {
            $actual = $gl['by_dimension'][$objectId] ?? 0;
            if (($expected[$objectId] ?? 0) !== $actual) {
                $rows[] = $this->exception($runId, $reconciler->itemDimension(), (string) $objectId, $expected[$objectId] ?? 0, $actual);
            }
        }
        foreach ($gl['unattributed_by_journal'] as $journalId => $actual) {
            $rows[] = $this->exception($runId, 'journal', $journalId, 0, $actual);
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('reconciliation_exceptions')->insert($chunk);
        }
    }

    /** @return array<string, mixed> */
    private function exception(string $runId, string $objectType, string $objectId, int $expected, int $actual): array
    {
        return ['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'run_id' => $runId, 'object_type' => $objectType,
            'object_id' => $objectId, 'expected_minor' => $expected, 'actual_minor' => $actual, 'status' => 'open'];
    }

    /** @return list<string> */
    private function controlAccounts(FiscalPeriod $period, string $subledger, CarbonImmutable $asOf): array
    {
        $roles = DB::table('subledger_controls')->where('entity_id', $period->entity_id)->where('book_id', $period->book_id)
            ->where('subledger', $subledger)->pluck('control_account_role')->all();

        return array_values(DB::table('account_role_mappings')->where('entity_id', $period->entity_id)->where('book_id', $period->book_id)
            ->whereIn('role_code', $roles)->where('effective_from', '<=', $asOf->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $asOf->toDateString()))
            ->pluck('account_id')->map(fn ($id): string => (string) $id)->unique()->all());
    }
}
