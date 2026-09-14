<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Close\CloseTaskCatalogue;
use App\Modules\Accounting\Application\Close\PeriodCloseService;
use App\Modules\Accounting\Application\Close\YearEndClose;
use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\Queries\FiscalPeriodQuery;
use App\Modules\Accounting\Application\Setup\FiscalYearSetup;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\travelTo;

/**
 * Gap fix GA-15: the next fiscal year is opened from the close (same service as the setup wizard's first year), and the close of a fiscal year's last
 * month closes income and expense into retained earnings with one closing journal (golden fixtures in tests/Fixtures/golden/year_end).
 */
beforeEach(function (): void {
    Queue::fake();
    $this->ctx = seedDemoTenant(); // FY 2026: July 2026 – June 2027, periods 1–12
    $this->finance = userWithPermissions($this->ctx['tenant_id'], ['periods.soft_lock', 'periods.lock', 'reports.financial']);
    $this->period = fn (string $starts): string => asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('fiscal_periods')->where('starts', $starts)->value('id'));
    $this->post = function (string $type, array $payload, string $date): void {
        $dims = ['branch' => $this->ctx['branch_id'], 'product' => (string) Str::uuid7(), 'product_code' => 'MOTOR', 'lob' => 'motor', 'channel' => 'agent',
            'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7(), 'agent' => (string) Str::uuid7(), 'claim' => (string) Str::uuid7()];
        $event = DB::transaction(fn () => app(SubmitAccountingEvent::class)($this->ctx['entity_id'], $type, 'fixture', (string) Str::uuid7(), $type.':'.Str::uuid7(),
            CarbonImmutable::parse($date), CarbonImmutable::parse($date), 'BDT', $payload, $dims));
        expect(app(PostingEngine::class)->post($event->id))->toHaveCount(1);
    };
    $this->roleOf = fn (string $accountId): string => (string) DB::table('account_role_mappings')->where('account_id', $accountId)->value('role_code');
});

it('opens the next fiscal year after the latest one, once that year has started, without overlapping, audited', function (): void {
    travelTo(CarbonImmutable::parse('2026-06-20 10:00'));
    $setup = app(FiscalYearSetup::class);
    asTenant($this->ctx['tenant_id'], function () use ($setup): void {
        $clerk = userWithPermissions($this->ctx['tenant_id'], ['periods.soft_lock']);
        expect(fn () => $setup->openNext($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-14'), $clerk))->toThrow(PermissionDenied::class);
        // Fiscal year 2026 starts on 1 July: the year after it waits.
        expect(thrownBy(fn () => $setup->openNext($this->ctx['entity_id'], CarbonImmutable::parse('2026-06-30'), $this->finance), BusinessRuleViolation::class)->reasonCode)->toBe('FISCAL_YEAR_TOO_EARLY');

        $opened = $setup->openNext($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-14'), $this->finance);
        $periods = DB::table('fiscal_periods')->where('year', 2027)->orderBy('period')->get(['period', 'starts', 'ends', 'status', 'book_id']);
        expect($opened)->toBe(['year' => 2027, 'starts' => '2027-07-01', 'ends' => '2028-06-30', 'periods' => 12])
            ->and($periods)->toHaveCount(12)
            ->and([$periods->first()?->starts, $periods->first()?->ends, $periods->last()?->starts, $periods->last()?->ends])->toBe(['2027-07-01', '2027-07-31', '2028-06-01', '2028-06-30'])
            ->and($periods->pluck('status')->unique()->all())->toBe(['open'])
            ->and($periods->pluck('book_id')->unique()->all())->toBe([$this->ctx['book_id']]);
        $audit = DB::table('audit_events')->where('action', 'fiscal_year.opened')->first(['actor_user_id', 'after', 'permission']);
        expect($audit?->actor_user_id)->toBe($this->finance)->and($audit?->permission)->toBe('periods.lock')
            ->and(json_decode((string) $audit?->after, true))->toMatchArray(['year' => 2027, 'starts' => '2027-07-01', 'ends' => '2028-06-30']);

        // FY 2027 has not started on 14 September 2026, so FY 2028 cannot be opened yet.
        expect(thrownBy(fn () => $setup->openNext($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-14'), $this->finance), BusinessRuleViolation::class)->reasonCode)->toBe('FISCAL_YEAR_TOO_EARLY');
        // Periods already labelled with the next year (a stray one, here) are never overlapped.
        DB::table('fiscal_periods')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'book_id' => $this->ctx['book_id'],
            'year' => 2028, 'period' => 1, 'starts' => '2026-01-01', 'ends' => '2026-01-31', 'status' => 'open']);
        expect(thrownBy(fn () => $setup->openNext($this->ctx['entity_id'], CarbonImmutable::parse('2027-07-02'), $this->finance), BusinessRuleViolation::class)->reasonCode)->toBe('FISCAL_YEAR_OVERLAP')
            ->and(DB::table('fiscal_periods')->where('year', 2028)->count())->toBe(1);
    });
});

it('opens the next fiscal year from the close screen', function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-14 10:00'));
    $headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $user = fn (string $id): App\Models\User => asTenant($this->ctx['tenant_id'], fn (): App\Models\User => App\Models\User::query()->findOrFail($id));
    $reader = userWithPermissions($this->ctx['tenant_id'], ['reports.financial']);

    Pest\Laravel\actingAs($user($reader))->get('/close', $headers)->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
        ->where('nextYear', ['starts' => '2027-07-01', 'ends' => '2028-06-30', 'opens_from' => '2026-07-01', 'can_open' => false]));
    Pest\Laravel\actingAs($user($reader))->post('/close/fiscal-years', [], $headers)->assertSessionHasErrors('form');
    Pest\Laravel\actingAs($user($this->finance))->get('/close', $headers)->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('nextYear.can_open', true));
    Pest\Laravel\actingAs($user($this->finance))->post('/close/fiscal-years', [], $headers)->assertRedirect('/close')
        ->assertSessionHas('status', 'Fiscal year opened: 12 months from 1 Jul 2027 to 30 Jun 2028.');
    Pest\Laravel\actingAs($user($this->finance))->get('/close', $headers)->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->has('periods', 24)
        ->where('nextYear', ['starts' => '2028-07-01', 'ends' => '2029-06-30', 'opens_from' => '2027-07-01', 'can_open' => false]));
});

it('posts the golden year-end closing journal :dataset', function (string $file): void {
    /** @var array{year_end: string, events: list<array{event_type: string, date: string, payload: array<string, int>}>, expected: list<array{0: string, 1: string, 2: int}>} $fixture */
    $fixture = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    asTenant($this->ctx['tenant_id'], function () use ($fixture): void {
        foreach ($fixture['events'] as $event) {
            ($this->post)($event['event_type'], $event['payload'], $event['date']);
        }
        $june = app(FiscalPeriodQuery::class)->find(($this->period)('2027-06-01')) ?? throw new RuntimeException('June 2027 is missing.');
        $result = app(YearEndClose::class)->close($june, $this->finance);

        $journal = Journal::query()->with('lines')->findOrFail((string) $result->details['journal_id']);
        expect($result->passed)->toBeTrue()
            ->and($journal->kind->value)->toBe('closing')->and($journal->status->value)->toBe('posted')->and($journal->number)->toStartWith('JV-')
            ->and($journal->posting_date->toDateString())->toBe($fixture['year_end'])->and($journal->period_id)->toBe($june->id)
            ->and($journal->description)->toBe('Year-end close FY 2026-27')
            ->and($journal->lines->map(fn ($l): array => [($this->roleOf)((string) $l->account_id), $l->side->value, $l->amount_minor])->all())->toBe($fixture['expected'])
            ->and($journal->lines->where('side.value', 'debit')->sum('amount_minor'))->toBe($journal->lines->where('side.value', 'credit')->sum('amount_minor'));

        // Income and expense are zero at the year end; equity carries the year's result; running it again posts nothing.
        $pnl = collect(app(App\Modules\Accounting\Application\LedgerQuery::class)->trialBalance($this->ctx['entity_id'], $this->ctx['book_id'], CarbonImmutable::parse('2027-06-30')))
            ->whereIn('type', ['income', 'expense'])->sum(fn (array $r): int => $r['debit'] - $r['credit']);
        $again = app(YearEndClose::class)->close($june, $this->finance);
        expect($pnl)->toBe(0)->and($again->details['journal_id'])->toBeNull()->and($again->summary)->toContain('already closed')
            ->and(DB::table('journals')->where('kind', 'closing')->count())->toBe(1)
            ->and(DB::table('audit_events')->where('action', 'fiscal_year.closed')->count())->toBe(1);
    });
})->with(fn (): array => array_combine(array_map('basename', glob(__DIR__.'/../../Fixtures/golden/year_end/*.json') ?: []), glob(__DIR__.'/../../Fixtures/golden/year_end/*.json') ?: []));

it('refuses outside a fiscal year\'s last month and without a retained earnings account', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        ($this->post)('PREMIUM_EARNED', ['earned' => 100_000], '2026-09-30');
        $query = app(FiscalPeriodQuery::class);
        expect(thrownBy(fn () => app(YearEndClose::class)->close($query->find(($this->period)('2027-05-01')) ?? throw new RuntimeException(), $this->finance), BusinessRuleViolation::class)->reasonCode)->toBe('NOT_YEAR_END');

        DB::table('account_role_mappings')->where('role_code', 'retained_earnings')->delete();
        expect(thrownBy(fn () => app(YearEndClose::class)->close($query->find(($this->period)('2027-06-01')) ?? throw new RuntimeException(), $this->finance), BusinessRuleViolation::class)->reasonCode)->toBe('RETAINED_EARNINGS_UNMAPPED')
            ->and(DB::table('journals')->where('kind', 'closing')->count())->toBe(0);
    });
});

it('adds the year-end close task only to the close of the fiscal year\'s last month, after everything that posts and before the trial balance', function (): void {
    travelTo(CarbonImmutable::parse('2027-07-02 10:00'));
    asTenant($this->ctx['tenant_id'], function (): void {
        $close = app(PeriodCloseService::class);
        $juneRun = $close->start(($this->period)('2027-06-01'), $this->finance);
        $mayRun = $close->start(($this->period)('2027-05-01'), $this->finance);
        $codes = fn (string $runId): array => DB::table('period_close_tasks')->where('close_run_id', $runId)->orderBy('order_no')->pluck('depends_on', 'code')
            ->map(fn (string $d): array => json_decode($d, true))->all();

        $june = $codes($juneRun);
        expect(array_keys($june))->toContain('year_end_close')->and(array_keys($codes($mayRun)))->not->toContain('year_end_close')
            ->and(array_search('year_end_close', array_keys($june), true))->toBe(array_search('trial_balance', array_keys($june), true) - 1)
            ->and($june['year_end_close'])->toBe(['premium_earning', 'suspense_review', 'bank_reconciliation', 'premium_reconciliation', 'claims_reconciliation',
                'commission_reconciliation', 'upr_reconciliation', 'suspense_reconciliation', 'vat_reconciliation', 'stamp_duty_reconciliation', 'ap_reconciliation', 'accruals'])
            ->and($june['trial_balance'])->toContain('year_end_close')
            ->and(app(CloseTaskCatalogue::class)->find('year_end_close')->permission)->toBe('periods.lock');

        // The task waits for its dependencies, then posts the closing journal like the service.
        ($this->post)('PREMIUM_EARNED', ['earned' => 250_000], '2027-06-30');
        $task = (string) DB::table('period_close_tasks')->where('close_run_id', $juneRun)->where('code', 'year_end_close')->value('id');
        expect(thrownBy(fn () => $close->execute($task, $this->finance), BusinessRuleViolation::class)->reasonCode)->toBe('DEPENDENCIES_OPEN');
        DB::table('period_close_tasks')->where('close_run_id', $juneRun)->whereIn('code', $june['year_end_close'])->update(['status' => 'done']);
        expect($close->execute($task, $this->finance))->toBe('done');
        $result = json_decode((string) DB::table('period_close_tasks')->where('id', $task)->value('result'), true);
        expect($result['summary'])->toStartWith('Income and expense for fiscal year 2026-27 closed to retained earnings in JV-')
            ->and($result['details']['net_result_minor'])->toBe(250_000)
            ->and(DB::table('journals')->where('kind', 'closing')->where('status', 'posted')->count())->toBe(1);
    });
});
