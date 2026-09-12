<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Close\PeriodCloseService;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Application\Reconciliation\ReconciliationService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design §6.1 claims subledger (Σ open reserves + Σ approved-unpaid) reconciles to claims_outstanding + claims_payable, as of a date;
 * §5.7 close task 5; §4.7 INVARIANT Σ claims_outstanding per claim = 0 after close.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->officer = userWithPermissions($this->ctx['tenant_id'], ['claim.register', 'claim.reserve']);
    $this->manager = userWithPermissions($this->ctx['tenant_id'], ['claim.approve', 'claim.pay_request', 'claim.close']);
    $this->finance = userWithPermissions($this->ctx['tenant_id'], ['claim.pay_release']);
    $this->policyId = asTenant($this->ctx['tenant_id'], function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-07-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-07-01'), $this->world['admin']);

        return $policy->id;
    });
    $this->d = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);
    $this->claimsRun = fn (string $monthEnd): ?object => DB::table('reconciliation_runs')->where('subledger', 'claims')
        ->where('period_id', DB::table('fiscal_periods')->where('ends', $monthEnd)->value('id'))->orderByDesc('run_at')->first();
    $this->outstandingByClaim = fn (): array => DB::table('journal_lines')->where('account_id', $this->ctx['accounts']['claims_outstanding'])->groupBy('dim_claim')
        ->selectRaw("dim_claim, sum(case when side = 'credit' then amount_minor else -amount_minor end) as b")->pluck('b', 'dim_claim')->map(fn ($b): int => (int) $b)->all();
});

it('reconciles claims clean as of each month end across open, approved-unpaid, paid and closed claims', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $claims = app(ClaimService::class);
        $payments = app(ClaimPaymentService::class);
        $open = $claims->register($this->policyId, ($this->d)('2026-09-01'), 'open', $this->officer, ($this->d)('2026-09-02'));
        $claims->reserve($open->id, 3_000_000, 'r', $this->officer, ($this->d)('2026-09-02'));
        $unpaid = $claims->register($this->policyId, ($this->d)('2026-09-03'), 'unpaid', $this->officer, ($this->d)('2026-09-04'));
        $claims->reserve($unpaid->id, 2_000_000, 'r', $this->officer, ($this->d)('2026-09-04'));
        $payments->approve($unpaid->id, 1_200_000, $this->world['policyholder_id'], $this->manager, ($this->d)('2026-09-20'));
        $settled = $claims->register($this->policyId, ($this->d)('2026-09-05'), 'settled', $this->officer, ($this->d)('2026-09-06'));
        $claims->reserve($settled->id, 1_000_000, 'r', $this->officer, ($this->d)('2026-09-06'));
        $payment = $payments->approve($settled->id, 900_000, $this->world['policyholder_id'], $this->manager, ($this->d)('2026-09-25'));
        $payments->requestRelease($payment->id, $this->manager, null);
        $payments->release($payment->id, $this->finance, ($this->d)('2026-10-03')); // paid after the September month end
        $claims->close($settled->id, 'done', $this->manager, ($this->d)('2026-10-05'));

        $service = app(ReconciliationService::class);
        foreach (['2026-09-30', '2026-10-31'] as $monthEnd) {
            $service->runAll((string) DB::table('fiscal_periods')->where('ends', $monthEnd)->value('id'));
        }
        $september = ($this->claimsRun)('2026-09-30');
        $october = ($this->claimsRun)('2026-10-31');

        // September: reserves 6,000,000 (all still in outstanding or payable; 900,000 approved but unpaid). October: 900,000 paid, 100,000 released.
        expect([(int) $september?->subledger_balance_minor, (int) $september?->variance_minor, $september?->status])->toBe([6_000_000, 0, 'clean'])
            ->and([(int) $october?->subledger_balance_minor, (int) $october?->variance_minor, $october?->status])->toBe([5_000_000, 0, 'clean']);
    });
});

it('leaves no reserve on any closed claim across randomised claim histories (INVARIANT)', function (int $seed): void {
    mt_srand($seed);

    asTenant($this->ctx['tenant_id'], function (): void {
        $claims = app(ClaimService::class);
        $payments = app(ClaimPaymentService::class);
        $closed = [];
        for ($n = 0; $n < 4; $n++) {
            $claim = $claims->register($this->policyId, ($this->d)('2026-08-01'), "claim {$n}", $this->officer, ($this->d)('2026-08-02'));
            $reserve = mt_rand(10, 500) * 10_000;
            $claims->reserve($claim->id, $reserve, 'initial', $this->officer, ($this->d)('2026-08-02'));
            for ($adjust = mt_rand(0, 3); $adjust > 0; $adjust--) {
                $next = max(10_000, $reserve + mt_rand(-20, 20) * 10_000);
                if ($next !== $reserve) {
                    $claims->reserve($claim->id, $next, 'adjust', $this->officer, ($this->d)('2026-08-03'));
                    $reserve = $next;
                }
            }
            $remaining = $reserve;
            for ($pay = mt_rand(1, 3); $pay > 0 && $remaining > 0; $pay--) {
                $amount = mt_rand(1, $remaining);
                $payment = $payments->approve($claim->id, $amount, $this->world['policyholder_id'], $this->manager, ($this->d)('2026-08-04'));
                $payments->requestRelease($payment->id, $this->manager, null);
                $payments->release($payment->id, $this->finance, ($this->d)('2026-08-05'));
                $remaining -= $amount;
            }
            $claims->close($claim->id, 'settled', $this->manager, ($this->d)('2026-08-06'));
            $closed[] = $claim->id;
        }

        $byClaim = ($this->outstandingByClaim)();
        foreach ($closed as $claimId) {
            expect($byClaim[$claimId] ?? 0)->toBe(0);
        }
        app(ReconciliationService::class)->runAll((string) DB::table('fiscal_periods')->where('ends', '2026-08-31')->value('id'));
        expect(($this->claimsRun)('2026-08-31')?->status)->toBe('clean')
            ->and((int) ($this->claimsRun)('2026-08-31')?->subledger_balance_minor)->toBe(0);
    });
})->with(array_map(fn (int $seed): array => [$seed], range(1, 12)));

it('blocks close task 5 on a claims variance and completes it once clean', function (): void {
    $maker = userWithPermissions($this->ctx['tenant_id'], ['accounting.create_manual_journal', 'accounting.post_to_control']);
    $checker = userWithPermissions($this->ctx['tenant_id'], ['accounting.approve_journal', 'accounting.post_to_control']);

    asTenant($this->ctx['tenant_id'], function () use ($maker, $checker): void {
        $claim = app(ClaimService::class)->register($this->policyId, ($this->d)('2026-09-01'), 'x', $this->officer, ($this->d)('2026-09-02'));
        app(ClaimService::class)->reserve($claim->id, 1_000_000, 'r', $this->officer, ($this->d)('2026-09-02'));
        $september = (string) DB::table('fiscal_periods')->where('ends', '2026-09-30')->value('id');
        $journals = app(ManualJournalService::class);
        $adjust = function (Side $payableSide, string $date) use ($journals, $maker, $checker, $claim): void {
            $journal = $journals->create(new ManualJournalRequest($this->ctx['entity_id'], CarbonImmutable::parse($date), 'Payable fix', JournalKind::Adjustment, 'test', 'BDT', [
                new ManualJournalLine($this->ctx['accounts']['claims_payable'], $payableSide, 50_000, ['branch' => $this->ctx['branch_id'], 'claim' => $claim->id]),
                new ManualJournalLine($this->ctx['accounts']['rounding_difference'], $payableSide->opposite(), 50_000, ['branch' => $this->ctx['branch_id']]),
            ]), $maker);
            $journals->submit($journal->id, $maker);
            $journals->approve($journal->id, $checker);
        };
        $adjust(Side::Credit, '2026-09-10');

        $close = app(PeriodCloseService::class);
        $runId = $close->start($september, $this->world['admin']);
        $task = (string) DB::table('period_close_tasks')->where('close_run_id', $runId)->where('code', 'claims_reconciliation')->value('id');
        $close->execute($task, $this->world['admin']);
        $exception = DB::table('reconciliation_exceptions')->where('object_type', 'claim')->first();

        expect((int) DB::table('period_close_tasks')->where('id', $task)->value('order_no'))->toBe(5)
            ->and(DB::table('period_close_tasks')->where('id', $task)->value('status'))->toBe('blocked')
            ->and(json_decode((string) DB::table('period_close_tasks')->where('id', $task)->value('result'), true)['details']['variance_minor'])->toBe(-50_000)
            ->and([$exception?->object_id, (int) $exception?->expected_minor, (int) $exception?->actual_minor])->toBe([$claim->id, 1_000_000, 1_050_000]);

        $adjust(Side::Debit, '2026-09-11');
        $close->execute($task, $this->world['admin']);
        expect(DB::table('period_close_tasks')->where('id', $task)->value('status'))->toBe('done');
    });
});
