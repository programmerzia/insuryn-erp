<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Periods\FiscalPeriodService;
use App\Modules\Insurance\Policy\Application\InstallmentQuery;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\PremiumEarning\PremiumEarningRun;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Policy\Infrastructure\Jobs\PremiumEarningJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Design §4.3 / §8.5 premium earning batch: one ledger row per policy per period (rerun = no-op), each posting
 * PREMIUM_EARNED once; §4.4 cancellation catches earning up so the policy's unearned premium ends at zero.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant(); // FY2026 periods: 2026-07 … 2027-06
});

/**
 * @param array{tenant_id: string, entity_id: string, branch_id: string, book_id: string, accounts: array<string, string>} $ctx
 * @param array{admin: string, product_id: string, product_version_id: string, policyholder_id: string, agent_id: string, agent_party_id: string} $world
 */
function issuedPolicy(array $ctx, array $world, string $inception = '2026-07-01', int $premium = 12_000_000): string
{
    $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($ctx['entity_id'], $ctx['branch_id'], $world['product_id'], $world['policyholder_id'],
        $world['agent_id'], CarbonImmutable::parse($inception), $premium, 'BDT', 1), $world['admin']);
    app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse($inception), $world['admin']);

    return $policy->id;
}

/** Unearned premium balance for one policy in the ledger (credit positive), in minor units. */
function unearnedFor(string $policyId, string $unearnedAccountId): int
{
    return (int) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
        ->where('l.account_id', $unearnedAccountId)->where('l.dim_policy', $policyId)->whereIn('j.status', ['posted', 'reversed'])
        ->selectRaw("coalesce(sum(case when l.side = 'credit' then l.amount_minor else -l.amount_minor end), 0) as bal")->value('bal');
}

/** @return list<string> period ids of the fiscal year in order */
function fiscalYearPeriods(): array
{
    return array_values(DB::table('fiscal_periods')->orderBy('period')->pluck('id')->map(fn ($id): string => (string) $id)->all());
}

it('earns the whole net premium over the term, one ledger row and one event per period, and reruns are no-ops', function (string $method): void {
    $world = seedInsuranceWorld($this->ctx, $method);

    asTenant($this->ctx['tenant_id'], function () use ($world): void {
        $policyId = issuedPolicy($this->ctx, $world);
        $net = (int) DB::table('policies')->where('id', $policyId)->value('net_premium_minor');
        $run = app(PremiumEarningRun::class);

        foreach (fiscalYearPeriods() as $periodId) {
            $run->run($periodId);
        }
        $eventsAfterFirstPass = DB::table('accounting_events')->where('event_type', 'PREMIUM_EARNED')->count();
        foreach (fiscalYearPeriods() as $periodId) {
            expect($run->run($periodId)->policiesEarned)->toBe(0);
        }

        expect((int) DB::table('premium_earning_ledger')->where('policy_id', $policyId)->sum('earned_minor'))->toBe($net)
            ->and(DB::table('premium_earning_ledger')->where('policy_id', $policyId)->count())->toBe(12)
            ->and($eventsAfterFirstPass)->toBe(12)
            ->and(DB::table('accounting_events')->where('event_type', 'PREMIUM_EARNED')->count())->toBe(12)
            ->and(DB::table('accounting_events')->where('event_type', 'PREMIUM_EARNED')->where('status', 'posted')->count())->toBe(12)
            ->and(unearnedFor($policyId, $this->ctx['accounts']['unearned_premium']))->toBe(0);
    });
})->with(['daily_365', 'monthly']);

it('does not earn quotes or policies whose cover has not started', function (): void {
    $world = seedInsuranceWorld($this->ctx);

    asTenant($this->ctx['tenant_id'], function () use ($world): void {
        app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $world['product_id'], $world['policyholder_id'],
            null, CarbonImmutable::parse('2026-07-01'), 1_000_000, 'BDT'), $world['admin']);
        issuedPolicy($this->ctx, $world, '2026-10-01');

        expect(app(PremiumEarningRun::class)->run(fiscalYearPeriods()[0])->policiesEarned)->toBe(0)
            ->and(DB::table('premium_earning_ledger')->count())->toBe(0);
    });
});

it('catches earning up on cancellation so the policy has no unearned premium left', function (): void {
    $world = seedInsuranceWorld($this->ctx, 'daily_365');

    asTenant($this->ctx['tenant_id'], function () use ($world): void {
        $policyId = issuedPolicy($this->ctx, $world);
        [$july, $august] = fiscalYearPeriods();
        app(PremiumEarningRun::class)->run($july);
        app(PremiumEarningRun::class)->run($august);

        app(PolicyLifecycle::class)->cancel($policyId, CarbonImmutable::parse('2026-10-16'), 'vehicle written off', $world['admin']);

        $amounts = json_decode((string) DB::table('policy_transactions')->where('policy_id', $policyId)->where('type', 'cancellation')->value('amounts'), true);
        expect((int) DB::table('premium_earning_ledger')->where('policy_id', $policyId)->sum('earned_minor'))->toBe($amounts['earned_to_date'])
            ->and(DB::table('premium_earning_ledger')->where('policy_id', $policyId)->where('kind', 'cancellation_catch_up')->count())->toBe(1)
            ->and(unearnedFor($policyId, $this->ctx['accounts']['unearned_premium']))->toBe(0);

        // later runs skip the cancelled policy
        $periods = fiscalYearPeriods();
        expect(app(PremiumEarningRun::class)->run($periods[3])->policiesEarned)->toBe(0);
    });
});

it('reverses over-earning when a policy is cancelled inside a month that was already earned', function (): void {
    $world = seedInsuranceWorld($this->ctx, 'monthly');

    asTenant($this->ctx['tenant_id'], function () use ($world): void {
        $policyId = issuedPolicy($this->ctx, $world);
        foreach (array_slice(fiscalYearPeriods(), 0, 3) as $periodId) { // July to September earned in full
            app(PremiumEarningRun::class)->run($periodId);
        }

        app(PolicyLifecycle::class)->cancel($policyId, CarbonImmutable::parse('2026-09-11'), 'mistake', $world['admin']);

        expect((int) DB::table('premium_earning_ledger')->where('policy_id', $policyId)->where('kind', 'cancellation_catch_up')->value('earned_minor'))->toBeLessThan(0)
            ->and(unearnedFor($policyId, $this->ctx['accounts']['unearned_premium']))->toBe(0);
    });
});

it('runs nightly for ended periods only, per tenant', function (): void {
    $world = seedInsuranceWorld($this->ctx, 'monthly');
    $policyId = asTenant($this->ctx['tenant_id'], fn (): string => issuedPolicy($this->ctx, $world));

    CarbonImmutable::setTestNow('2026-09-15 02:00:00');
    app()->call([new PremiumEarningJob(), 'handle']);
    CarbonImmutable::setTestNow();

    asTenant($this->ctx['tenant_id'], function () use ($policyId): void {
        expect(DB::table('premium_earning_ledger')->where('policy_id', $policyId)->count())->toBe(2) // July and August ended; September has not
            ->and(DB::table('policies')->where('id', $policyId)->value('status'))->toBe('active');
    });
});

it('lists overdue installments with their outstanding amount and days overdue', function (): void {
    $world = seedInsuranceWorld($this->ctx, 'monthly');

    asTenant($this->ctx['tenant_id'], function () use ($world): void {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $world['product_id'], $world['policyholder_id'],
            null, CarbonImmutable::parse('2026-07-01'), 12_000_000, 'BDT', 3), $world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-07-01'), $world['admin']);

        $overdue = app(InstallmentQuery::class)->overdue($this->ctx['entity_id'], CarbonImmutable::parse('2026-08-15'));
        expect(array_map(fn (array $i): int => $i['no'], $overdue))->toBe([1, 2])
            ->and($overdue[0]['outstanding_minor'])->toBe(4_000_000)
            ->and($overdue[0]['days_overdue'])->toBe(45);
    });
});

it('refuses to earn into a locked period, so the ledger never claims earning the GL rejected', function (): void {
    $world = seedInsuranceWorld($this->ctx, 'monthly');
    $closer = userWithPermissions($this->ctx['tenant_id'], ['periods.soft_lock', 'periods.lock']);

    asTenant($this->ctx['tenant_id'], function () use ($world, $closer): void {
        issuedPolicy($this->ctx, $world);
        $july = fiscalYearPeriods()[0];
        app(FiscalPeriodService::class)->softLock($july, $closer);
        app(FiscalPeriodService::class)->lock($july, $closer);

        expect(thrownBy(fn () => app(PremiumEarningRun::class)->run($july), App\Modules\Platform\Exceptions\BusinessRuleViolation::class)->reasonCode)->toBe('PERIOD_NOT_OPEN')
            ->and(DB::table('premium_earning_ledger')->count())->toBe(0);
    });
});
