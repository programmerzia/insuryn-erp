<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Flow fix X4 (Part A steps 1, 5 and 10): Home starts the day's work — New quote, Record a receipt, Register a claim, New manual journal — for the
 * people who may do each, so the step no longer passes through its list screen. Each link opens a page the user can open.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    seedInsuranceWorld($this->ctx); // a tenant with products: Home, not the setup wizard
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->asRole = fn (string ...$codes): User => asTenant($this->ctx['tenant_id'], function () use ($codes): User {
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'tenant_id' => $this->ctx['tenant_id'], 'email' => $id.'@demo.test', 'name' => implode(' + ', $codes),
            'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($codes as $code) {
            DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $code)->value('id'),
                'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);
        }

        return User::query()->findOrFail($id);
    });
});

it('offers each role the work it starts', function (array $roles, array $starts): void {
    $user = ($this->asRole)(...$roles);
    actingAs($user)->get('/home', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('home/Index')->where('starts', $starts));
    foreach ($starts as $start) {
        actingAs($user)->get($start['href'], $this->headers)->assertOk();
    }
})->with([
    'branch officer' => [['branch_officer'], [['label' => 'New quote', 'href' => '/quotations/create'], ['label' => 'Record a receipt', 'href' => '/receipts/create']]],
    'branch manager' => [['branch_manager'], [['label' => 'New quote', 'href' => '/quotations/create'], ['label' => 'Record a receipt', 'href' => '/receipts/create'],
        ['label' => 'Register a claim', 'href' => '/claims/create']]],
    'claims officer' => [['claims_officer'], [['label' => 'Register a claim', 'href' => '/claims/create']]],
    'accountant' => [['accountant'], [['label' => 'New manual journal', 'href' => '/accounting/journals/create']]],
    'finance manager' => [['finance_manager'], [['label' => 'New manual journal', 'href' => '/accounting/journals/create']]],
    'auditor' => [['auditor'], []],
    'officer and accountant' => [['branch_officer', 'accountant'], [['label' => 'New quote', 'href' => '/quotations/create'], ['label' => 'Record a receipt', 'href' => '/receipts/create'],
        ['label' => 'New manual journal', 'href' => '/accounting/journals/create']]],
]);

it('offers a quote to a branch officer whose role is scoped to the branch', function (): void {
    $user = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['quotation.create'], 'branch', $this->ctx['branch_id'])));
    actingAs($user)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('starts', [['label' => 'New quote', 'href' => '/quotations/create']]));
});

it('offers no manual journal to someone who may draft one but cannot open the journals', function (): void {
    $user = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['accounting.create_manual_journal'])));
    actingAs($user)->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('starts', []));
});
