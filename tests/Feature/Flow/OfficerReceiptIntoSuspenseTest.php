<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Authorization\RoleTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Gap fix GA-03 (DECISION D-65): "Record receipt" on a policy prefilled allocation lines a branch officer may not post (design §7.2 gives receipt.allocate to
 * the branch manager and the accountant, not the officer), so the review only said "You do not have permission". The design split is kept: the officer
 * records the money with the policy noted, it is held in suspense, and the branch manager's Home lists it to allocate.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->asRole = fn (string $role): User => ($this->in)(function () use ($role): User {
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'tenant_id' => $this->ctx['tenant_id'], 'email' => "{$role}-{$id}@example.test", 'name' => $role, 'status' => 'active']);
        DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $role)->value('id'),
            'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);

        return User::query()->findOrFail($id);
    });
    $this->policyId = ($this->in)(function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            $this->world['agent_id'], CarbonImmutable::parse('2026-09-15'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-15'), $this->world['admin']);

        return $policy->id;
    });
    $this->number = ($this->in)(fn (): string => (string) DB::table('policies')->where('id', $this->policyId)->value('number'));
});

it('keeps allocation with the branch manager and the accountant: no segregation rule forbids it, the §7.2 templates do', function (): void {
    $templates = RoleTemplates::all();
    expect(in_array('receipt.allocate', $templates['branch_officer']['permissions'], true))->toBeFalse()
        ->and($templates['branch_officer']['permissions'])->toContain('receipt.create')
        ->and($templates['branch_manager']['permissions'])->toContain('receipt.allocate')
        ->and($templates['accountant']['permissions'])->toContain('receipt.allocate');
});

it('lets the officer record the premium from the policy into suspense, noted for the policy, and the branch manager allocate it from Home', function (): void {
    $officer = ($this->asRole)('branch_officer');
    $manager = ($this->asRole)('branch_manager');

    actingAs($officer)->get("/policies/{$this->policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('actions.record_receipt', true));
    actingAs($officer)->get("/receipts/create?policy={$this->policyId}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('prefill.policy.number', $this->number)->where('prefill.amount', '120,000.00')->where('allocateBranchIds', []));
    actingAs($manager)->get("/receipts/create?policy={$this->policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('allocateBranchIds', [$this->ctx['branch_id']]));

    // What the officer's screen used to send is still refused: the officer does not allocate.
    $installment = ($this->in)(fn (): string => (string) DB::table('installments')->where('policy_id', $this->policyId)->value('id'));
    $receipt = ['branch_id' => $this->ctx['branch_id'], 'channel' => 'cash', 'amount' => '120,000.00', 'value_date' => '2026-09-15', 'reference' => 'Counter'];
    actingAs($officer)->post('/receipts', [...$receipt, 'allocations' => [['installment_id' => $installment, 'amount' => '120,000.00']]], $this->headers)->assertSessionHasErrors('form');

    // The preview and the post with the policy noted and no allocation lines go through.
    actingAs($officer)->postJson('/receipts', [...$receipt, 'for_policy_id' => $this->policyId, 'allocations' => []], [...$this->headers, 'X-Journal-Preview' => '1'])
        ->assertOk()->assertJsonPath('posts', true)->assertJsonPath('journals.0.event', 'RECEIPT_RECORDED');
    actingAs($officer)->post('/receipts', [...$receipt, 'for_policy_id' => $this->policyId, 'allocations' => []], $this->headers)->assertSessionHasNoErrors()
        ->assertSessionHas('status', fn (string $status): bool => str_ends_with($status, "recorded. The money is held in suspense for {$this->number}; your branch manager allocates it."));
    $row = ($this->in)(fn (): object => DB::table('receipts')->firstOrFail(['id', 'status', 'for_policy_id']));
    expect($row->status)->toBe('unallocated')->and($row->for_policy_id)->toBe($this->policyId);
    actingAs($officer)->get("/receipts/{$row->id}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('receipt.for_policy', ['id' => $this->policyId, 'number' => $this->number])->where('actions.allocate', false)->where('suspense.open', '120,000.00'));

    actingAs($manager)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues.5.key', 'receipts_to_allocate')->where('queues.5.count', 1)->where('queues.5.rows.0.href', "/receipts/{$row->id}/allocate")
        ->where('queues.5.rows.0.cells.policy', $this->number)->where('queues.5.rows.0.cells.amount', '120,000.00'));
    actingAs($manager)->get("/receipts/{$row->id}/allocate", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('receipt.for_policy', $this->number)->where('candidates.0.policy_number', $this->number)->where('candidates.0.for_this_policy', true));

    $item = ($this->in)(fn (): string => (string) DB::table('suspense_items')->value('id'));
    actingAs($manager)->post("/suspense/{$item}/allocations", ['on' => '2026-09-15', 'lines' => [['installment_id' => $installment, 'amount' => '120,000.00']]], $this->headers)->assertSessionHasNoErrors();
    actingAs($manager)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('queues.5.key', 'receipts_to_allocate')->where('queues.5.count', 0));
});

it('refuses a receipt noted for a policy that cannot receive premium', function (): void {
    $officer = ($this->asRole)('branch_officer');
    actingAs($officer)->post('/receipts', ['branch_id' => $this->ctx['branch_id'], 'channel' => 'cash', 'amount' => '100.00', 'value_date' => '2026-09-15',
        'for_policy_id' => (string) Str::uuid7()], $this->headers)->assertSessionHasErrors(['form' => 'The receipt was taken for a policy that cannot receive premium. Open the receipt from an issued policy.']);
    expect(($this->in)(fn (): int => DB::table('receipts')->count()))->toBe(0);
});
