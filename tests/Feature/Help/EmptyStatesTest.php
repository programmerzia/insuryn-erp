<?php

declare(strict_types=1);

use App\Http\Home\WorkQueues;
use App\Models\User;
use Database\Seeders\BlankTenantSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Session S6 (UX brief §4 "Empty states: one sentence + one primary action"): every home queue that is empty says so in one sentence and offers one
 * action, and the screens learn whether the tenant still needs setting up (no products yet) so empty lists can point to the setup wizard — and,
 * locally, to the demo story.
 */
beforeEach(function (): void {
    $this->withoutVite();
});

it('gives every home queue one sentence and one action for when it is empty', function (): void {
    $ctx = seedDemoTenant();
    seedRoleTemplates($ctx['tenant_id']);
    $user = asTenant($ctx['tenant_id'], function () use ($ctx): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $ctx['tenant_id'], 'email' => 'all@demo.test', 'name' => 'Everyone', 'password' => 'x', 'status' => 'active']);
        foreach (array_keys(WorkQueues::BY_ROLE) as $code) {
            DB::table('user_roles')->insert(['tenant_id' => $ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $code)->value('id'), 'scope_type' => 'tenant', 'scope_id' => $ctx['tenant_id']]);
        }

        return User::query()->findOrFail($id);
    });
    $blocks = asTenant($ctx['tenant_id'], fn (): array => app(WorkQueues::class)->blocks($user->id));

    expect(count($blocks))->toBe(count(array_unique(array_merge(...array_values(WorkQueues::BY_ROLE)))));
    foreach ($blocks as $block) {
        expect(preg_match_all('/[.!?](\s|$)/', (string) $block['empty']))->toBe(1, "{$block['key']}: {$block['empty']}")
            ->and($block['emptyAction']['label'] ?? '')->not->toBe('')
            ->and((string) ($block['emptyAction']['href'] ?? ''))->toStartWith('/');
    }
    expect(collect($blocks)->firstWhere('key', 'unallocated_receipts'))->toMatchArray(['empty' => 'No unallocated receipts.', 'emptyAction' => ['label' => 'Import a bank statement', 'href' => '/bank']]);
});

it('tells the screens when the tenant still needs setting up, and who can do it', function (): void {
    $tenant = (new BlankTenantSeeder())->run('acme', 'Acme');
    $user = fn (array $roles): User => asTenant($tenant, function () use ($tenant, $roles): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $tenant, 'email' => $id.'@acme.test', 'name' => 'Person', 'password' => 'x', 'status' => 'active']);
        foreach ($roles as $code) {
            DB::table('user_roles')->insert(['tenant_id' => $tenant, 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $code)->value('id'), 'scope_type' => 'tenant', 'scope_id' => $tenant]);
        }

        return User::query()->findOrFail($id);
    });
    asTenant($tenant, fn () => app(App\Modules\Platform\Setup\SetupProgress::class)->complete('done', (string) Str::uuid7()));

    actingAs($user(['finance_manager']))->get('/home', ['X-Tenant' => $tenant])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('shell.onboarding.setupNeeded', true)->where('shell.onboarding.canSetup', true)->where('shell.onboarding.demoCommand', null));
    actingAs($user(['branch_officer']))->get('/home', ['X-Tenant' => $tenant])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('shell.onboarding.setupNeeded', true)->where('shell.onboarding.canSetup', false));
});
