<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Distribution\Application\CreateProducer;
use App\Modules\Distribution\Application\Incentives\IncentivePlanService;
use App\Modules\Distribution\Application\Licences\LicenceService;
use App\Modules\Distribution\Application\Licences\RecordLicence;
use App\Modules\Distribution\Application\ProducerService;
use App\Modules\Distribution\Application\Targets\TargetService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Commission\Application\CommissionStatementRun;
use App\Modules\Insurance\Commission\Application\IncentiveRun;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * Distribution design note §4 (slice D7): targets by producer, branch or channel and period; achievement from the premium register and
 * collections; incentive tiers computed at period end into the producer statement's bonus; leaderboards and 13th/25th-month persistency. This is
 * how salaried BDOs on a non-life product with no commission are paid for performance.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $admin = $this->world['admin'];
    $day = fn (string $d): CarbonImmutable => CarbonImmutable::parse($d);
    $this->bdo = fn (string $code): string => ($this->in)(function () use ($code, $admin, $day): string {
        DB::table('parties')->insert(['id' => $party = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'kind' => 'individual', 'display_name' => "BDO {$code}", 'status' => 'active']);
        $id = app(ProducerService::class)->create(new CreateProducer($party, $code, 'bdo', $this->ctx['branch_id'], employeeId: (string) Str::uuid7(), joinedOn: $day('2026-01-01')), $admin)->id;
        app(LicenceService::class)->record(new RecordLicence($id, "IDRA-{$code}", 'non_life', $day('2026-01-01'), $day('2027-12-31')), $admin);

        return $id;
    });
    $this->sell = fn (string $producer, int $premium, string $on, ?string $received = null): string => ($this->in)(function () use ($producer, $premium, $on, $received): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            $producer, CarbonImmutable::parse($on), $premium, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse($on), $this->world['admin']);
        if ($received !== null) {
            app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', $premium, 'BDT', CarbonImmutable::parse($received),
                null, 'ref', [new AllocationLine((string) DB::table('installments')->where('policy_id', $policy->id)->value('id'), $premium)]), $this->world['admin']);
        }

        return $policy->id;
    });
    $this->target = fn (string $subjectType, string $subjectId, string $metric, int $value, string $periodType = 'monthly', string $start = '2026-09-01') => ($this->in)(fn () => app(TargetService::class)
        ->set($subjectType, $subjectId, $periodType, CarbonImmutable::parse($start), $metric, $value, $this->world['admin']));
    $this->plan = fn (array $overrides = []): string => ($this->in)(fn (): string => app(IncentivePlanService::class)->create([
        'code' => 'BDO-MONTHLY', 'name' => 'BDO monthly premium bonus', 'period_type' => 'monthly', 'metric' => 'premium', 'applies_to' => ['producer_type' => 'bdo'],
        'tiers' => [['achievement_bp_from' => 10000, 'bonus' => ['type' => 'fixed_minor', 'value' => 5_000_000]], ['achievement_bp_from' => 12000, 'bonus' => ['type' => 'percent_of_metric', 'value' => 100]]],
        'effective_from' => '2026-01-01', ...$overrides], $this->world['admin']));
    $this->runIncentives = fn (string $periodEnd): int => ($this->in)(fn (): int => app(IncentiveRun::class)->run($this->ctx['entity_id'], CarbonImmutable::parse($periodEnd), $this->world['admin']));
});

it('sets targets for producers, branches and channels, one per subject, period and metric', function (): void {
    $bdo = ($this->bdo)('B-1');
    ($this->target)('producer', $bdo, 'premium', 10_000_000);
    ($this->target)('producer', $bdo, 'premium', 12_000_000); // a new value replaces the old one, audited
    ($this->target)('branch', $this->ctx['branch_id'], 'policies', 40);
    ($this->target)('channel', ($this->in)(fn (): string => (string) DB::table('channels')->where('code', 'BDO')->value('id')), 'collections', 90_000_000, 'quarterly', '2026-07-01');

    expect(($this->in)(fn () => DB::table('targets')->orderBy('subject_type')->get(['subject_type', 'metric', 'period_type', 'target_value'])->map(fn ($t): array => (array) $t)->all()))->toBe([
        ['subject_type' => 'branch', 'metric' => 'policies', 'period_type' => 'monthly', 'target_value' => 40],
        ['subject_type' => 'channel', 'metric' => 'collections', 'period_type' => 'quarterly', 'target_value' => 90_000_000],
        ['subject_type' => 'producer', 'metric' => 'premium', 'period_type' => 'monthly', 'target_value' => 12_000_000],
    ])
        ->and(thrownBy(fn () => ($this->target)('producer', $bdo, 'premium', 1, 'quarterly', '2026-08-01'), BusinessRuleViolation::class)->reasonCode)->toBe('TARGET_INVALID')
        ->and(thrownBy(fn () => ($this->target)('producer', $bdo, 'smiles', 1), BusinessRuleViolation::class)->reasonCode)->toBe('TARGET_INVALID')
        ->and(($this->in)(fn () => DB::table('audit_events')->where('action', 'target.set')->count()))->toBe(4);
});

it('validates incentive plan tiers and bonuses', function (): void {
    $refusal = fn (array $overrides): string => thrownBy(fn () => ($this->plan)($overrides), BusinessRuleViolation::class)->reasonCode;

    expect($refusal(['tiers' => []]))->toBe('INCENTIVE_PLAN_INVALID')
        ->and($refusal(['tiers' => [['achievement_bp_from' => 10000, 'bonus' => ['type' => 'fixed_minor', 'value' => 1]], ['achievement_bp_from' => 10000, 'bonus' => ['type' => 'fixed_minor', 'value' => 2]]]]))->toBe('INCENTIVE_PLAN_INVALID')
        ->and($refusal(['metric' => 'policies', 'tiers' => [['achievement_bp_from' => 10000, 'bonus' => ['type' => 'percent_of_metric', 'value' => 100]]]]))->toBe('INCENTIVE_PLAN_INVALID')
        ->and($refusal(['period_type' => 'fortnightly']))->toBe('INCENTIVE_PLAN_INVALID')
        ->and($refusal(['applies_to' => ['producer_type' => 'salesman']]))->toBe('INCENTIVE_PLAN_INVALID');
});

it('pays the tier a producer reached against its target at period end, into its statement, once', function (): void {
    [$star, $close, $short, $untargeted] = [($this->bdo)('B-1'), ($this->bdo)('B-2'), ($this->bdo)('B-3'), ($this->bdo)('B-4')];
    ($this->plan)();
    foreach ([$star, $close, $short] as $bdo) {
        ($this->target)('producer', $bdo, 'premium', 10_000_000);
    }
    ($this->sell)($star, 12_000_000, '2026-09-05');              // 120%: 1% of 12,000,000
    ($this->sell)($close, 10_500_000, '2026-09-06');             // 105%: fixed 50,000
    ($this->sell)($short, 9_000_000, '2026-09-07');              // 90%: nothing
    ($this->sell)($untargeted, 50_000_000, '2026-09-08');        // no target: nothing
    ($this->sell)($star, 1_000_000, '2026-08-20');               // August: not September's production

    expect(($this->runIncentives)('2026-09-30'))->toBe(2)
        ->and(($this->runIncentives)('2026-09-30'))->toBe(0);
    $awards = ($this->in)(fn () => DB::table('incentive_awards as a')->join('producers as p', 'p.id', '=', 'a.producer_id')->orderBy('p.code')
        ->get(['p.code', 'a.actual_value', 'a.achievement_bp', 'a.bonus_minor'])->map(fn ($a): array => array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, (array) $a))->all());
    expect($awards)->toBe([
        ['code' => 'B-1', 'actual_value' => 12_000_000, 'achievement_bp' => 12000, 'bonus_minor' => 120_000],
        ['code' => 'B-2', 'actual_value' => 10_500_000, 'achievement_bp' => 10500, 'bonus_minor' => 5_000_000],
    ])
        ->and(($this->in)(fn () => DB::table('accounting_events')->where('event_type', 'INCENTIVE_BONUS_EARNED')->count()))->toBe(2);

    $approver = userWithPermissions($this->ctx['tenant_id'], ['commission.approve']);
    ($this->in)(fn () => app(CommissionStatementRun::class)->prepare($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-30'), $approver));
    expect(($this->in)(fn () => DB::table('commission_statements')->where('agent_id', $star)->first(['bonus_minor', 'net_minor', 'paid_via'])))
        ->toEqual((object) ['bonus_minor' => 120_000, 'net_minor' => 120_000, 'paid_via' => 'payroll']);
});

it('runs quarterly plans only at quarter end, on collections', function (): void {
    $bdo = ($this->bdo)('B-1');
    ($this->plan)(['code' => 'BDO-Q', 'period_type' => 'quarterly', 'metric' => 'collections', 'tiers' => [['achievement_bp_from' => 5000, 'bonus' => ['type' => 'fixed_minor', 'value' => 100_000]]]]);
    ($this->target)('producer', $bdo, 'collections', 20_000_000, 'quarterly', '2026-07-01');
    ($this->sell)($bdo, 12_000_000, '2026-08-01', '2026-08-05');

    expect(($this->runIncentives)('2026-08-31'))->toBe(0)
        ->and(($this->runIncentives)('2026-09-30'))->toBe(1)
        ->and(($this->in)(fn () => (int) DB::table('incentive_awards')->value('actual_value')))->toBe(12_000_000);
});

it('ranks producers on a leaderboard with their targets, and reports 13th and 25th month persistency', function (): void {
    [$first, $second] = [($this->bdo)('B-1'), ($this->bdo)('B-2')];
    ($this->target)('producer', $first, 'premium', 10_000_000);
    ($this->sell)($first, 12_000_000, '2026-09-05');
    ($this->sell)($second, 4_000_000, '2026-09-06');
    ($this->sell)($second, 5_000_000, '2026-09-07');
    $reader = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['reports.financial'])));

    actingAs($reader)->getJson('/api/reports/leaderboard?metric=premium&from=2026-09-01&to=2026-09-30', $this->headers)->assertOk()
        ->assertJsonPath('data.0.producer_code', 'B-1')->assertJsonPath('data.0.value', 12_000_000)->assertJsonPath('data.0.target', 10_000_000)->assertJsonPath('data.0.achievement_bp', 12000)
        ->assertJsonPath('data.1.producer_code', 'B-2')->assertJsonPath('data.1.value', 9_000_000)->assertJsonPath('data.1.target', null)->assertJsonPath('data.1.rank', 2);

    $policies = ($this->in)(fn () => DB::table('policies')->where('agent_id', $second)->pluck('id')->all());
    ($this->in)(fn () => DB::table('policies')->whereIn('id', $policies)->update(['inception' => '2025-08-01']));
    ($this->in)(fn () => DB::table('policies')->where('id', $policies[0])->update(['status' => 'lapsed']));
    actingAs($reader)->getJson('/api/reports/persistency?as_of=2026-09-30', $this->headers)->assertOk()
        ->assertJsonPath('data.0.producer_code', 'B-2')->assertJsonPath('data.0.month_13_bp', 5000)->assertJsonPath('data.0.month_13_policies', 2)->assertJsonPath('data.0.month_25_bp', null);
});
