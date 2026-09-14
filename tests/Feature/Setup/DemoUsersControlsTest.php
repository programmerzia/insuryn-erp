<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\travelTo;

/**
 * Gap fix GA-20: the Part A demo's admin held Tenant Admin together with Finance Manager and Claims Manager, breaking the seeded rule
 * platform.manage_roles ✕ accounting.* the demo is meant to show, and every demo role was given silently (no timeline entry).
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-13 10:00'));
});

/** @return array<string, list<array{code: string, scope: string}>> email → roles */
function ga20Roles(string $tenantId): array
{
    return asTenant($tenantId, function (): array {
        $roles = [];
        foreach (DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->join('users as u', 'u.id', '=', 'ur.user_id')->orderBy('u.email')->orderBy('r.code')
            ->get(['u.email', 'r.code', 'ur.scope_type']) as $row) {
            $roles[(string) $row->email][] = ['code' => (string) $row->code, 'scope' => (string) $row->scope_type];
        }

        return $roles;
    });
}

it('seeds the demo admin as Tenant Admin only, even locally, and records every demo role on the user\'s timeline', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    expect(Artisan::call('erp:demo'))->toBe(0);
    $tenantId = (string) DB::table('tenants')->where('slug', 'nonlife')->value('id');
    $roles = ga20Roles($tenantId);

    expect($roles['admin@nonlife.local'])->toBe([['code' => 'tenant_admin', 'scope' => 'tenant']])
        ->and($roles['branch.officer@nonlife.local'])->toBe([['code' => 'branch_officer', 'scope' => 'tenant']])
        ->and(asTenant($tenantId, fn (): int => DB::table('audit_events')->where('action', 'user_role.assigned')->where('reason', 'Part A demo')->count()))->toBe(8);

    // Seeding the admins again (db:seed after erp:demo) does not add the local browsing roles back to a tenant with its own finance manager.
    (new Database\Seeders\AdminUserSeeder())->run();
    expect(ga20Roles($tenantId)['admin@nonlife.local'])->toBe([['code' => 'tenant_admin', 'scope' => 'tenant']]);
});
