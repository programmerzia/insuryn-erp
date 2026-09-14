<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Policy\Infrastructure\Jobs\PremiumEarningJob;
use App\Modules\Platform\Tenancy\BusinessClock;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Slice 2.1b (DECISION D-54, CQ-H2): business dates follow the company's time zone (legal_entities.timezone, Asia/Dhaka by default); technical
 * timestamps stay UTC. At 2026-09-30 19:30 UTC it is already 1 October 01:30 in Dhaka: forms, reports, the current period and the nightly runs
 * all see 1 October.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-30 19:30:00'));
    $this->ctx = seedDemoTenant();
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->clock = fn (): BusinessClock => app(BusinessClock::class);
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
});

it('says it is 1 October in Dhaka while the application clock still says 30 September', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $clock = ($this->clock)();
        expect(CarbonImmutable::today()->toDateString())->toBe('2026-09-30')
            ->and(DB::table('legal_entities')->where('id', $this->ctx['entity_id'])->value('timezone'))->toBe('Asia/Dhaka')
            ->and($clock->timezone())->toBe('Asia/Dhaka')
            ->and($clock->today()->toDateString())->toBe('2026-10-01')
            ->and($clock->today()->format('H:i:s e'))->toBe('00:00:00 UTC') // compares like dates read from the database
            ->and($clock->today()->equalTo(CarbonImmutable::parse('2026-10-01')))->toBeTrue()
            ->and($clock->today($this->ctx['entity_id'])->toDateString())->toBe('2026-10-01')
            ->and($clock->now()->format('Y-m-d H:i'))->toBe('2026-10-01 01:30')
            ->and($clock->now()->getTimestamp())->toBe(CarbonImmutable::now()->getTimestamp());
    });
});

it('follows the entity\'s own time zone, and the default zone outside a tenant', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        DB::table('legal_entities')->where('id', $this->ctx['entity_id'])->update(['timezone' => 'UTC']);
        expect(($this->clock)()->today($this->ctx['entity_id'])->toDateString())->toBe('2026-09-30')
            ->and(($this->clock)()->today()->toDateString())->toBe('2026-09-30');
        DB::table('legal_entities')->where('id', $this->ctx['entity_id'])->update(['timezone' => 'Not/AZone']);
        expect(($this->clock)()->today()->toDateString())->toBe('2026-10-01');
    });

    expect(TenantContext::has())->toBeFalse()
        ->and(($this->clock)()->today()->toDateString())->toBe('2026-10-01');
    config(['erp.business_clock.default_timezone' => 'America/New_York']);
    expect(($this->clock)()->today()->toDateString())->toBe('2026-09-30');
});

it('offers 1 October on the forms, the trial balance and the date inputs', function (): void {
    $user = ($this->userWith)(['accounting.view_journals', 'accounting.create_manual_journal', 'reports.financial', 'receipt.create', 'claim.register']);

    actingAs($user)->get('/accounting/journals/create', $this->headers)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('accounting/journals/Create')->where('today', '2026-10-01')->where('businessToday', '2026-10-01'));
    actingAs($user)->get('/accounting/trial-balance', $this->headers)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('accounting/TrialBalance')->where('asOf', '2026-10-01')->where('compare.asOf', '2026-09-30'));
    actingAs($user)->get('/receipts/create', $this->headers)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Create')->where('defaults.value_date', '2026-10-01'));
    actingAs($user)->get('/claims/create', $this->headers)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('claims/Create')->where('today', '2026-10-01'));
});

it('earns September in the nightly run once September has ended in Dhaka, and not a minute before', function (): void {
    $world = seedInsuranceWorld($this->ctx, 'monthly');
    $policyId = asTenant($this->ctx['tenant_id'], function () use ($world): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $world['product_id'], $world['policyholder_id'],
            $world['agent_id'], CarbonImmutable::parse('2026-07-01'), 12_000_000, 'BDT', 1), $world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-07-01'), $world['admin']);

        return $policy->id;
    });
    $earned = fn (): array => asTenant($this->ctx['tenant_id'], fn (): array => DB::table('premium_earning_ledger as l')->join('fiscal_periods as p', 'p.id', '=', 'l.period_id')
        ->where('l.policy_id', $policyId)->orderBy('p.starts')->pluck('p.starts')->map(fn (mixed $d): string => substr((string) $d, 0, 7))->all());

    travelTo(CarbonImmutable::parse('2026-09-30 17:59:00')); // 23:59 on 30 September in Dhaka
    app()->call([new PremiumEarningJob(), 'handle']);
    expect($earned())->toBe(['2026-07', '2026-08']);

    travelTo(CarbonImmutable::parse('2026-09-30 19:30:00')); // 01:30 on 1 October in Dhaka, still 30 September in UTC
    app()->call([new PremiumEarningJob(), 'handle']);
    expect($earned())->toBe(['2026-07', '2026-08', '2026-09']);
});

it('keeps technical timestamps in UTC while the business date is Dhaka\'s', function (): void {
    $maker = ($this->userWith)(['accounting.create_manual_journal']);
    $journal = asTenant($this->ctx['tenant_id'], fn () => app(ManualJournalService::class)->create(new ManualJournalRequest($this->ctx['entity_id'], ($this->clock)()->today(),
        'Rent', JournalKind::Manual, 'Rent', 'BDT', [
            new ManualJournalLine($this->ctx['accounts']['salary_expense'], Side::Debit, 100_000, ['branch' => $this->ctx['branch_id']]),
            new ManualJournalLine($this->ctx['accounts']['bank_main'], Side::Credit, 100_000, ['branch' => $this->ctx['branch_id']]),
        ]), $maker->id));

    asTenant($this->ctx['tenant_id'], function () use ($journal): void {
        expect((string) DB::table('journals')->where('id', $journal->id)->value('transaction_date'))->toBe('2026-10-01')
            ->and(CarbonImmutable::parse((string) DB::table('audit_events')->where('object_id', $journal->id)->where('action', 'journal.created')->value('occurred_at'))->utc()->format('Y-m-d H:i'))
            ->toBe('2026-09-30 19:30');
    });
});

it('sets the company time zone in the setup wizard, audited, and refuses one that does not exist', function (): void {
    seedRoleTemplates($this->ctx['tenant_id']);
    $admin = ($this->userWith)(['platform.manage_roles']);
    $company = fn (array $extra) => actingAs($admin)->post('/setup/company', ['code' => 'DEMO', 'name' => 'Demo Insurance Ltd', 'branches' => [['code' => 'HO', 'name' => 'Head Office']], ...$extra], $this->headers);

    actingAs($admin)->get('/setup?step=company', $this->headers)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('setup/Index')->where('company.timezone', 'Asia/Dhaka')->where('company.timezones', fn (mixed $zones): bool => is_iterable($zones) && in_array('Asia/Kolkata', iterator_to_array($zones), true)));
    $company(['timezone' => 'Mars/Olympus'])->assertSessionHasErrors(['timezone']);
    $company(['timezone' => 'Asia/Kolkata'])->assertSessionHasNoErrors();

    asTenant($this->ctx['tenant_id'], function (): void {
        expect(DB::table('legal_entities')->where('id', $this->ctx['entity_id'])->value('timezone'))->toBe('Asia/Kolkata')
            ->and(json_decode((string) DB::table('audit_events')->where('action', 'setup.company_saved')->orderByDesc('occurred_at')->value('after'), true)['timezone'])->toBe('Asia/Kolkata')
            ->and(($this->clock)()->today()->toDateString())->toBe('2026-10-01'); // 01:00 in Kolkata
    });
    $company([])->assertSessionHasNoErrors(); // saving without a zone keeps the one set
    expect(asTenant($this->ctx['tenant_id'], fn (): mixed => DB::table('legal_entities')->where('id', $this->ctx['entity_id'])->value('timezone')))->toBe('Asia/Kolkata');
});
