<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * G2 (design §7.2 "user_roles.scope restricts to entity/branch … branch users never see other branches"): a user whose only role is scoped to a branch opens the
 * policy, receipt, claim, quotation and proposal screens of that branch; lists and pickers show only that branch's records, and a record of another branch is 403.
 * Actions keep their own per-branch checks.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    // Receipt and claim numbers are not branch-coded by default, so two branches' first receipts would share a number (a gap, not this fix).
    config(['erp.numbering.formats.receipt' => '{prefix}-{branch}-{fy}-{seq}', 'erp.numbering.formats.claim' => '{prefix}-{branch}-{fy}-{seq}']);
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->world = ratedProductsWorld($this->ctx);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->home = $this->ctx['branch_id'];
    $this->ctg = ($this->in)(function (): string {
        DB::table('branches')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => 'CTG',
            'name' => 'Chattogram', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    });
    /** A user holding the template roles, each scoped to $branch. */
    $this->scoped = fn (array $roleCodes, string $branch): User => ($this->in)(function () use ($roleCodes, $branch): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'email' => $id.'@example.test', 'name' => 'Branch user',
            'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($roleCodes as $code) {
            DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $code)->value('id'),
                'scope_type' => 'branch', 'scope_id' => $branch]);
        }

        return User::query()->findOrFail($id);
    });
    $admin = $this->world['admin'];
    /** An issued typed-premium policy in $branch with one unpaid installment, and a claim and a receipt on it. */
    $this->sold = fn (string $branch): array => ($this->in)(function () use ($branch, $admin): array {
        $lifecycle = app(PolicyLifecycle::class);
        $policy = $lifecycle->issue($lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $branch, $this->world['product_id'], $this->world['policyholder_id'], null,
            CarbonImmutable::parse('2026-09-01'), 1_200_000, 'BDT', 2), $admin)->id, CarbonImmutable::parse('2026-09-01'), $admin);
        $receipt = app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $branch, null, 'cash', 100_000, 'BDT', CarbonImmutable::parse('2026-09-10'),
            null, 'counter', []), $admin);
        $claim = app(ClaimService::class)->register($policy->id, CarbonImmutable::parse('2026-09-10'), 'Collision', $admin, CarbonImmutable::parse('2026-09-11'));
        $quotations = app(QuotationService::class);
        $quotation = $quotations->issue($quotations->saveDraft(new QuotationTerms($branch, $this->world['motor_product_id'], $this->world['policyholder_id'], $this->world['agent_id'],
            CarbonImmutable::parse('2026-09-15'), [...$this->world['motor_inputs'], 'registration_no' => 'DHK-'.Str::random(6), 'chassis_no' => 'CH-'.Str::random(8)], ['passenger_liability']),
            null, $admin)->id, CarbonImmutable::today(), $admin);
        $proposal = app(ProposalService::class)->createFromQuotation($quotation->id, $admin);

        return ['policy' => $policy->id, 'number' => (string) $policy->number, 'receipt' => $receipt->id, 'claim' => $claim->id, 'quotation' => $quotation->id, 'proposal' => $proposal->id];
    });
    $this->mine = ($this->sold)($this->home);
    $this->theirs = ($this->sold)($this->ctg);
    $this->ids = fn (array $rows): array => array_values(array_map(fn (array $row): string => (string) $row['id'], $rows));
});

it('opens the policy, receipt and new receipt pages of the officer\'s own branch and lists only that branch', function (): void {
    $officer = ($this->scoped)(['branch_officer'], $this->home);

    actingAs($officer)->get('/policies', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('policies/Index')
        ->where('policies.data', fn ($rows): bool => ($this->ids)((array) json_decode((string) json_encode($rows), true)) === [$this->mine['policy']]));
    actingAs($officer)->get("/policies/{$this->mine['policy']}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('policies/Show'));
    actingAs($officer)->get('/policies/create', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('branches', fn ($rows): bool => ($this->ids)((array) json_decode((string) json_encode($rows), true)) === [$this->home]));

    actingAs($officer)->get('/receipts', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Index')
        ->where('receipts.data', fn ($rows): bool => ($this->ids)((array) json_decode((string) json_encode($rows), true)) === [$this->mine['receipt']]));
    actingAs($officer)->get("/receipts/{$this->mine['receipt']}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Show'));
    actingAs($officer)->get("/receipts/create?policy={$this->mine['policy']}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Create')
        ->where('defaults.branch_id', $this->home)->where('prefill.policy.id', $this->mine['policy'])
        ->where('branches', fn ($rows): bool => ($this->ids)((array) json_decode((string) json_encode($rows), true)) === [$this->home])
        ->where('installments', fn ($rows): bool => collect((array) json_decode((string) json_encode($rows), true))->every(fn (array $row): bool => str_starts_with((string) $row['label'], $this->mine['number'].' '))
            && count((array) json_decode((string) json_encode($rows), true)) === 2));

    // Another branch's policy is not offered for a receipt.
    actingAs($officer)->get("/receipts/create?policy={$this->theirs['policy']}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', null));
});

it('refuses another branch\'s policy, receipt, claim, quotation and proposal pages to a branch-scoped user', function (): void {
    $manager = ($this->scoped)(['branch_manager'], $this->home);

    foreach (['policies' => 'policy', 'receipts' => 'receipt', 'claims' => 'claim', 'quotations' => 'quotation', 'proposals' => 'proposal'] as $path => $key) {
        actingAs($manager)->get("/{$path}/{$this->mine[$key]}", $this->headers)->assertOk();
        actingAs($manager)->get("/{$path}/{$this->theirs[$key]}", $this->headers)->assertForbidden();
    }
});

it('lists only the branch\'s claims and quotations and offers only its policies and branches when creating', function (): void {
    $manager = ($this->scoped)(['branch_manager'], $this->home);

    actingAs($manager)->get('/claims', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('claims.data', fn ($rows): bool => ($this->ids)((array) json_decode((string) json_encode($rows), true)) === [$this->mine['claim']]));
    actingAs($manager)->get('/claims/create', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('policies', fn ($rows): bool => ($this->ids)((array) json_decode((string) json_encode($rows), true)) === [$this->mine['policy']]));
    actingAs($manager)->get('/quotations', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('quotations', fn ($rows): bool => ($this->ids)((array) json_decode((string) json_encode($rows), true)) === [$this->mine['quotation']]));
    actingAs($manager)->get('/quotations/create', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('branches', fn ($rows): bool => ($this->ids)((array) json_decode((string) json_encode($rows), true)) === [$this->home]));
});

it('still refuses actions on another branch through their own per-branch checks', function (): void {
    $officer = ($this->scoped)(['branch_officer'], $this->home);

    actingAs($officer)->post('/receipts', ['branch_id' => $this->ctg, 'channel' => 'cash', 'amount' => '1,000.00', 'value_date' => '2026-09-15'], $this->headers)
        ->assertSessionHasErrors(['reason' => 'PERMISSION_DENIED']);
    expect(($this->in)(fn (): int => DB::table('receipts')->where('branch_id', $this->ctg)->count()))->toBe(1);
});

it('keeps tenant-wide and entity-wide users seeing every branch', function (): void {
    $tenantWide = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['receipt.create', 'policy.create'])));
    $entityWide = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['receipt.create', 'policy.create'], 'entity', $this->ctx['entity_id'])));

    foreach ([$tenantWide, $entityWide] as $user) {
        actingAs($user)->get('/policies', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('policies.data', 2));
        actingAs($user)->get('/receipts', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('receipts.data', 2));
        actingAs($user)->get("/policies/{$this->theirs['policy']}", $this->headers)->assertOk();
    }
    // Without any of the area's permissions the pages stay closed.
    $claimsOnly = ($this->scoped)(['claims_officer'], $this->home);
    actingAs($claimsOnly)->get('/policies', $this->headers)->assertForbidden();
    actingAs($claimsOnly)->get('/receipts/create', $this->headers)->assertForbidden();
});
