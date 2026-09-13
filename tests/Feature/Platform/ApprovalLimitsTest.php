<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Approvals\ApprovalInboxQuery;
use App\Modules\Platform\Approvals\ApprovalPolicyRequest;
use App\Modules\Platform\Approvals\ApprovalPolicyService;
use App\Modules\Platform\Approvals\ApprovalService;
use App\Modules\Platform\Approvals\Decision;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Fix F3 (market cross-check Part A step 7 "approve within their limit, otherwise it routes up"; design §2.1 approval_policies, §7.3): Admin → Approval
 * limits lists and edits the approval policies the engine matches — what they approve, an amount band, roles per step in order and effective dates.
 * Policies in force are ended and succeeded, never rewritten; overlapping policies are refused; every change is audited; the Tenant Admin holds
 * platform.manage_approvals (A-54). A step's role decides who approves (D-26).
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-13 10:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->person = fn (string $name, array $roleCodes): User => ($this->in)(function () use ($name, $roleCodes): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'email' => Str::slug($name).'@example.test', 'name' => $name,
            'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($roleCodes as $code) {
            DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $code)->value('id'),
                'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);
        }

        return User::query()->findOrFail($id);
    });
    $this->admin = ($this->person)('Nadia Admin', ['tenant_admin']);
    $this->limit = fn (array $overrides = []): array => [...['object_type' => 'claim_payment', 'min_amount' => '500,000.00', 'max_amount' => '', 'roles' => ['finance_manager', 'cfo'],
        'effective_from' => '2026-09-13', 'effective_to' => null], ...$overrides];
    $this->policies = fn (): array => ($this->in)(fn (): array => DB::table('approval_policies')->orderBy('effective_from')->orderBy('id')
        ->get(['id', 'object_type', 'condition', 'steps', 'effective_from', 'effective_to'])->map(fn (object $p): array => ['id' => (string) $p->id, 'object_type' => (string) $p->object_type,
            'condition' => json_decode((string) $p->condition, true), 'steps' => json_decode((string) $p->steps, true), 'from' => (string) $p->effective_from,
            'to' => $p->effective_to === null ? null : (string) $p->effective_to])->all());
});

it('lists approval limits by what they approve, and adds one for people who manage approvals only', function (): void {
    actingAs($this->admin)->get('/admin/approval-limits', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('admin/approval-limits/Index')
        ->where('policies', [])->where('currency', 'BDT')->where('today', '2026-09-13')
        ->where('objectTypes', fn ($types): bool => in_array(['value' => 'claim_payment', 'label' => 'Claim payment approval'], json_decode((string) json_encode($types), true), true))
        ->where('roles', fn ($roles): bool => in_array(['code' => 'cfo', 'name' => 'CFO'], json_decode((string) json_encode($roles), true), true)));

    $clerk = ($this->person)('Branch Person', ['branch_officer', 'finance_manager']);
    actingAs($clerk)->get('/admin/approval-limits', $this->headers)->assertForbidden();
    actingAs($clerk)->post('/admin/approval-limits', ($this->limit)(), $this->headers)->assertSessionHasErrors('form');
    expect(($this->policies)())->toBe([]);

    actingAs($this->admin)->post('/admin/approval-limits', ($this->limit)(), $this->headers)->assertSessionHasNoErrors()->assertRedirect('/admin/approval-limits');
    [$policy] = ($this->policies)();
    expect($policy)->toMatchArray(['object_type' => 'claim_payment', 'condition' => ['min_amount_minor' => 50_000_000], 'from' => '2026-09-13', 'to' => null,
        'steps' => [['permission' => 'claim.approve', 'role' => 'finance_manager'], ['permission' => 'claim.approve', 'role' => 'cfo']]]);

    actingAs($this->admin)->get('/admin/approval-limits', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('policies.0.label', 'Claim payment approval')->where('policies.0.amount', '500,000.00 and above')
        ->where('policies.0.approvers', 'Finance Manager → CFO')->where('policies.0.status', 'in_force'));
    ($this->in)(function () use ($policy): void {
        $audit = DB::table('audit_events')->where('action', 'approval_policy.created')->sole(['object_id', 'permission', 'actor_user_id']);
        expect($audit->object_id)->toBe($policy['id'])->and($audit->permission)->toBe('platform.manage_approvals')->and($audit->actor_user_id)->toBe($this->admin->id);
    });
});

it('refuses a policy without steps, with an unknown role, with amounts or dates that make no sense, or overlapping another', function (): void {
    actingAs($this->admin)->post('/admin/approval-limits', ($this->limit)(['roles' => []]), $this->headers)->assertSessionHasErrors('roles');
    actingAs($this->admin)->post('/admin/approval-limits', ($this->limit)(['roles' => ['finance_manager', 'chief_of_nothing']]), $this->headers)
        ->assertSessionHasErrors(['form' => 'Each step needs a role of this organisation; chief_of_nothing is not one.']);
    actingAs($this->admin)->post('/admin/approval-limits', ($this->limit)(['min_amount' => '500,000.00', 'max_amount' => '100,000.00']), $this->headers)->assertSessionHasErrors('form');
    actingAs($this->admin)->post('/admin/approval-limits', ($this->limit)(['min_amount' => 'lots']), $this->headers)->assertSessionHasErrors('min_amount');
    actingAs($this->admin)->post('/admin/approval-limits', ($this->limit)(['effective_from' => '2026-09-01']), $this->headers)->assertSessionHasErrors('form');
    actingAs($this->admin)->post('/admin/approval-limits', ($this->limit)(['object_type' => 'coffee_break']), $this->headers)->assertSessionHasErrors('form');
    expect(($this->policies)())->toBe([]);

    actingAs($this->admin)->post('/admin/approval-limits', ($this->limit)(['effective_to' => '2027-01-01']), $this->headers)->assertSessionHasNoErrors();
    // Same object type, amounts meeting, dates meeting: refused.
    actingAs($this->admin)->post('/admin/approval-limits', ($this->limit)(['min_amount' => '1,000,000.00', 'effective_from' => '2026-12-01']), $this->headers)
        ->assertSessionHasErrors(['form' => 'Another claim payment approval policy already covers 500,000.00 and above from 13 Sep 2026 to 1 Jan 2027. Change or end that policy first, or choose other amounts or dates.']);
    // The band below, a band after the first one ends, and another object type are all fine.
    actingAs($this->admin)->post('/admin/approval-limits', ($this->limit)(['min_amount' => '100,000.00', 'max_amount' => '500,000.00', 'roles' => ['claims_manager']]), $this->headers)->assertSessionHasNoErrors();
    actingAs($this->admin)->post('/admin/approval-limits', ($this->limit)(['effective_from' => '2027-01-01', 'roles' => ['cfo']]), $this->headers)->assertSessionHasNoErrors();
    actingAs($this->admin)->post('/admin/approval-limits', ($this->limit)(['object_type' => 'claim_payment_release', 'roles' => ['cfo']]), $this->headers)->assertSessionHasNoErrors();
    expect(($this->policies)())->toHaveCount(4);
});

it('changes a policy in force by ending it and adding its successor, edits a scheduled one in place, and never changes an ended one', function (): void {
    actingAs($this->admin)->post('/admin/approval-limits', ($this->limit)(), $this->headers);
    [$current] = ($this->policies)();

    actingAs($this->admin)->put("/admin/approval-limits/{$current['id']}", ($this->limit)(['min_amount' => '750,000.00', 'roles' => ['cfo'], 'effective_from' => '2026-10-01']), $this->headers)
        ->assertSessionHasNoErrors();
    [$old, $new] = ($this->policies)();
    expect($old)->toMatchArray(['id' => $current['id'], 'condition' => ['min_amount_minor' => 50_000_000], 'from' => '2026-09-13', 'to' => '2026-10-01'])
        ->and($new)->toMatchArray(['condition' => ['min_amount_minor' => 75_000_000], 'steps' => [['permission' => 'claim.approve', 'role' => 'cfo']], 'from' => '2026-10-01', 'to' => null]);
    ($this->in)(function () use ($old, $new): void {
        expect(DB::table('audit_events')->where('action', 'approval_policy.ended')->where('object_id', $old['id'])->count())->toBe(1)
            ->and(json_decode((string) DB::table('audit_events')->where('action', 'approval_policy.created')->where('object_id', $new['id'])->value('after'), true)['replaces'])->toBe($old['id']);
    });

    // A change dated before the policy started, or in the past, is refused.
    actingAs($this->admin)->put("/admin/approval-limits/{$old['id']}", ($this->limit)(['effective_from' => '2026-09-13']), $this->headers)->assertSessionHasErrors('form');

    // The successor has not started yet: it is edited in place, no third row.
    actingAs($this->admin)->put("/admin/approval-limits/{$new['id']}", ($this->limit)(['min_amount' => '800,000.00', 'roles' => ['finance_manager', 'cfo'], 'effective_from' => '2026-10-01']), $this->headers)
        ->assertSessionHasNoErrors();
    expect(($this->policies)())->toHaveCount(2)->and(($this->policies)()[1])->toMatchArray(['id' => $new['id'], 'condition' => ['min_amount_minor' => 80_000_000]]);

    // Ending: from a date after it starts; afterwards it no longer changes.
    actingAs($this->admin)->post("/admin/approval-limits/{$new['id']}/end", ['effective_to' => '2026-09-20'], $this->headers)->assertSessionHasErrors('form');
    actingAs($this->admin)->post("/admin/approval-limits/{$new['id']}/end", ['effective_to' => '2026-12-31'], $this->headers)->assertSessionHasNoErrors();
    travelTo(CarbonImmutable::parse('2027-01-02 09:00'));
    actingAs($this->admin)->put("/admin/approval-limits/{$new['id']}", ($this->limit)(['effective_from' => '2027-01-02']), $this->headers)
        ->assertSessionHasErrors(['form' => 'This policy has ended and stays as it was. Add a new policy instead.']);
    actingAs($this->admin)->get('/admin/approval-limits', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('policies.0.status', 'ended')->where('policies.1.status', 'ended'));
});

it('routes a claim payment above the limit to the configured roles in order, and one within the limit is approved at once', function (): void {
    $world = seedInsuranceWorld($this->ctx, 'monthly');
    ($this->in)(fn () => app(ApprovalPolicyService::class)->acceptDefaults(CarbonImmutable::parse('2026-09-13'), $this->admin->id));
    $officer = ($this->person)('Claims Officer', ['claims_officer']);
    $claimsManager = ($this->person)('Claims Manager', ['claims_manager']);
    $financeManager = ($this->person)('Finance Manager', ['finance_manager']);
    $cfo = ($this->person)('The CFO', ['cfo']);
    // Holds claim.approve but not the CFO role: the second step is not theirs.
    $otherClaimsManager = ($this->person)('Other Claims Manager', ['claims_manager']);

    ($this->in)(function () use ($world, $officer, $claimsManager, $financeManager, $cfo, $otherClaimsManager): void {
        $on = CarbonImmutable::parse('2026-09-14');
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $world['product_id'], $world['policyholder_id'], $world['agent_id'],
            CarbonImmutable::parse('2026-07-01'), 12_000_000, 'BDT', 1), $world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-07-01'), $world['admin']);
        $claim = app(ClaimService::class)->register($policy->id, $on, 'Total loss', $officer->id, $on);
        app(ClaimService::class)->reserve($claim->id, 100_000_000, 'Total loss', $officer->id, $on);
        $payments = app(ClaimPaymentService::class);
        $approvals = app(ApprovalService::class);

        $small = $payments->approve($claim->id, 40_000_000, $world['policyholder_id'], $claimsManager->id, $on);
        $large = $payments->approve($claim->id, 60_000_000, $world['policyholder_id'], $claimsManager->id, $on);
        expect($small->status->value)->toBe('approved')->and($large->status->value)->toBe('pending_approval');

        $approvalId = (string) $approvals->pendingFor('claim_payment', $large->id);
        expect(array_column(app(ApprovalInboxQuery::class)->decidableBy($financeManager->id), 'id'))->toBe([$approvalId])
            ->and(app(ApprovalInboxQuery::class)->decidableBy($cfo->id))->toBe([])
            ->and(fn () => $approvals->decide($approvalId, $cfo->id, Decision::Approved, null))->toThrow(PermissionDenied::class);

        expect($approvals->decide($approvalId, $financeManager->id, Decision::Approved, null)->value)->toBe('pending')
            ->and(fn () => $approvals->decide($approvalId, $otherClaimsManager->id, Decision::Approved, null))->toThrow(PermissionDenied::class)
            ->and(array_column(app(ApprovalInboxQuery::class)->decidableBy($cfo->id), 'id'))->toBe([$approvalId]);
        expect($approvals->decide($approvalId, $cfo->id, Decision::Approved, null)->value)->toBe('approved')
            ->and(DB::table('claim_payments')->where('id', $large->id)->value('status'))->toBe('approved')
            ->and(DB::table('accounting_events')->where('event_type', 'CLAIM_APPROVED')->count())->toBe(2);
    });
});

it('keeps a role that an approval limit names', function (): void {
    actingAs($this->admin)->post('/admin/approval-limits', ($this->limit)(['roles' => ['cfo']]), $this->headers)->assertSessionHasNoErrors();
    $roleAdmin = ($this->in)(fn (): string => (string) DB::table('roles')->where('code', 'cfo')->value('id'));

    expect(thrownBy(fn () => ($this->in)(fn () => app(App\Modules\Platform\Administration\RoleAdministration::class)->delete($roleAdmin, $this->admin->id)), BusinessRuleViolation::class)->reasonCode)
        ->toBe('ROLE_IN_USE');
});

it('builds the defaults from the template roles, and accepting them twice adds nothing', function (): void {
    $service = fn (): ApprovalPolicyService => app(ApprovalPolicyService::class);
    expect(($this->in)(fn (): int => ($service)()->acceptDefaults(CarbonImmutable::parse('2026-09-13'), $this->admin->id)))->toBe(4)
        ->and(($this->in)(fn (): int => ($service)()->acceptDefaults(CarbonImmutable::parse('2026-09-13'), $this->admin->id)))->toBe(0)
        ->and(array_map(fn (array $p): string => $p['object_type'], ($this->policies)()))->toEqualCanonicalizing(['claim_payment', 'claim_payment_release', 'journal', 'journal_reversal'])
        ->and(fn () => ($this->in)(fn () => ($service)()->create(new ApprovalPolicyRequest('journal', null, null, ['finance_manager'], CarbonImmutable::parse('2026-09-13')), ($this->person)('Nobody', [])->id)))
        ->toThrow(PermissionDenied::class);
});
