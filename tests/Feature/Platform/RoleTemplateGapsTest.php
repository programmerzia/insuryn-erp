<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Platform\Authorization\RoleTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Fix G3 (flow audit): the finance manager files the regulatory returns (Part A step 14), so the finance manager and CFO templates hold reports.regulatory;
 * the branch manager template holds policy.endorse (A-137) and the accountant commission.pay (A-138, tested with the payout in CommissionPayoutTest), which no
 * template held. Existing tenants get them through a migration.
 */
beforeEach(function (): void {
    $this->withoutVite();
});

/** @return list<string> role codes of the tenant holding $permission, sorted */
function g3RolesHolding(string $permission): array
{
    return array_values(array_map(fn (mixed $code): string => (string) $code, DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')
        ->where('rp.permission_code', $permission)->orderBy('r.code')->pluck('r.code')->all()));
}

it('gives reports.regulatory to the finance manager and CFO, and policy.endorse to the branch manager only', function (): void {
    $templates = RoleTemplates::all();

    expect($templates['finance_manager']['permissions'])->toContain('reports.regulatory')
        ->and($templates['cfo']['permissions'])->toContain('reports.regulatory')
        ->and($templates['branch_manager']['permissions'])->toContain('policy.endorse')
        ->and(array_keys(array_filter($templates, fn (array $t): bool => in_array('policy.endorse', $t['permissions'], true))))->toBe(['branch_manager'])
        ->and(array_keys(array_filter($templates, fn (array $t): bool => in_array('reports.regulatory', $t['permissions'], true))))->toBe(['finance_manager', 'cfo', RoleTemplates::AUDITOR]);
});

it('adds the permissions to the template roles of existing tenants, safely rerun', function (): void {
    $ctx = seedDemoTenant();
    seedRoleTemplates($ctx['tenant_id']);
    asTenant($ctx['tenant_id'], fn () => DB::table('role_permissions')->whereIn('permission_code', ['reports.regulatory', 'policy.endorse', 'commission.pay'])
        ->whereIn('role_id', DB::table('roles')->whereIn('code', ['finance_manager', 'cfo', 'branch_manager', 'accountant'])->select('id'))->delete());
    expect(asTenant($ctx['tenant_id'], fn (): array => [g3RolesHolding('reports.regulatory'), g3RolesHolding('policy.endorse'), g3RolesHolding('commission.pay')]))->toBe([['auditor'], [], []]);

    $migration = require database_path('migrations/2026_09_30_000043_grant_role_template_permission_gaps.php');
    $migration->up();
    $migration->up();

    expect(asTenant($ctx['tenant_id'], fn (): array => [g3RolesHolding('reports.regulatory'), g3RolesHolding('policy.endorse'), g3RolesHolding('commission.pay')]))
        ->toBe([['auditor', 'cfo', 'finance_manager'], ['branch_manager'], ['accountant']]);
});

it('lets the demo finance manager export the agency register and the demo branch manager endorse a policy', function (): void {
    travelTo(CarbonImmutable::parse('2026-09-13 10:00'));
    expect(Artisan::call('erp:demo'))->toBe(0);
    $tenantId = (string) DB::table('tenants')->where('slug', 'nonlife')->value('id');
    $headers = ['X-Tenant' => $tenantId];
    $user = fn (string $email): User => asTenant($tenantId, fn (): User => User::query()->where('email', $email)->firstOrFail());
    $finance = $user('finance.manager@nonlife.local');
    $manager = $user('branch.manager@nonlife.local');

    actingAs($finance)->get('/distribution/producers', $headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('can.export_register', true));
    actingAs($finance)->get('/distribution/licences/register?format=csv&as_of=2026-09-13', $headers)->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $policy = asTenant($tenantId, fn (): object => DB::table('policies')->whereNotNull(DB::raw("risk_inputs->>'seats'"))->whereIn('status', ['issued', 'active'])->orderBy('number')->firstOrFail());
    $inputs = (array) json_decode((string) $policy->risk_inputs, true);
    actingAs($manager)->get("/policies/{$policy->id}", $headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('actions.endorse_risk', true));
    actingAs($manager)->post("/policies/{$policy->id}/endorse-risk", ['effective_date' => '2026-09-20', 'risk_inputs' => [...$inputs, 'seats' => (int) $inputs['seats'] + 1],
        'reason' => 'Extra seats fitted'], $headers)->assertSessionHasNoErrors()->assertRedirect("/policies/{$policy->id}?tab=rating");
    expect(asTenant($tenantId, fn (): int => (int) DB::table('policies')->where('id', $policy->id)->value('version')))->toBe(2);
});
