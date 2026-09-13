<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\ReversalService;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Domain\Models\Journal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Slice 0.6 read side (design §9.2 Reports: TB; spec §9 "Accounting is invisible by default, visible on
 * demand, drillable"): trial balance, journal list and journal detail as read-only Inertia pages.
 */
beforeEach(function (): void {
    Queue::fake();
    $this->ctx = seedDemoTenant();
    $this->viewer = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['accounting.view_journals', 'reports.financial'])));

    [$this->first, $this->reversal] = asTenant($this->ctx['tenant_id'], function (): array {
        $post = function (string $key, int $amount): Journal {
            $event = DB::transaction(fn () => app(SubmitAccountingEvent::class)(
                $this->ctx['entity_id'], 'PREMIUM_RECEIVED', 'test', (string) Str::uuid7(), $key,
                CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'BDT', ['amount' => $amount],
                ['branch' => $this->ctx['branch_id'], 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7()]));

            return app(PostingEngine::class)->post($event->id)[0];
        };
        $first = $post('pages-1', 123_456);
        $post('pages-2', 50_000);
        $reversal = app(ReversalService::class)->reverse(Journal::query()->findOrFail($first->id), CarbonImmutable::parse('2026-09-20'), 'bounced', (string) Str::uuid7());

        return [$first, $reversal];
    });
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
});

it('shows the trial balance as of a date with balanced totals', function (): void {
    $this->withoutVite();

    actingAs($this->viewer)->get('/accounting/trial-balance?as_of=2026-09-30', $this->headers)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounting/TrialBalance')
            ->where('asOf', '2026-09-30')
            ->where('entity.code', 'DEMO')
            ->has('rows', 2)
            ->where('rows.0.code', '1010')
            ->where('rows.0.debit', '1,734.56')
            ->where('rows.0.credit', '1,234.56')
            ->where('totals.debit', '2,969.12')
            ->where('totals.credit', '2,969.12')
            ->where('totals.balanced', true));
});

it('lists journals newest first with status, kind and totals', function (): void {
    $this->withoutVite();

    actingAs($this->viewer)->get('/accounting/journals', $this->headers)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounting/journals/Index')
            ->has('journals.data', 3)
            ->where('journals.data.0.id', $this->reversal->id)
            ->where('journals.data.0.kind', 'reversal')
            ->where('journals.data.0.total', '1,234.56')
            ->where('journals.data.2.status', 'reversed'));
});

it('filters the journal list by status', function (): void {
    $this->withoutVite();

    actingAs($this->viewer)->get('/accounting/journals?status=reversed', $this->headers)
        ->assertInertia(fn (AssertableInertia $page) => $page->has('journals.data', 1)->where('journals.data.0.id', $this->first->id)->where('filters.status', 'reversed'));
});

it('shows a journal with its lines, source event and reversal links in both directions', function (): void {
    $this->withoutVite();

    actingAs($this->viewer)->get("/accounting/journals/{$this->first->id}", $this->headers)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounting/journals/Show')
            ->where('journal.id', $this->first->id)
            ->where('journal.status', 'reversed')
            ->where('journal.event.type', 'PREMIUM_RECEIVED')
            ->has('journal.lines', 2)
            ->where('journal.lines.0.account.code', '1010')
            ->where('journal.lines.0.side', 'debit')
            ->where('journal.lines.0.amount', '1,234.56')
            ->where('journal.lines.1.account.code', '1100')
            ->where('journal.reversedBy.id', $this->reversal->id)
            ->where('journal.reverses', null)
            ->has('journal.corrections', 0));

    actingAs($this->viewer)->get("/accounting/journals/{$this->reversal->id}", $this->headers)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('journal.reverses.id', $this->first->id)->where('journal.reason', 'bounced'));
});

it('lists draft manual journals but keeps them out of the trial balance', function (): void {
    $this->withoutVite();
    $maker = userWithPermissions($this->ctx['tenant_id'], ['accounting.create_manual_journal']);
    asTenant($this->ctx['tenant_id'], fn () => app(ManualJournalService::class)->create(new App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest(
        $this->ctx['entity_id'], CarbonImmutable::parse('2026-09-21'), 'Draft accrual', App\Modules\Accounting\Domain\Enums\JournalKind::Manual, 'accrual', 'BDT', [
            new App\Modules\Accounting\Application\ManualJournals\ManualJournalLine($this->ctx['accounts']['bank_main'], App\Modules\Accounting\Domain\Enums\Side::Debit, 999_00),
            new App\Modules\Accounting\Application\ManualJournals\ManualJournalLine($this->ctx['accounts']['retained_earnings'], App\Modules\Accounting\Domain\Enums\Side::Credit, 999_00),
        ]), $maker));

    actingAs($this->viewer)->get('/accounting/journals?status=draft', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->has('journals.data', 1));
    actingAs($this->viewer)->get('/accounting/trial-balance?as_of=2026-09-30', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('totals.debit', '2,969.12'));
});

it('requires accounting.view_journals and a signed-in user', function (): void {
    get('/accounting/journals', $this->headers)->assertRedirect('/login'); // Fortify sign-in (Zitadel OIDC is LATER)

    $stranger = asTenant($this->ctx['tenant_id'], fn (): User => User::factory()->create());
    actingAs($stranger)->get('/accounting/journals', $this->headers)->assertForbidden();
    actingAs($stranger)->get('/accounting/trial-balance', $this->headers)->assertForbidden();
});

it('does not reveal journals of another tenant', function (): void {
    $other = seedDemoTenant('pages-other');
    $otherViewer = asTenant($other['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($other['tenant_id'], ['accounting.view_journals'])));

    actingAs($otherViewer)->get("/accounting/journals/{$this->first->id}", ['X-Tenant' => $other['tenant_id']])->assertNotFound();
});
