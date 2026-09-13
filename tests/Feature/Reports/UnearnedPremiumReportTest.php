<?php

declare(strict_types=1);

use App\Http\Pages\PageSupport;
use App\Models\User;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Insurance\Commission\Application\CommissionPlanService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\PremiumEarning\PremiumEarningRun;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Insurance\Reports\Application\PremiumRegisterQuery;
use App\Modules\Insurance\Reports\Application\UnearnedPremiumQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * F5: the unearned premium register as at a date (per policy, by class and product) reconciles to the unearned_premium control through issue,
 * earning, endorsement and cancellation; the premium register is totalled by class and branch; both drill to the policy.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $planner = userWithPermissions($this->ctx['tenant_id'], ['commission.manage_plans']);
    $planId = asTenant($this->ctx['tenant_id'], fn (): string => app(CommissionPlanService::class)->create('P10', 'Plan 10%', 1000, null, null, $planner)->id);
    $this->world = seedInsuranceWorld($this->ctx, 'monthly', true, $planId);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));

    // Motor A (head office): issued July, endorsed 15 August. Motor B (head office): issued September, cancelled 15 October. Fire C (Chattogram): issued September.
    $this->policies = asTenant($this->ctx['tenant_id'], function (): array {
        $admin = $this->world['admin'];
        $this->branch2 = (string) Str::uuid7();
        DB::table('branches')->insert(['id' => $this->branch2, 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => 'CTG', 'name' => 'Chattogram',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $catalogue = app(ProductCatalogue::class);
        $fire = $catalogue->createProduct('FIRE', 'Fire and allied perils', 'fire', $admin);
        $catalogue->addVersion($fire->id, ['effective_from' => '2026-01-01', 'term_months' => 12, 'earning_method' => 'monthly',
            'tax_profile' => ['tax_type' => 'VAT', 'jurisdiction' => 'BD', 'inclusive' => true, 'refund_tax_on_cancellation' => true],
            'commission_plan_id' => null, 'posting_rule_set' => 'default', 'coverages' => [['code' => 'FIRE', 'name' => 'Fire']]], $admin);

        $lifecycle = app(PolicyLifecycle::class);
        $issue = function (string $branchId, string $productId, string $inception, int $premium) use ($lifecycle, $admin): string {
            $policy = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $branchId, $productId, $this->world['policyholder_id'], $this->world['agent_id'],
                CarbonImmutable::parse($inception), $premium, 'BDT', 1), $admin);
            $lifecycle->issue($policy->id, CarbonImmutable::parse($inception), $admin);

            return $policy->id;
        };
        $a = $issue($this->ctx['branch_id'], $this->world['product_id'], '2026-07-01', 12_000_000);
        $lifecycle->endorse($a, CarbonImmutable::parse('2026-08-15'), 1_150_000, 'extra driver', $admin);
        $b = $issue($this->ctx['branch_id'], $this->world['product_id'], '2026-09-01', 6_000_000);
        // Issued at head office, then booked to Chattogram: policy numbers are not yet unique across branches (F1), and the reports read the policy's branch.
        $c = $issue($this->ctx['branch_id'], $fire->id, '2026-09-01', 2_300_000);
        DB::table('policies')->where('id', $c)->update(['branch_id' => $this->branch2]);
        foreach (DB::table('fiscal_periods')->where('ends', '<=', '2026-10-31')->orderBy('starts')->pluck('id') as $periodId) {
            app(PremiumEarningRun::class)->run((string) $periodId);
        }
        // Cancelled mid-October, after October was earned in full: the catch-up is negative and posts on the cancellation date.
        $lifecycle->cancel($b, CarbonImmutable::parse('2026-10-15'), 'sold', $admin);

        return ['a' => $a, 'b' => $b, 'c' => $c];
    });
    $this->earnedBy = fn (string $policyId, string $asOf): int => (int) DB::table('premium_earning_ledger as l')->join('fiscal_periods as p', 'p.id', '=', 'l.period_id')
        ->where('l.policy_id', $policyId)->where('l.kind', 'scheduled')->where('p.ends', '<=', $asOf)->sum('l.earned_minor');
    $this->netOf = fn (string $policyId, string $asOf): int => (int) DB::table('policy_transactions')->where('policy_id', $policyId)->whereIn('type', ['new', 'endorsement'])
        ->where('accounting_date', '<=', $asOf)->sum('net_delta_minor');
});

it('reconciles unearned premium per policy to the unearned premium control on every date through issue, earning, endorsement and cancellation', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        ['a' => $a, 'b' => $b, 'c' => $c] = $this->policies;
        $query = app(UnearnedPremiumQuery::class);

        foreach (['2026-06-30', '2026-07-01', '2026-07-31', '2026-08-15', '2026-08-20', '2026-09-30', '2026-10-14', '2026-10-15', '2026-10-20', '2026-10-31', '2026-11-30', '2027-12-31'] as $day) {
            $report = $query->unearned($this->ctx['entity_id'], CarbonImmutable::parse($day));
            $glBalance = (int) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('l.account_id', $this->ctx['accounts']['unearned_premium'])
                ->whereIn('j.status', ['posted', 'reversed'])->where('j.posting_date', '<=', $day)
                ->selectRaw("coalesce(sum(case when l.side = 'credit' then l.amount_minor else -l.amount_minor end), 0) as b")->value('b');

            expect($report['reconciliation'])->toBe(['register_minor' => $report['totals']['unearned_minor'], 'gl_minor' => $glBalance, 'variance_minor' => 0,
                'account_ids' => [$this->ctx['accounts']['unearned_premium']]], "as at {$day}");
        }

        expect($query->unearned($this->ctx['entity_id'], CarbonImmutable::parse('2026-06-30'))['rows'])->toBe([]);

        // 20 August: A carries its endorsement; only July has been earned (August earns on its last day).
        $august = $query->unearned($this->ctx['entity_id'], CarbonImmutable::parse('2026-08-20'));
        expect($august['rows'])->toHaveCount(1)
            ->and($august['rows'][0]['policy_id'])->toBe($a)
            ->and($august['rows'][0]['net_premium_minor'])->toBe(($this->netOf)($a, '2026-08-20'))
            ->and($august['rows'][0]['earned_minor'])->toBe(($this->earnedBy)($a, '2026-07-31'))
            ->and($august['rows'][0]['earned_minor'])->toBeGreaterThan(0)
            ->and($august['rows'][0]['unearned_minor'])->toBe(($this->netOf)($a, '2026-08-20') - ($this->earnedBy)($a, '2026-07-31'));

        // 30 September: all three, grouped by class (line of business) and product.
        $october = $query->unearned($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-30'));
        $unearned = fn (string $id): int => ($this->netOf)($id, '2026-09-30') - ($this->earnedBy)($id, '2026-09-30');
        expect($october['rows'][0]['policy_id'])->toBe($c)
            ->and(array_column($october['rows'], 'unearned_minor', 'policy_id'))->toEqual([$a => $unearned($a), $b => $unearned($b), $c => $unearned($c)])
            ->and(array_map(fn (array $g): array => [$g['group'], $g['policies'], $g['unearned_minor']], $october['by_class']))->toBe([['fire', 1, $unearned($c)], ['motor', 2, $unearned($a) + $unearned($b)]])
            ->and(array_map(fn (array $g): array => [$g['group'], $g['unearned_minor']], $october['by_product']))->toBe([['FIRE', $unearned($c)], ['MOTOR', $unearned($a) + $unearned($b)]])
            ->and($october['totals']['unearned_minor'])->toBe($unearned($a) + $unearned($b) + $unearned($c))
            ->and($october['rows'][0]['class'])->toBe('fire')
            ->and($october['rows'][0]['branch_code'])->toBe('CTG');

        // Between the cancellation and October's end, B still carries October's scheduled earning (posting on the 31st) that its negative catch-up already took back.
        $octoberEarning = (int) DB::table('premium_earning_ledger as l')->join('fiscal_periods as p', 'p.id', '=', 'l.period_id')->where('l.policy_id', $b)
            ->where('l.kind', 'scheduled')->where('p.starts', '2026-10-01')->value('l.earned_minor');
        expect((int) DB::table('premium_earning_ledger')->where('policy_id', $b)->where('kind', 'cancellation_catch_up')->value('earned_minor'))->toBeLessThan(0)
            ->and(array_column($query->unearned($this->ctx['entity_id'], CarbonImmutable::parse('2026-10-20'))['rows'], 'unearned_minor', 'policy_id')[$b])->toBe($octoberEarning);

        // From October's end B has nothing unearned and leaves the register; A and C remain.
        $november = $query->unearned($this->ctx['entity_id'], CarbonImmutable::parse('2026-10-31'));
        expect(array_column($november['rows'], 'policy_id'))->not->toContain($b)
            ->and(array_column($november['rows'], 'policy_id'))->toContain($a, $c);
    });
});

it('shows a variance when the unearned premium control holds a posting the register does not explain', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $policy = App\Modules\Insurance\Policy\Domain\Models\Policy::query()->whereKey($this->policies['a'])->firstOrFail();
        DB::transaction(fn () => app(SubmitAccountingEvent::class)(
            entityId: $this->ctx['entity_id'], eventType: 'PREMIUM_EARNED', sourceType: 'premium_earning_ledger', sourceId: $policy->id, idempotencyKey: 'PREMIUM_EARNED:stray-test',
            transactionDate: CarbonImmutable::parse('2026-10-15'), effectiveDate: CarbonImmutable::parse('2026-10-15'), currency: 'BDT',
            payload: ['earned' => 12_345], dimensions: App\Modules\Insurance\Policy\Application\PolicyAccountingEvents::dimensions($policy),
        ));

        $before = app(UnearnedPremiumQuery::class)->unearned($this->ctx['entity_id'], CarbonImmutable::parse('2026-10-14'));
        $after = app(UnearnedPremiumQuery::class)->unearned($this->ctx['entity_id'], CarbonImmutable::parse('2026-10-31'));

        expect($before['reconciliation']['variance_minor'])->toBe(0)
            ->and($after['reconciliation']['variance_minor'])->toBe(12_345)
            ->and($after['reconciliation']['gl_minor'])->toBe($after['reconciliation']['register_minor'] - 12_345);
    });
});

it('totals the premium register by class and by branch', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $report = app(PremiumRegisterQuery::class)->register($this->ctx['entity_id'], CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-11-30'));
        $sum = function (string $key, string $value) use ($report): array {
            $rows = array_values(array_filter($report['rows'], fn (array $r): bool => $r[$key] === $value));

            return ['gross_minor' => array_sum(array_column($rows, 'gross_minor')), 'net_minor' => array_sum(array_column($rows, 'net_minor')), 'tax_minor' => array_sum(array_column($rows, 'tax_minor'))];
        };

        expect($report['by_class'])->toBe([['group' => 'fire'] + $sum('class', 'fire'), ['group' => 'motor'] + $sum('class', 'motor')])
            ->and($report['by_branch'])->toBe([['group' => 'CTG'] + $sum('branch_code', 'CTG'), ['group' => 'HO'] + $sum('branch_code', 'HO')])
            ->and(array_sum(array_column($report['by_class'], 'gross_minor')))->toBe($report['totals']['gross_minor'])
            ->and(array_sum(array_column($report['by_branch'], 'net_minor')))->toBe($report['totals']['net_minor'])
            ->and($sum('class', 'fire')['gross_minor'])->toBe(2_300_000);
    });
});

it('serves both reports to reports.financial only, drilling policy numbers to the policy page', function (): void {
    $reader = ($this->userWith)(['reports.financial']);
    $clerk = ($this->userWith)(['policy.create']);
    ['a' => $a, 'c' => $c] = $this->policies;
    $zero = PageSupport::money(0, 'BDT');

    foreach (['/reports/unearned-premium?as_of=2026-09-30', '/reports/premium-register?from=2026-07-01&to=2026-11-30'] as $url) {
        actingAs($clerk)->get($url, $this->headers)->assertForbidden();
    }
    actingAs($clerk)->getJson("/api/reports/unearned-premium?entity_id={$this->ctx['entity_id']}&as_of=2026-09-30", $this->headers + ['Accept' => 'application/json'])->assertForbidden();
    actingAs($reader)->getJson("/api/reports/unearned-premium?entity_id={$this->ctx['entity_id']}&as_of=2026-09-30", $this->headers + ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.reconciliation.variance_minor', 0)->assertJsonCount(3, 'data.rows');

    actingAs($reader)->get('/reports', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('reports/Index')
        ->where('reports', fn (Illuminate\Support\Collection $reports): bool => $reports->pluck('key')->contains('unearned-premium')));

    actingAs($reader)->get('/reports/unearned-premium?as_of=2026-09-30', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('reports/Show')->where('filter', 'as_of')->has('rows', 3)
        ->where('rows.0.link', "/policies/{$c}")->where('rows.0.cells.class', 'fire')
        ->where('summaries.0.title', 'Totals by class')->has('summaries.0.rows', 2)
        ->where('summaries.1.title', 'Totals by product')
        ->where('summaries.2.rows.2.cells.item', 'Variance')->where('summaries.2.rows.2.cells.amount', $zero)
        ->where('summaries.2.rows.1.link', fn (string $link): bool => str_starts_with($link, "/reports/account-activity?account_id={$this->ctx['accounts']['unearned_premium']}")));

    actingAs($reader)->get('/reports/premium-register?from=2026-07-01&to=2026-11-30', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('reports/Show')->where('rows.0.links.policy_number', "/policies/{$a}")
        ->where('rows.0.link', fn (string $link): bool => str_starts_with($link, '/accounting/journals/'))
        ->where('summaries.0.title', 'Totals by class')->where('summaries.0.rows.0.cells.group', 'fire')
        ->where('summaries.1.title', 'Totals by branch')->where('summaries.1.rows.0.cells.group', 'CTG'));
});
