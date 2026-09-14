<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Flow fix X11 (Part A step 12): the finance manager reaches the policy behind a trial balance figure from account activity in one click (a Source column next to
 * the journal), and moves between profit and loss, balance sheet and trial balance for the same period without the reports index.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->reader = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['reports.financial'])));
    [$this->policyId, $this->receiptId] = asTenant($this->ctx['tenant_id'], function (): array {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);
        $receipt = app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 5_000_000, 'BDT',
            CarbonImmutable::parse('2026-09-10'), null, 'r', [new AllocationLine((string) DB::table('installments')->value('id'), 5_000_000)]), $this->world['admin']);

        return [$policy->id, (string) DB::table('receipts')->value('id')];
    });
});

it('links each account activity line to its source record next to its journal', function (): void {
    [$policyNumber, $receiptNumber] = asTenant($this->ctx['tenant_id'], fn (): array => [(string) DB::table('policies')->where('id', $this->policyId)->value('number'),
        (string) DB::table('receipts')->where('id', $this->receiptId)->value('number')]);
    $premiumAccount = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
        ->where('j.source_type', 'policy_transaction')->where('l.side', 'credit')->value('l.account_id'));

    actingAs($this->reader)->get("/reports/account-activity?account_id={$this->ctx['accounts']['bank_main']}&to=2026-09-30", $this->headers)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('columns.2', ['key' => 'source', 'label' => 'Source', 'align' => 'left'])
            ->where('rows.0.cells.source', $receiptNumber)->where('rows.0.links', ['source' => "/receipts/{$this->receiptId}"])
            ->where('rows.0.link', fn (string $link): bool => str_starts_with($link, '/accounting/journals/')));
    actingAs($this->reader)->get("/reports/account-activity?account_id={$premiumAccount}&to=2026-09-30", $this->headers)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.0.cells.source', $policyNumber)->where('rows.0.links.source', "/policies/{$this->policyId}"));
});

it('links profit and loss, balance sheet and trial balance to each other for the same period', function (): void {
    actingAs($this->reader)->get('/reports/profit-and-loss?from=2026-09-01&to=2026-09-30', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('related', [
        ['label' => 'Balance sheet', 'href' => '/reports/balance-sheet?as_of=2026-09-30'],
        ['label' => 'Trial balance', 'href' => '/accounting/trial-balance?as_of=2026-09-30'],
    ]));
    actingAs($this->reader)->get('/reports/balance-sheet?as_of=2026-09-30', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('related', [
        ['label' => 'Profit and loss', 'href' => '/reports/profit-and-loss?from=2026-09-01&to=2026-09-30'],
        ['label' => 'Trial balance', 'href' => '/accounting/trial-balance?as_of=2026-09-30'],
    ]));
    actingAs($this->reader)->get('/reports/premium-register?from=2026-09-01&to=2026-09-30', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->missing('related'));
});
