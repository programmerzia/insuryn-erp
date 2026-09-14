<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Flow fix X3 (Part A steps 6–7): after the reserve the settlement is the next step — offered to a user who may approve it now, otherwise the
 * message says who approves; the approval, release and close drawers start from the reserve left, the policyholder, today and the default bank.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-14 10:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    $this->claimId = asTenant($this->ctx['tenant_id'], function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return app(\App\Modules\Insurance\Claims\Application\ClaimService::class)->register($policy->id, CarbonImmutable::parse('2026-09-10'), 'Collision', $this->world['admin'], CarbonImmutable::parse('2026-09-11'))->id;
    });
});

it('tells a claims officer who approves the settlement once the reserve is set', function (): void {
    $officer = ($this->userWith)(['claim.register', 'claim.reserve']);

    actingAs($officer)->post("/claims/{$this->claimId}/reserve", ['reserve' => '200,000.00', 'reason' => 'Initial', 'on' => '2026-09-14'], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionMissing('next_step')
        ->assertSessionHas('status', fn (string $status): bool => str_starts_with($status, 'Reserve set. The ') && str_contains($status, 'claims manager')
            && str_ends_with($status, ' approves the settlement from their Home.'));
    actingAs($officer)->get("/claims/{$this->claimId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('nextStep', null));
});

it('does not offer the approval to the person who reserved when segregation of duties blocks it, and records nothing checking', function (): void {
    $both = ($this->userWith)(['claim.reserve', 'claim.approve']);

    actingAs($both)->post("/claims/{$this->claimId}/reserve", ['reserve' => '200,000.00', 'reason' => 'Initial', 'on' => '2026-09-14'], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionMissing('next_step')->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'approves the settlement from their Home.'));
    expect(asTenant($this->ctx['tenant_id'], fn (): int => DB::table('audit_events')->where('action', 'sod.warning')->count()))->toBe(0);
});

it('offers "Approve payment" next when the user may approve the reserve left within their limit', function (): void {
    asTenant($this->ctx['tenant_id'], fn () => DB::table('sod_rules')->where('permission_a', 'claim.reserve')->where('permission_b', 'claim.approve')->update(['mode' => 'warn']));
    $both = ($this->userWith)(['claim.reserve', 'claim.approve']);

    actingAs($both)->post("/claims/{$this->claimId}/reserve", ['reserve' => '200,000.00', 'reason' => 'Initial', 'on' => '2026-09-14'], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('next_step', 'approve_payment')->assertSessionHas('status', 'Reserve set.');
    expect(asTenant($this->ctx['tenant_id'], fn (): int => DB::table('audit_events')->where('action', 'sod.warning')->count()))->toBe(0);

    actingAs($both)->withSession(['next_step' => 'approve_payment'])->get("/claims/{$this->claimId}", $this->headers)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('nextStep', 'approve_payment'));
});

it('names the approval limit\'s approvers instead when the reserve left is over the user\'s limit', function (): void {
    asTenant($this->ctx['tenant_id'], fn () => DB::table('sod_rules')->where('permission_a', 'claim.reserve')->where('permission_b', 'claim.approve')->update(['mode' => 'warn']));
    approvalPolicy($this->ctx['tenant_id'], 'claim_payment', ['min_amount_minor' => 10_000_000], [['permission' => 'claim.approve', 'role' => 'cfo']]);
    $both = ($this->userWith)(['claim.reserve', 'claim.approve']);

    actingAs($both)->post("/claims/{$this->claimId}/reserve", ['reserve' => '200,000.00', 'reason' => 'Initial', 'on' => '2026-09-14'], $this->headers)
        ->assertSessionMissing('next_step')->assertSessionHas('status', 'Reserve set. The CFO approves the settlement from their Home.');
    expect(asTenant($this->ctx['tenant_id'], fn (): int => DB::table('approvals')->count()))->toBe(0);
});

it('starts the approval, release and close drawers from what the claim already knows', function (): void {
    $bankId = asTenant($this->ctx['tenant_id'], function (): string {
        DB::table('bank_accounts')->insert(['id' => $other = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
            'gl_account_id' => $this->ctx['accounts']['salary_expense'], 'bank_name' => 'Other Bank', 'account_no_masked' => '****9', 'currency' => 'BDT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bank_accounts')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
            'gl_account_id' => $this->ctx['accounts']['bank_main'], 'bank_name' => 'City Bank', 'account_no_masked' => '****1', 'currency' => 'BDT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    });
    $officer = ($this->userWith)(['claim.reserve']);
    $manager = ($this->userWith)(['claim.approve', 'claim.pay_request', 'claim.close']);
    actingAs($officer)->post("/claims/{$this->claimId}/reserve", ['reserve' => '200,000.00', 'reason' => 'Initial', 'on' => '2026-09-14'], $this->headers)->assertSessionHasNoErrors();

    actingAs($manager)->get("/claims/{$this->claimId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('today', '2026-09-14')->where('claim.uncommitted', '200,000.00')->where('claim.policy.policyholder_id', $this->world['policyholder_id']));

    actingAs($manager)->post("/claims/{$this->claimId}/payments", ['amount' => '180,000.00', 'payee_party_id' => $this->world['policyholder_id'], 'on' => '2026-09-14'], $this->headers)->assertSessionHasNoErrors();
    $paymentId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('claim_payments')->value('id'));
    actingAs($manager)->post("/claim-payments/{$paymentId}/request-release", [], $this->headers)->assertSessionHasNoErrors();

    actingAs($manager)->get("/claims/{$this->claimId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('claim.uncommitted', '20,000.00')->where('payments.0.bank_account_id', $bankId));
});

it('puts reserved claims to settle on the claims manager\'s home and requested releases on finance\'s', function (): void {
    $asRole = fn (string $code): User => asTenant($this->ctx['tenant_id'], function () use ($code): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'email' => $id.'@demo.test', 'name' => $code, 'password' => 'x', 'status' => 'active']);
        DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $code)->value('id'), 'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);

        return User::query()->findOrFail($id);
    });
    /** @return array{count: int, rows: list<array{href: string, cells: array<string, string>}>} the queue block, or an empty one when the home has no such queue */
    $block = function (array $queues, string $key): array {
        foreach ($queues as $queue) {
            if (is_array($queue) && ($queue['key'] ?? null) === $key) {
                return ['count' => (int) $queue['count'], 'rows' => array_values((array) $queue['rows'])];
            }
        }

        return ['count' => -1, 'rows' => []];
    };
    $settle = fn (array $queues): array => $block($queues, 'claims_to_settle');
    $release = fn (array $queues): array => $block($queues, 'payments_to_release');
    $manager = $asRole('claims_manager');
    $finance = $asRole('finance_manager');
    $cfo = $asRole('cfo');
    actingAs(($this->userWith)(['claim.reserve']))->post("/claims/{$this->claimId}/reserve", ['reserve' => '200,000.00', 'reason' => 'Initial', 'on' => '2026-09-14'], $this->headers);

    actingAs($manager)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('queues', function ($queues) use ($settle): bool {
        $block = $settle((array) json_decode((string) json_encode($queues), true));

        return $block['count'] === 1 && $block['rows'][0]['href'] === "/claims/{$this->claimId}" && $block['rows'][0]['cells']['reserve'] === '200,000.00';
    }));

    $paymentId = asTenant($this->ctx['tenant_id'], fn (): string => app(ClaimPaymentService::class)->approve($this->claimId, 18_000_000, $this->world['policyholder_id'],
        userWithPermissions($this->ctx['tenant_id'], ['claim.approve']), CarbonImmutable::parse('2026-09-14'))->id);
    actingAs($manager)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues', fn ($queues): bool => $settle((array) json_decode((string) json_encode($queues), true))['count'] === 0));
    actingAs($finance)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues', fn ($queues): bool => $release((array) json_decode((string) json_encode($queues), true))['count'] === 0)); // approved, not yet requested

    asTenant($this->ctx['tenant_id'], fn () => app(ClaimPaymentService::class)->requestRelease($paymentId, userWithPermissions($this->ctx['tenant_id'], ['claim.pay_request']), null));
    foreach ([$finance, $cfo] as $user) {
        actingAs($user)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('queues', function ($queues) use ($release): bool {
            $block = $release((array) json_decode((string) json_encode($queues), true));

            return $block['count'] === 1 && $block['rows'][0]['cells']['status'] === 'release_requested';
        })->where('shell.badges.claims', 1));
    }
});
