<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ChequeBounceService;
use App\Modules\Insurance\Collections\Application\ChequeDetails;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
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
 * Gap fix GA-12: the claims desk could not see the policy it pays on (no link, cover, sum insured, premium status, bounced cheques or earlier claims) and had
 * no report. The claim page now shows a read-only policy panel, registration warns — without refusing — when premium due is unpaid, and the new
 * reports.claims permission opens outstanding claims, claims paid and loss ratio for the claims roles.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-10-20 10:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->asRole = fn (string $role): User => ($this->in)(function () use ($role): User {
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'tenant_id' => $this->ctx['tenant_id'], 'email' => "{$role}-{$id}@example.test", 'name' => $role, 'status' => 'active']);
        DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $role)->value('id'),
            'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);

        return User::query()->findOrFail($id);
    });
    // A policy from 1 Sep in two monthly installments (1 Sep and 1 Oct): the first paid by a cheque that bounced, so both, 120,000.00, are due and unpaid in October.
    [$this->policyId, $this->earlierClaim, $this->claim] = ($this->in)(function (): array {
        $admin = $this->world['admin'];
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 2), $admin);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $admin);
        $first = (string) DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->value('id');
        $receipt = app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cheque', 6_000_000, 'BDT',
            CarbonImmutable::parse('2026-09-02'), null, 'CHQ', [new AllocationLine($first, 6_000_000)], new ChequeDetails('445566', 'Sonali Bank', CarbonImmutable::parse('2026-09-02'))), $admin);
        app(ChequeBounceService::class)->bounce($receipt->id, 'Insufficient funds', $admin, CarbonImmutable::parse('2026-09-10'));
        $claims = app(ClaimService::class);
        $earlier = $claims->register($policy->id, CarbonImmutable::parse('2026-09-05'), 'Side mirror', $admin, CarbonImmutable::parse('2026-09-06'));
        $claim = $claims->register($policy->id, CarbonImmutable::parse('2026-10-10'), 'Collision', $admin, CarbonImmutable::parse('2026-10-11'));

        return [$policy->id, $earlier->id, $claim->id];
    });
    $this->number = ($this->in)(fn (): string => (string) DB::table('policies')->where('id', $this->policyId)->value('number'));
});

it('shows the claims officer the policy behind the claim: cover, premium paid and owed, the bounced cheque and the other claims, without a link they cannot open', function (): void {
    $officer = ($this->asRole)('claims_officer');
    actingAs($officer)->get("/claims/{$this->claim}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('claims/Show')
        ->where('policyFacts.number', $this->number)->where('policyFacts.inception', '2026-09-01')->where('policyFacts.expiry', fn (string $expiry): bool => str_starts_with($expiry, '2027-08'))
        ->where('policyFacts.sum_insured', null)->where('policyFacts.paid', '0.00')->where('policyFacts.outstanding', '120,000.00')->where('policyFacts.unpaid_at_loss', '120,000.00')
        ->where('policyFacts.bounced_cheques', fn ($cheques): bool => count($cheques) === 1 && $cheques[0]['cheque_no'] === '445566' && $cheques[0]['bounced_on'] === '2026-09-10' && $cheques[0]['amount'] === '60,000.00')
        ->where('policyFacts.other_claims', fn ($claims): bool => count($claims) === 1 && $claims[0]['id'] === $this->earlierClaim && $claims[0]['loss_date'] === '2026-09-05')
        ->where('policyFacts.href', null));
    actingAs($officer)->get("/policies/{$this->policyId}", $this->headers)->assertForbidden();

    $admin = ($this->in)(fn (): User => User::query()->findOrFail((string) $this->world['admin']));
    actingAs($admin)->get("/claims/{$this->claim}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('policyFacts.href', "/policies/{$this->policyId}"));
});

it('warns, without refusing, when premium due on the policy is unpaid at registration', function (): void {
    $officer = ($this->asRole)('claims_officer');
    actingAs($officer)->get('/claims/create', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('policies', fn ($policies): bool => array_column(json_decode((string) json_encode($policies), true), 'unpaid_premium', 'id')[$this->policyId] === '120,000.00'));

    actingAs($officer)->post('/claims', ['policy_id' => $this->policyId, 'loss_date' => '2026-10-15', 'reported_on' => '2026-10-20', 'description' => 'Flood damage'], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', fn (string $status): bool => str_ends_with($status, " registered. Premium of 120,000.00 due by the date of loss is unpaid on {$this->number}: check it before reserving."));
    expect(($this->in)(fn (): int => DB::table('claims')->where('policy_id', $this->policyId)->count()))->toBe(3);

    // Once the premium is paid there is no warning.
    ($this->in)(function (): void {
        $lines = [];
        foreach (DB::table('installments')->where('policy_id', $this->policyId)->get(['id', 'amount_minor', 'paid_minor', 'cancelled_minor']) as $i) {
            $lines[] = new AllocationLine((string) $i->id, (int) $i->amount_minor - (int) $i->paid_minor - (int) $i->cancelled_minor);
        }
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 12_000_000, 'BDT',
            CarbonImmutable::parse('2026-10-20'), null, 'TT', $lines), $this->world['admin']);
    });
    actingAs($officer)->post('/claims', ['policy_id' => $this->policyId, 'loss_date' => '2026-10-16', 'reported_on' => '2026-10-20', 'description' => 'Hail'], $this->headers)
        ->assertSessionHas('status', fn (string $status): bool => str_ends_with($status, ' registered.'));
});

it('opens outstanding claims, claims paid and loss ratio to the claims roles through reports.claims, and nothing else', function (): void {
    $templates = RoleTemplates::all();
    expect(array_keys(array_filter($templates, fn (array $t): bool => in_array('reports.claims', $t['permissions'], true))))->toBe(['claims_officer', 'claims_manager']);

    $manager = ($this->asRole)('claims_manager');
    actingAs($manager)->get('/reports', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('reports/Index')
        ->where('reports', fn ($reports): bool => array_column(json_decode((string) json_encode($reports), true), 'key') === ['outstanding-claims', 'claims-paid', 'loss-ratio']));
    actingAs($manager)->get('/reports/outstanding-claims?as_of=2026-10-20', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('reports/Show')
        ->where('rows', fn ($rows): bool => array_filter(array_column(json_decode((string) json_encode($rows), true), 'link'), fn (mixed $link): bool => ! str_starts_with((string) $link, '/claims/')) === []));
    actingAs($manager)->get('/reports/loss-ratio?from=2026-09-01&to=2026-10-31', $this->headers)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rows', fn ($rows): bool => array_filter(array_column(json_decode((string) json_encode($rows), true), 'link')) === []));
    actingAs($manager)->get('/reports/claims-paid/export?format=csv&from=2026-09-01&to=2026-10-31', $this->headers)->assertOk();
    actingAs($manager)->get('/reports/premium-register', $this->headers)->assertForbidden();
    actingAs($manager)->get('/reports/premium-register/export?format=csv', $this->headers)->assertForbidden();
    actingAs($manager)->get('/accounting/trial-balance', $this->headers)->assertForbidden();

    // The finance reader keeps every report and its drill-down links.
    $finance = ($this->asRole)('finance_manager');
    actingAs($finance)->get('/reports', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('reports', fn ($reports): bool => count($reports) > 3));

    // Existing tenants: the migration adds the permission and grants it to the claims roles, safely rerun.
    $holders = fn (): array => ($this->in)(fn (): array => DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')->where('rp.permission_code', 'reports.claims')
        ->where('r.code', 'not like', 'test-%')->orderBy('r.code')->pluck('r.code')->all());
    ($this->in)(fn () => DB::table('role_permissions')->where('permission_code', 'reports.claims')->delete());
    expect($holders())->toBe([]);
    $migration = require database_path('migrations/2026_09_30_000074_add_claims_reports_permission.php');
    $migration->up();
    $migration->up();
    expect($holders())->toBe(['claims_manager', 'claims_officer'])->and(DB::table('permissions')->where('code', 'reports.claims')->exists())->toBeTrue();
});

it('keeps the approved-payment flow on a claim with the panel', function (): void {
    ($this->in)(function (): void {
        app(ClaimService::class)->reserve($this->claim, 5_000_000, 'Initial', userWithPermissions($this->ctx['tenant_id'], ['claim.reserve']), CarbonImmutable::parse('2026-10-12'));
        app(ClaimPaymentService::class)->approve($this->claim, 2_000_000, $this->world['policyholder_id'], userWithPermissions($this->ctx['tenant_id'], ['claim.approve']), CarbonImmutable::parse('2026-10-13'));
    });
    actingAs(($this->asRole)('claims_manager'))->get("/claims/{$this->earlierClaim}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('policyFacts.other_claims', fn ($claims): bool => count($claims) === 1 && $claims[0]['id'] === $this->claim && $claims[0]['reserve'] === '50,000.00'));
});
