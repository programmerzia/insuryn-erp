<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Flow fix X1 (Part A steps 2–3): issuing a policy offers "Record receipt", and the receipt form opened from the policy arrives filled in — the amount
 * outstanding, one allocation line per unpaid installment (oldest first), the policy's branch, today, the channel the user last used.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->user = fn (string $id): User => ($this->in)(fn (): User => User::query()->findOrFail($id));
    $this->admin = ($this->user)($this->world['admin']);
    // Screens open to anyone holding an area permission tenant-wide (reports.financial here); the branch-scoped role decides what they may do in a branch.
    $this->branchUser = function (array $permissions, string $branchId): User {
        $id = userWithPermissions($this->ctx['tenant_id'], ['reports.financial']);
        $scoped = userWithPermissions($this->ctx['tenant_id'], array_values($permissions), 'branch', $branchId);
        ($this->in)(fn () => DB::table('user_roles')->where('user_id', $scoped)->update(['user_id' => $id]));

        return ($this->user)($id);
    };
    $this->quote = fn (int $installments = 2): string => ($this->in)(fn (): string => app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'],
        $this->world['product_id'], $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::parse('2026-09-15'), 12_000_000, 'BDT', $installments), $this->world['admin'])->id);
});

it('offers to record the premium receipt right after issuing, only to someone who may record it', function (): void {
    $policyId = ($this->quote)();
    $response = actingAs($this->admin)->post("/policies/{$policyId}/issue", ['on' => '2026-09-15'], $this->headers);
    $number = ($this->in)(fn (): string => (string) DB::table('policies')->where('id', $policyId)->value('number'));
    $response->assertSessionHasNoErrors()->assertRedirect("/policies/{$policyId}")->assertSessionHas('status', "Policy {$number} issued.")
        ->assertSessionHas('next', ['label' => 'Record receipt', 'url' => "/receipts/create?policy={$policyId}", 'prompt' => 'Record the premium receipt?']);

    $issuer = ($this->user)(userWithPermissions($this->ctx['tenant_id'], ['policy.issue']));
    $other = ($this->quote)();
    actingAs($issuer)->post("/policies/{$other}/issue", ['on' => '2026-09-15'], $this->headers)->assertSessionHasNoErrors()->assertSessionMissing('next');
});

it('shows Record receipt on the policy page while money is outstanding and the user may record receipts in its branch', function (): void {
    $policyId = ($this->quote)(1);
    actingAs($this->admin)->get("/policies/{$policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('actions.record_receipt', false));
    ($this->in)(fn () => app(PolicyLifecycle::class)->issue($policyId, CarbonImmutable::parse('2026-09-15'), $this->world['admin']));
    actingAs($this->admin)->get("/policies/{$policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('actions.record_receipt', true));

    $elsewhere = ($this->in)(function (): string {
        DB::table('branches')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => 'CTG', 'name' => 'Chattogram',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    });
    $otherBranch = ($this->branchUser)(['receipt.create'], $elsewhere);
    actingAs($otherBranch)->get("/policies/{$policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('actions.record_receipt', false));

    actingAs($this->admin)->post('/receipts', ['branch_id' => $this->ctx['branch_id'], 'channel' => 'bank_transfer', 'amount' => '120,000.00', 'value_date' => '2026-09-15',
        'allocations' => [['installment_id' => ($this->in)(fn (): string => (string) DB::table('installments')->where('policy_id', $policyId)->value('id')), 'amount' => '120,000.00']]], $this->headers)
        ->assertSessionHasNoErrors();
    actingAs($this->admin)->get("/policies/{$policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('actions.record_receipt', false));
});

it('prefills the receipt from the policy: total outstanding, a line per unpaid installment oldest first, its branch, today and the last channel', function (): void {
    $policyId = ($this->quote)(2);
    ($this->in)(fn () => app(PolicyLifecycle::class)->issue($policyId, CarbonImmutable::parse('2026-09-15'), $this->world['admin']));
    [$first, $second] = ($this->in)(fn (): array => DB::table('installments')->where('policy_id', $policyId)->orderBy('no')->pluck('id')->map(fn ($id): string => (string) $id)->all());
    $number = ($this->in)(fn (): string => (string) DB::table('policies')->where('id', $policyId)->value('number'));

    actingAs($this->admin)->get("/receipts/create?policy={$policyId}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Create')
        ->where('prefill', ['policy' => ['id' => $policyId, 'number' => $number], 'amount' => '120,000.00', 'branch_id' => $this->ctx['branch_id'], 'allocations' => [
            ['installment_id' => $first, 'label' => "{$number} #1", 'amount' => '60,000.00', 'outstanding' => '60,000.00'],
            ['installment_id' => $second, 'label' => "{$number} #2", 'amount' => '60,000.00', 'outstanding' => '60,000.00'],
        ]])
        ->where('defaults', ['branch_id' => $this->ctx['branch_id'], 'value_date' => '2026-09-15', 'channel' => 'bank_transfer']));

    // Part of the first installment paid by cash: the next prefill carries what is left, and the channel remembered is cash.
    actingAs($this->admin)->post('/receipts', ['branch_id' => $this->ctx['branch_id'], 'channel' => 'cash', 'amount' => '10,000.00', 'value_date' => '2026-09-15',
        'allocations' => [['installment_id' => $first, 'amount' => '10,000.00']]], $this->headers)->assertSessionHasNoErrors();
    actingAs($this->admin)->get("/receipts/create?policy={$policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('prefill.amount', '110,000.00')->where('prefill.allocations.0.amount', '50,000.00')->where('prefill.allocations.1.amount', '60,000.00')
        ->where('defaults.channel', 'cash'));
});

it('keeps the receipt form without a policy unfilled, with the user\'s branch and today', function (): void {
    actingAs($this->admin)->get('/receipts/create', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Create')
        ->where('prefill', null)->where('defaults', ['branch_id' => $this->ctx['branch_id'], 'value_date' => '2026-09-15', 'channel' => 'bank_transfer']));
    // A policy fully paid or unknown prefills nothing.
    actingAs($this->admin)->get('/receipts/create?policy='.Str::uuid7(), $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', null));
    actingAs($this->admin)->get('/receipts/create?policy=not-a-uuid', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', null));
});

it('defaults the branch to the chosen branch, else the one branch the roles are scoped to, else nothing when several branches exist', function (): void {
    $chattogram = ($this->in)(function (): string {
        DB::table('branches')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => 'CTG', 'name' => 'Chattogram',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    });
    actingAs($this->admin)->get('/receipts/create', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('defaults.branch_id', null));

    $scoped = ($this->branchUser)(['receipt.create'], $chattogram);
    actingAs($scoped)->get('/receipts/create', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('defaults.branch_id', $chattogram));

    actingAs($this->admin)->put('/preferences/branch_id', ['value' => $chattogram], $this->headers)->assertSuccessful();
    actingAs($this->admin)->get('/receipts/create', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('defaults.branch_id', $chattogram));
});
