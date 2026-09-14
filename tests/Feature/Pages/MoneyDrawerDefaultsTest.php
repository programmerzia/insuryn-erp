<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Commission\Application\CommissionPlanService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Gap fix GA-19: money drawers start at the company's today and with the amount they are about (the deposit with the agent's undeposited cash), the
 * statement run shows the draft statement before it is approved, and agent pickers show code · name.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-30 19:30:00', 'UTC')); // 1 October 01:30 in Dhaka
    $this->ctx = seedDemoTenant();
    $planner = userWithPermissions($this->ctx['tenant_id'], ['commission.manage_plans']);
    $planId = asTenant($this->ctx['tenant_id'], fn (): string => app(CommissionPlanService::class)->create('P10', 'Plan 10%', 1000, null, null, $planner)->id);
    $this->world = seedInsuranceWorld($this->ctx, 'monthly', true, $planId);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    $this->receiptId = asTenant($this->ctx['tenant_id'], function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 12_000_000, 'BDT',
            CarbonImmutable::parse('2026-09-10'), null, 'r', [new AllocationLine((string) DB::table('installments')->value('id'), 12_000_000)]), $this->world['admin'])->id;
    });
});

it('shows the statement run\'s draft - entries, earned, tax withheld and net - before approving, with nothing posted', function (): void {
    // W3 (D-75) made the monthly statement run the only approve path; its draft is the payout preview GA-19 asked for.
    $approver = ($this->userWith)(['commission.approve']);
    $events = fn (): int => asTenant($this->ctx['tenant_id'], fn (): int => DB::table('accounting_events')->count());
    $before = $events();
    actingAs($approver)->post('/distribution/statements/prepare', ['period_end' => '2026-09-30'], $this->headers)->assertSessionHasNoErrors();
    expect($events())->toBe($before);

    actingAs($approver)->get('/distribution/statements?period_end=2026-09-30', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->component('distribution/statements/Index')->where('businessToday', '2026-10-01')->has('statements', 1)->where('statements.0.status', 'draft')
        ->where('statements.0.producer_code', 'AG-001')->where('statements.0.earned', '10,434.78')->where('statements.0.withholding', '0.00')->where('statements.0.net', '10,434.78')
        ->where('can.approve', true)->has('entries', 1)->where('entries.0.earned_on', '2026-09-10')->where('entries.0.amount', '10,434.78')->where('entries.0.withholding', '0.00'));
});

it('lets whoever records agent deposits pick the agent by code or name, shown as code · name', function (): void {
    $collections = ($this->userWith)(['receipt.create']);
    actingAs($collections)->getJson('/lookup/agent?q=jamal', $this->headers)->assertOk()
        ->assertJsonPath('results.0.id', $this->world['agent_id'])->assertJsonPath('results.0.label', 'AG-001 · Jamal Agent');
    // GA-40: the deposit drawer looks agents up for agent cash (the user's branches) instead of receiving every agent as a prop.
    actingAs($collections)->getJson('/lookup/agent?for=agent-cash&q=AG-001', $this->headers)->assertOk()->assertJsonPath('results.0.label', 'AG-001 · Jamal Agent');
});

it('gives every money drawer the company today, not the server clock', function (): void {
    $finance = ($this->userWith)(['commission.approve', 'commission.pay', 'receipt.create', 'receipt.allocate', 'receipt.refund_request', 'receipt.refund_release', 'agent.manage']);
    foreach (['/commission' => 'commission/Index', '/refunds' => 'refunds/Index', '/agent-cash' => 'agentCash/Index', "/receipts/{$this->receiptId}" => 'receipts/Show',
        '/distribution/statements' => 'distribution/statements/Index', "/distribution/producers/{$this->world['agent_id']}" => 'distribution/producers/Show'] as $url => $component) {
        actingAs($finance)->get($url, $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component($component)->where('businessToday', '2026-10-01'));
    }
});
