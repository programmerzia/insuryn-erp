<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Reports\FinancialStatementsQuery;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\PremiumEarning\PremiumEarningRun;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Reports\Application\ClaimsPaidRegisterQuery;
use App\Modules\Insurance\Reports\Application\LossRatioQuery;
use App\Modules\Insurance\Reports\Application\OutstandingClaimsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Spec §4 Claims reports: outstanding claims, loss ratio by dimension (product, branch, agent), claims paid register — read-only, drilling to journals.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $officer = userWithPermissions($this->ctx['tenant_id'], ['claim.register', 'claim.reserve']);
    $manager = userWithPermissions($this->ctx['tenant_id'], ['claim.approve', 'claim.pay_request', 'claim.close']);
    $finance = userWithPermissions($this->ctx['tenant_id'], ['claim.pay_release']);
    $d = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);

    [$this->agentPolicy, $this->directPolicy, $this->settledClaim, $this->openClaim, $this->paymentId] = asTenant($this->ctx['tenant_id'], function () use ($officer, $manager, $finance, $d): array {
        $lifecycle = app(PolicyLifecycle::class);
        $issue = function (?string $agentId) use ($lifecycle, $d): string {
            $policy = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
                $agentId, $d('2026-07-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
            $lifecycle->issue($policy->id, $d('2026-07-01'), $this->world['admin']);

            return $policy->id;
        };
        $agentPolicy = $issue($this->world['agent_id']);
        $directPolicy = $issue(null);
        foreach (DB::table('fiscal_periods')->where('ends', '<=', '2026-10-31')->orderBy('starts')->pluck('id') as $periodId) {
            app(PremiumEarningRun::class)->run((string) $periodId);
        }
        $claims = app(ClaimService::class);
        $payments = app(ClaimPaymentService::class);
        $settled = $claims->register($agentPolicy, $d('2026-09-01'), 'Collision', $officer, $d('2026-09-02'));
        $claims->reserve($settled->id, 3_000_000, 'Initial', $officer, $d('2026-09-02'));
        $payment = $payments->approve($settled->id, 2_000_000, $this->world['policyholder_id'], $manager, $d('2026-10-01'));
        $payments->requestRelease($payment->id, $manager, null);
        $payments->release($payment->id, $finance, $d('2026-10-04'));
        $claims->recover($settled->id, 'salvage', 200_000, null, 'SALV', $manager, $d('2026-10-10'));
        $claims->close($settled->id, 'Settled', $manager, $d('2026-10-15'));
        $open = $claims->register($directPolicy, $d('2026-10-02'), 'Theft', $officer, $d('2026-10-03'));
        $claims->reserve($open->id, 500_000, 'Initial', $officer, $d('2026-10-03'));

        return [$agentPolicy, $directPolicy, $settled->id, $open->id, $payment->id];
    });
});

it('lists outstanding claims as of a date with reserve and approved-unpaid amounts, drilling to journals', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $query = app(OutstandingClaimsQuery::class);
        $september = $query->outstanding($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-30'));
        $early = $query->outstanding($this->ctx['entity_id'], CarbonImmutable::parse('2026-10-02'));
        $october = $query->outstanding($this->ctx['entity_id'], CarbonImmutable::parse('2026-10-31'));

        expect(array_map(fn (array $r): array => [$r['claim_id'], $r['outstanding_reserve_minor'], $r['approved_unpaid_minor']], $september['rows']))->toBe([[$this->settledClaim, 3_000_000, 0]])
            ->and(array_map(fn (array $r): array => [$r['claim_id'], $r['outstanding_reserve_minor'], $r['approved_unpaid_minor']], $early['rows']))->toBe([[$this->settledClaim, 1_000_000, 2_000_000]])
            ->and(array_map(fn (array $r): string => $r['claim_id'], $october['rows']))->toBe([$this->openClaim])
            ->and($october['totals'])->toBe(['outstanding_reserve_minor' => 500_000, 'approved_unpaid_minor' => 0, 'total_minor' => 500_000])
            ->and($september['rows'][0]['claim_number'])->toStartWith('CLM-')
            ->and($september['rows'][0]['policy_id'])->toBe($this->agentPolicy)
            ->and($september['rows'][0]['journals'][0]['journal_id'])->toBe((string) DB::table('journals')->where('description', 'CLAIM_RESERVED')->orderBy('posting_date')->value('id'));
    });
});

it('computes the loss ratio by product, branch and agent from the GL, drilling to filtered account activity', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $from = CarbonImmutable::parse('2026-09-01');
        $to = CarbonImmutable::parse('2026-10-31');
        $earned = fn (string $policyId): int => (int) DB::table('premium_earning_ledger as l')->join('fiscal_periods as p', 'p.id', '=', 'l.period_id')
            ->where('l.policy_id', $policyId)->whereBetween('p.ends', ['2026-09-30', '2026-10-31'])->sum('l.earned_minor');
        $byAgent = app(LossRatioQuery::class)->lossRatio($this->ctx['entity_id'], $from, $to, 'agent');
        $byProduct = app(LossRatioQuery::class)->lossRatio($this->ctx['entity_id'], $from, $to, 'product');
        $rowFor = function (?string $value) use ($byAgent): array {
            foreach ($byAgent['rows'] as $row) {
                if ($row['dimension_value'] === $value) {
                    return $row;
                }
            }
            throw new PHPUnit\Framework\AssertionFailedError('No loss ratio row for '.var_export($value, true));
        };
        $agentRow = $rowFor((string) $this->world['agent_id']);
        $directRow = $rowFor(null);

        // incurred = claims expense (reserves − releases) − recoveries: agent policy 3,000,000 − 1,000,000 − 200,000; direct 500,000
        expect([$agentRow['earned_premium_minor'], $agentRow['incurred_claims_minor']])->toBe([$earned($this->agentPolicy), 1_800_000])
            ->and([$directRow['earned_premium_minor'], $directRow['incurred_claims_minor']])->toBe([$earned($this->directPolicy), 500_000])
            ->and($agentRow['loss_ratio_bp'])->toBe(App\Modules\Insurance\Policy\Domain\PremiumMath::divideHalfEven(1_800_000 * 10_000, $earned($this->agentPolicy)))
            ->and(count($byProduct['rows']))->toBe(1)
            ->and($byProduct['rows'][0]['dimension_value'])->toBe($this->world['product_id'])
            ->and($byProduct['rows'][0]['incurred_claims_minor'])->toBe(2_300_000)
            ->and($byProduct['totals']['incurred_claims_minor'])->toBe(2_300_000)
            ->and(count(app(LossRatioQuery::class)->lossRatio($this->ctx['entity_id'], $from, $to, 'branch')['rows']))->toBe(1)
            ->and(fn () => app(LossRatioQuery::class)->lossRatio($this->ctx['entity_id'], $from, $to, 'employee'))->toThrow(InvalidArgumentException::class);

        $expenseUrl = $agentRow['drill']['claims_expense'];
        expect($expenseUrl)->toContain('dimension=agent')->toContain('value='.$this->world['agent_id']);
        $activity = app(FinancialStatementsQuery::class)->accountActivity($this->ctx['entity_id'], $this->ctx['accounts']['claims_expense'], $from, $to, 'agent', $this->world['agent_id']);
        expect($activity['closing_minor'] - $activity['opening_minor'])->toBe(2_000_000);
    });
});

it('registers claims paid in a date range with their journals', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $register = app(ClaimsPaidRegisterQuery::class)->register($this->ctx['entity_id'], CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

        expect(array_map(fn (array $r): array => [$r['claim_payment_id'], $r['paid_on'], $r['amount_minor'], $r['product_code']], $register['rows']))
            ->toBe([[$this->paymentId, '2026-10-04', 2_000_000, 'MOTOR']])
            ->and($register['totals']['amount_minor'])->toBe(2_000_000)
            ->and(array_column($register['rows'][0]['journals'], 'journal_id'))->toBe(DB::table('journals')->where('source_type', 'claim_payment')->where('source_id', $this->paymentId)
                ->orderBy('posting_date')->orderBy('id')->pluck('id')->map(fn ($id): string => (string) $id)->all())
            ->and(app(ClaimsPaidRegisterQuery::class)->register($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'))['rows'])->toBe([]);
    });
});

it('serves the claims reports over the API to reports.financial only', function (): void {
    $headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
    $reader = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['reports.financial'])));
    $clerk = asTenant($this->ctx['tenant_id'], fn () => App\Models\User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['claim.register'])));
    $e = $this->ctx['entity_id'];

    foreach ([
        "/api/reports/outstanding-claims?entity_id={$e}&as_of=2026-10-31",
        "/api/reports/loss-ratio?entity_id={$e}&from=2026-09-01&to=2026-10-31&by=agent",
        "/api/reports/claims-paid?entity_id={$e}&from=2026-10-01&to=2026-10-31",
        "/api/reports/accounts/{$this->ctx['accounts']['claims_expense']}/activity?entity_id={$e}&from=2026-09-01&to=2026-10-31&dimension=agent&value={$this->world['agent_id']}",
    ] as $url) {
        Pest\Laravel\actingAs($clerk)->getJson($url, $headers)->assertForbidden();
        Pest\Laravel\actingAs($reader)->getJson($url, $headers)->assertOk()->assertJsonStructure(['data']);
    }
    Pest\Laravel\actingAs($reader)->getJson("/api/reports/loss-ratio?entity_id={$e}&from=2026-09-01&to=2026-10-31&by=employee", $headers)->assertUnprocessable();
});
