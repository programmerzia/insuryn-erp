<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Flow fix X2 (Part A step 5 and the manual journal): screens that record something happening now receive today's date for their date fields —
 * reported on, the reserve, recovery, reject and reopen dates on a claim, a manual journal's date and a reversal's date. The date of loss is not
 * given one: only the customer knows it.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-14 10:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
});

it('gives the claim screens today for the dates recorded as they happen', function (): void {
    $claimId = asTenant($this->ctx['tenant_id'], function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return app(ClaimService::class)->register($policy->id, CarbonImmutable::parse('2026-09-10'), 'Collision', $this->world['admin'], CarbonImmutable::parse('2026-09-11'))->id;
    });
    $officer = ($this->userWith)(['claim.register', 'claim.reserve']);

    actingAs($officer)->get('/claims/create', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('claims/Create')->where('today', '2026-09-14'));
    actingAs($officer)->get("/claims/{$claimId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('claims/Show')->where('today', '2026-09-14'));
});

it('gives a new manual journal and a reversal request today', function (): void {
    $maker = ($this->userWith)(['accounting.view_journals', 'accounting.create_manual_journal']);
    $journalId = asTenant($this->ctx['tenant_id'], fn (): string => app(ManualJournalService::class)->create(new ManualJournalRequest($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-10'),
        'Rent', JournalKind::Manual, 'Rent', 'BDT', [
            new ManualJournalLine($this->ctx['accounts']['salary_expense'], Side::Debit, 100_000, ['branch' => $this->ctx['branch_id']]),
            new ManualJournalLine($this->ctx['accounts']['bank_main'], Side::Credit, 100_000, ['branch' => $this->ctx['branch_id']]),
        ]), $maker->id)->id);

    actingAs($maker)->get('/accounting/journals/create', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('accounting/journals/Create')->where('today', '2026-09-14'));
    actingAs($maker)->get("/accounting/journals/{$journalId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('accounting/journals/Show')->where('today', '2026-09-14'));
});
