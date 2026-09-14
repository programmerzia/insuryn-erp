<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Reinsurance\Application\ReinsurancePayableReconciler;
use App\Modules\Insurance\Reinsurance\Application\ReinsurerStatements;
use App\Modules\Insurance\Reinsurance\Application\TreatyService;
use App\Modules\Insurance\Reinsurance\Domain\RiMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Reinsurance MVP (G4): a motor quota share treaty (40%, commission 25%) with the SBC compulsory share (50%) applied first. Issuing a policy cedes 50% of its net
 * premium to SBC and 20% (40% of the rest) to the treaty reinsurer, each posted as RI_PREMIUM_CEDED; a claim reserve and payment follow the same shares; the
 * reinsurer balances reconcile to the ledger and the quarterly statement adds up.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-20 10:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->manager = userWithPermissions($this->ctx['tenant_id'], ['ri.manage_treaties', 'ri.view', 'ri.place_facultative', 'policy.create', 'reports.financial']);
    [$this->sbc, $this->globalRe] = asTenant($this->ctx['tenant_id'], function (): array {
        $treaties = app(TreatyService::class);
        $sbc = $treaties->registerReinsurer('Sadharan Bima Corporation', 'SBC', null, null, 'BD', true, $this->manager);
        $globalRe = $treaties->registerReinsurer('Global Re (placeholder)', 'GLOBALRE', 'AA-', 'S&P', 'CH', false, $this->manager);
        $treaties->save(null, ['entity_id' => $this->ctx['entity_id'], 'code' => 'MOT-QS-2026', 'name' => 'Motor quota share 2026', 'class_code' => 'motor', 'underwriting_year' => 2026,
            'period_from' => '2026-07-01', 'period_to' => '2027-06-30', 'type' => 'quota_share', 'cession_bp' => 4000, 'retention_minor' => null, 'lines' => null, 'commission_bp' => 2500,
            'sbc_share_bp' => 5000, 'currency' => 'BDT', 'status' => 'active'], [$globalRe => 10_000], $this->manager);

        return [$sbc, $globalRe];
    });
});

it('cedes premium and claims to SBC and the treaty reinsurer, posts them, reconciles and states the quarter', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $admin = $this->world['admin'];
        $lifecycle = app(PolicyLifecycle::class);
        $policy = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'], null,
            CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $admin);
        $policy = $lifecycle->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $admin);
        $net = $policy->net_premium_minor;

        $ceded = DB::table('ri_cessions')->orderBy('kind')->get(['kind', 'reinsurer_id', 'premium_minor', 'commission_minor'])->map(fn (object $c): array => (array) $c)->all();
        $sbcPremium = RiMath::bp($net, 5000);
        $treatyPremium = RiMath::bp($net - $sbcPremium, 4000);
        expect($ceded)->toBe([
            ['kind' => 'quota_share', 'reinsurer_id' => $this->globalRe, 'premium_minor' => $treatyPremium, 'commission_minor' => RiMath::bp($treatyPremium, 2500)],
            ['kind' => 'sbc', 'reinsurer_id' => $this->sbc, 'premium_minor' => $sbcPremium, 'commission_minor' => RiMath::bp($sbcPremium, 2500)],
        ]);
        $lines = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.description', 'RI_PREMIUM_CEDED')->where('l.dim_reinsurer', DB::table('reinsurers')->where('id', $this->globalRe)->value('party_id'))
            ->orderBy('l.line_no')->get(['l.role_code', 'l.side', 'l.amount_minor'])->map(fn (object $l): array => (array) $l)->all();
        expect($lines)->toBe([
            ['role_code' => 'ri_premium_ceded', 'side' => 'debit', 'amount_minor' => $treatyPremium], ['role_code' => 'ri_payable', 'side' => 'credit', 'amount_minor' => $treatyPremium],
            ['role_code' => 'ri_payable', 'side' => 'debit', 'amount_minor' => RiMath::bp($treatyPremium, 2500)], ['role_code' => 'ri_commission_income', 'side' => 'credit', 'amount_minor' => RiMath::bp($treatyPremium, 2500)],
        ]);

        // A claim reserved at 1,000,000 and paid 800,000: 50% to SBC, 20% to the treaty reinsurer.
        $claim = app(ClaimService::class)->register($policy->id, CarbonImmutable::parse('2026-09-05'), 'Collision', $admin, CarbonImmutable::parse('2026-09-06'));
        app(ClaimService::class)->reserve($claim->id, 100_000_000, 'Initial', userWithPermissions($this->ctx['tenant_id'], ['claim.reserve']), CarbonImmutable::parse('2026-09-06'));
        $payment = app(ClaimPaymentService::class)->approve($claim->id, 80_000_000, $this->world['policyholder_id'], userWithPermissions($this->ctx['tenant_id'], ['claim.approve']), CarbonImmutable::parse('2026-09-07'));
        app(ClaimPaymentService::class)->requestRelease($payment->id, userWithPermissions($this->ctx['tenant_id'], ['claim.pay_request']), null);
        app(ClaimPaymentService::class)->release($payment->id, userWithPermissions($this->ctx['tenant_id'], ['claim.pay_release']), CarbonImmutable::parse('2026-09-08'));

        expect(DB::table('ri_claim_shares')->where('reinsurer_id', $this->globalRe)->orderBy('kind')->get(['kind', 'amount_minor', 'from_reserve_minor'])->map(fn (object $s): array => (array) $s)->all())
            ->toBe([['kind' => 'recoverable', 'amount_minor' => 16_000_000, 'from_reserve_minor' => 16_000_000], ['kind' => 'reserve', 'amount_minor' => 20_000_000, 'from_reserve_minor' => 0]])
            ->and((int) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.description', 'RI_CLAIM_RECOVERABLE')->where('l.role_code', 'ri_claims_recoverable')->sum('l.amount_minor'))
            ->toBe(16_000_000 + 40_000_000);

        // The amounts due to reinsurers reconcile per reinsurer to ri_payable.
        $register = app(ReinsurancePayableReconciler::class)->balanceAt($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-30'))->getMinorAmount()->toInt();
        $gl = (int) DB::table('journal_lines')->where('role_code', 'ri_payable')->selectRaw("coalesce(sum(case when side = 'credit' then amount_minor else -amount_minor end), 0) as b")->value('b');
        expect($register)->toBe($gl)->and($gl)->toBe($sbcPremium - RiMath::bp($sbcPremium, 2500) + $treatyPremium - RiMath::bp($treatyPremium, 2500));

        $statementId = app(ReinsurerStatements::class)->prepare($this->ctx['entity_id'], $this->globalRe, 2026, 3, $this->manager);
        expect(DB::table('ri_statements')->where('id', $statementId)->first(['premium_minor', 'commission_minor', 'claims_recoverable_minor', 'closing_balance_minor']))
            ->toEqual((object) ['premium_minor' => $treatyPremium, 'commission_minor' => RiMath::bp($treatyPremium, 2500), 'claims_recoverable_minor' => 16_000_000,
                'closing_balance_minor' => $treatyPremium - RiMath::bp($treatyPremium, 2500) - 16_000_000]);

        // Cancelling returns the unearned share of every cession.
        $lifecycle->cancel($policy->id, CarbonImmutable::parse('2026-09-15'), 'Sold the car', $admin);
        expect((int) DB::table('ri_cessions')->where('movement', 'cancellation')->sum('premium_minor'))->toBeLessThan(0)
            ->and((int) DB::table('accounting_events')->where('event_type', 'like', 'RI_%')->where('status', '<>', 'posted')->count())->toBe(0);
    });

    actingAs(asTenant($this->ctx['tenant_id'], fn (): User => User::query()->whereKey($this->manager)->firstOrFail()));
    $this->withHeaders(['X-Tenant' => $this->ctx['tenant_id']])->get('/reinsurance/treaties')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('reinsurance/treaties/Index')->has('treaties', 1)->has('reinsurers', 2));
    $this->withHeaders(['X-Tenant' => $this->ctx['tenant_id']])->get('/reinsurance/cessions')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('reinsurance/cessions/Index'));
    $this->withHeaders(['X-Tenant' => $this->ctx['tenant_id']])->get('/reinsurance/statements')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('reinsurance/statements/Index')->has('statements', 1));
    $this->withHeaders(['X-Tenant' => $this->ctx['tenant_id']])->get('/reports/ri-premium-bordereau?from=2026-07-01&to=2026-09-30')->assertOk();
    $this->withHeaders(['X-Tenant' => $this->ctx['tenant_id']])->get('/reports/ri-premium-bordereau/export?format=csv&from=2026-07-01&to=2026-09-30')->assertOk();
});
