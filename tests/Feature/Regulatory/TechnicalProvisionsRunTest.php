<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\Close\PeriodCloseService;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Regulatory\Application\Provisions\TechnicalProvisionService;
use App\Modules\Insurance\Regulatory\Application\RegulatoryPeriod;
use App\Modules\Platform\Authorization\SodViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Market gap G5: the quarterly technical provisions run. Q3 2026 uses the paid chain ladder (legacy paid history plus a claim paid in the quarter), the finance
 * manager prepares and reviews, the CFO approves and IBNR_PROVISION posts; Q4 uses the percentage method and releases Q3's provision first. The September close
 * lists the "Technical provisions" task and passes it.
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-12-31 10:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx);
    $this->finance = userWithPermissions($this->ctx['tenant_id'], ['provisions.run', 'provisions.approve', 'periods.soft_lock', 'reports.regulatory']);
    $this->cfo = userWithPermissions($this->ctx['tenant_id'], ['provisions.approve']);
    $this->officer = userWithPermissions($this->ctx['tenant_id'], ['claim.register', 'claim.reserve']);
    $this->manager = userWithPermissions($this->ctx['tenant_id'], ['claim.approve', 'claim.pay_request']);
    $this->payer = userWithPermissions($this->ctx['tenant_id'], ['claim.pay_release']);
    $this->lines = fn (string $eventType): array => array_values(DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.description', $eventType)
        ->orderBy('j.id')->orderBy('l.line_no')->get(['l.role_code', 'l.side', 'l.amount_minor', 'l.dim_lob'])->map(fn (object $l): array => [(string) $l->role_code, (string) $l->side, (int) $l->amount_minor, $l->dim_lob])->all());

    asTenant($this->ctx['tenant_id'], function (): void {
        $d = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'], null,
            $d('2026-07-01'), 12_000_000_00, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, $d('2026-07-01'), $this->world['admin']);
        $claim = app(ClaimService::class)->register($policy->id, $d('2026-08-17'), 'Rear collision', $this->officer, $d('2026-08-18'));
        app(ClaimService::class)->reserve($claim->id, 200_000_00, 'Surveyor estimate', $this->officer, $d('2026-08-19'));
        $payment = app(ClaimPaymentService::class)->approve($claim->id, 180_000_00, $this->world['policyholder_id'], $this->manager, $d('2026-08-25'));
        app(ClaimPaymentService::class)->requestRelease($payment->id, $this->manager, null);
        app(ClaimPaymentService::class)->release($payment->id, $this->payer, $d('2026-08-27'));
        // Legacy paid claims, accident quarters 2024-Q4 to 2026-Q2: 100,000 in the accident quarter, 50,000 the next, 25,000 the one after.
        for ($aq = 2024 * 4 + 3; $aq <= 2026 * 4 + 1; $aq++) {
            foreach ([0 => 100_000_00, 1 => 50_000_00, 2 => 25_000_00] as $dev => $amount) {
                $paid = $aq + $dev;
                if ($paid > 2026 * 4 + 2) {
                    continue;
                }
                DB::table('claims_paid_history')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'class' => 'motor',
                    'accident_quarter_start' => sprintf('%d-%02d-01', intdiv($aq, 4), $aq % 4 * 3 + 1), 'paid_quarter_start' => sprintf('%d-%02d-01', intdiv($paid, 4), $paid % 4 * 3 + 1), 'paid_minor' => $amount]);
            }
        }
    });
});

it('posts the quarter\'s IBNR by chain ladder, then releases it when the next quarter posts by percentage', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $service = app(TechnicalProvisionService::class);
        $q3 = $service->prepare($this->ctx['entity_id'], RegulatoryPeriod::fromKey('2026-Q3'), [], $this->finance);
        $run = DB::table('technical_provision_runs')->where('id', $q3)->sole();
        $motor = json_decode((string) $run->results, true)['classes'][0];
        // f0 = 1.5, f1 = 1.75 / 1.5; 2026-Q2 unpaid 25,000; 2026-Q3 (180,000 paid) ultimate 315,000 → unpaid 135,000; less the open case reserve 20,000.
        expect($run->number)->toBe('TPR-2026-000001')
            ->and($motor)->toMatchArray(['class' => 'motor', 'method' => 'chain_ladder', 'chain_ladder_available' => true, 'case_reserves_minor' => 20_000_00, 'ibnr_minor' => 140_000_00])
            ->and((int) $run->total_ibnr_minor)->toBe(140_000_00);

        $service->review($q3, $this->finance);
        // SoD: whoever prepared the run does not approve it, even holding provisions.approve.
        expect(fn () => $service->approve($q3, $this->finance))->toThrow(SodViolation::class);
        $service->approve($q3, $this->cfo);
        expect(($this->lines)('IBNR_PROVISION'))->toBe([['claims_ibnr_expense', 'debit', 140_000_00, 'motor'], ['ibnr_provision', 'credit', 140_000_00, 'motor']])
            ->and(DB::table('journals')->where('description', 'IBNR_PROVISION')->value('posting_date'))->toBe('2026-09-30');

        // The September close lists the quarterly task, and it passes with the run posted.
        $runId = app(PeriodCloseService::class)->start((string) DB::table('fiscal_periods')->where('starts', '2026-09-01')->value('id'), $this->finance);
        $task = DB::table('period_close_tasks')->where('close_run_id', $runId)->where('code', 'technical_provisions')->sole();
        expect(json_decode((string) DB::table('period_close_tasks')->where('close_run_id', $runId)->where('code', 'trial_balance')->value('depends_on'), true))->toContain('technical_provisions');
        app(PeriodCloseService::class)->execute((string) $task->id, $this->finance);
        expect(DB::table('period_close_tasks')->where('id', $task->id)->value('status'))->toBe('done')
            ->and(DB::table('period_close_tasks')->where('code', 'technical_provisions')->whereIn('close_run_id', DB::table('period_close_runs')->whereIn('period_id',
                DB::table('fiscal_periods')->where('starts', '2026-08-01')->select('id'))->select('id'))->exists())->toBeFalse();

        // Q4 by percentage: 5% of net written premium over the four quarters to December; Q3's 140,000 released first.
        $q4 = $service->prepare($this->ctx['entity_id'], RegulatoryPeriod::fromKey('2026-Q4'), ['motor' => 'percentage'], $this->finance);
        $net = (int) DB::table('policy_transactions')->sum('net_delta_minor');
        $expected = intdiv($net * 500, 10_000);
        expect((int) DB::table('technical_provision_runs')->where('id', $q4)->value('total_ibnr_minor'))->toBe($expected);
        $service->review($q4, $this->finance);
        $service->approve($q4, $this->cfo);

        expect(($this->lines)('IBNR_PROVISION_REVERSED'))->toBe([['ibnr_provision', 'debit', 140_000_00, 'motor'], ['claims_ibnr_expense', 'credit', 140_000_00, 'motor']])
            ->and(DB::table('technical_provision_runs')->where('id', $q3)->value('reversed_by_run_id'))->toBe($q4)
            ->and((int) DB::table('journal_lines')->where('account_id', $this->ctx['accounts']['ibnr_provision'])
                ->selectRaw("coalesce(sum(case when side = 'credit' then amount_minor else -amount_minor end), 0) as b")->value('b'))->toBe($expected);
    });

    $this->withoutVite();
    actingAs(asTenant($this->ctx['tenant_id'], fn (): User => User::query()->whereKey($this->cfo)->firstOrFail()))->get('/regulatory/provisions?quarter=2026-Q3', ['X-Tenant' => $this->ctx['tenant_id']])
        ->assertInertia(fn (AssertableInertia $page) => $page->component('regulatory/Provisions')->where('run.status', 'posted')->where('run.number', 'TPR-2026-000001')
            ->where('classes.0.method', 'chain_ladder')->where('classes.0.ibnr', '140,000.00')->where('triangles.0.factors.0', '1.5000')
            ->where('journal.0', ['event' => 'IBNR_PROVISION', 'class' => 'Motor', 'debit' => 'Claims incurred – IBNR', 'credit' => 'IBNR provision', 'amount' => '140,000.00']));
});
