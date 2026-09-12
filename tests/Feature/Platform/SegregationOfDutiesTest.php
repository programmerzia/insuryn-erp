<?php

declare(strict_types=1);

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\RoleAssignmentService;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Authorization\SodViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design §7.3 segregation of duties. (1) Role assignment blocks (or warns) when a user would hold both
 * sides of a user-level conflict. (2) At action time SodGuard reads the object's audit history, so the
 * same person is never maker and checker on one object — even with both permissions via two roles.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->admin = userWithPermissions($this->ctx['tenant_id'], ['platform.manage_users']);
});

function sodGuard(): SodGuard
{
    return app(SodGuard::class);
}

/** Record that $userId exercised $permission on the object (what every audited maker action does). */
function actedOn(string $userId, string $permission, AuditSubject $object): void
{
    app(Audit::class)->record('test.acted', $object, null, null, null, $permission, Actor::user($userId));
}

function roleId(string $code): string
{
    return (string) DB::table('roles')->where('code', $code)->value('id');
}

it('stops the maker of an object from also being its checker, for every design conflict', function (string $maker, string $checker, string $objectType): void {
    $userWithBoth = userWithPermissions($this->ctx['tenant_id'], [$maker, $checker]);
    $colleague = userWithPermissions($this->ctx['tenant_id'], [$checker]);

    asTenant($this->ctx['tenant_id'], function () use ($maker, $checker, $objectType, $userWithBoth, $colleague): void {
        $object = AuditSubject::of($objectType, (string) Str::uuid7());
        $otherObject = AuditSubject::of($objectType, (string) Str::uuid7());
        actedOn($userWithBoth, $maker, $object);

        $violation = thrownBy(fn () => sodGuard()->assert($userWithBoth, $checker, $object), SodViolation::class);

        expect($violation->permission)->toBe($checker)
            ->and($violation->conflictingPermission)->toBe($maker)
            ->and(sodGuard()->assert($colleague, $checker, $object))->toBe([])
            ->and(sodGuard()->assert($userWithBoth, $checker, $otherObject))->toBe([]);
    });
})->with([
    'refund request / release' => ['receipt.refund_request', 'receipt.refund_release', 'refund'],
    'claim pay request / release' => ['claim.pay_request', 'claim.pay_release', 'claim_payment'],
    'claim reserve / approve (same claim)' => ['claim.reserve', 'claim.approve', 'claim'],
    'manual journal create / approve (same journal)' => ['accounting.create_manual_journal', 'accounting.approve_journal', 'journal'],
    'commission approve / pay' => ['commission.approve', 'commission.pay', 'commission_statement'],
]);

it('applies conflicts in both directions', function (): void {
    $user = userWithPermissions($this->ctx['tenant_id'], ['receipt.refund_request', 'receipt.refund_release']);

    asTenant($this->ctx['tenant_id'], function () use ($user): void {
        $refund = AuditSubject::of('refund', (string) Str::uuid7());
        actedOn($user, 'receipt.refund_release', $refund);

        expect(fn () => sodGuard()->assert($user, 'receipt.refund_request', $refund))->toThrow(SodViolation::class);
    });
});

it('only warns, and audits the warning, when the conflicting rule is in warn mode', function (): void {
    $user = userWithPermissions($this->ctx['tenant_id'], ['commission.approve', 'commission.pay']);

    asTenant($this->ctx['tenant_id'], function () use ($user): void {
        DB::table('sod_rules')->where('permission_a', 'commission.approve')->update(['mode' => 'warn']);
        $statement = AuditSubject::of('commission_statement', (string) Str::uuid7());
        actedOn($user, 'commission.approve', $statement);

        $warnings = sodGuard()->assert($user, 'commission.pay', $statement);

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0]->conflictingPermission)->toBe('commission.approve')
            ->and(DB::table('audit_events')->where('action', 'sod.warning')->where('object_id', $statement->id)->where('actor_user_id', $user)->count())->toBe(1);
    });
});

it('blocks assigning a role that would give a user both sides of a user-level conflict', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $user = userWithPermissions($this->ctx['tenant_id'], ['receipt.refund_request']);
        $releaser = (string) Str::uuid7();
        DB::table('roles')->insert(['id' => $releaser, 'tenant_id' => $this->ctx['tenant_id'], 'code' => 'refund_releaser', 'name' => 'Refund releaser']);
        DB::table('role_permissions')->insert(['tenant_id' => $this->ctx['tenant_id'], 'role_id' => $releaser, 'permission_code' => 'receipt.refund_release']);

        $violation = thrownBy(fn () => app(RoleAssignmentService::class)->assign($user, $releaser, 'tenant', $this->ctx['tenant_id'], $this->admin), SodViolation::class);

        expect($violation->permission)->toBe('receipt.refund_release')
            ->and(DB::table('user_roles')->where('user_id', $user)->where('role_id', $releaser)->exists())->toBeFalse();
    });
});

it('lets object-level conflicts share a role template, because they are checked per object', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $user = userWithPermissions($this->ctx['tenant_id'], []);

        $warnings = app(RoleAssignmentService::class)->assign($user, roleId('claims_manager'), 'tenant', $this->ctx['tenant_id'], $this->admin);

        expect($warnings)->toBe([])
            ->and(DB::table('user_roles')->where('user_id', $user)->where('role_id', roleId('claims_manager'))->exists())->toBeTrue()
            ->and(DB::table('audit_events')->where('action', 'user_role.assigned')->where('object_id', $user)->value('permission'))->toBe('platform.manage_users');
    });
});

it('never lets role administrators hold accounting permissions', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $roleAdmin = userWithPermissions($this->ctx['tenant_id'], []);
        app(RoleAssignmentService::class)->assign($roleAdmin, roleId('tenant_admin'), 'tenant', $this->ctx['tenant_id'], $this->admin);

        $violation = thrownBy(fn () => app(RoleAssignmentService::class)->assign($roleAdmin, roleId('accountant'), 'tenant', $this->ctx['tenant_id'], $this->admin), SodViolation::class);

        expect($violation->conflictingPermission)->toBe('platform.manage_roles')
            ->and(str_starts_with($violation->permission, 'accounting.'))->toBeTrue();
    });
});

it('never combines the auditor role with write permissions', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $auditor = userWithPermissions($this->ctx['tenant_id'], []);
        app(RoleAssignmentService::class)->assign($auditor, roleId('auditor'), 'tenant', $this->ctx['tenant_id'], $this->admin);

        $violation = thrownBy(fn () => app(RoleAssignmentService::class)->assign($auditor, roleId('branch_officer'), 'tenant', $this->ctx['tenant_id'], $this->admin), SodViolation::class);
        expect($violation->reasonCode)->toBe('AUDITOR_WRITE_PERMISSION');

        $clerk = userWithPermissions($this->ctx['tenant_id'], ['receipt.create']);
        expect(fn () => app(RoleAssignmentService::class)->assign($clerk, roleId('auditor'), 'tenant', $this->ctx['tenant_id'], $this->admin))->toThrow(SodViolation::class);
    });
});

it('requires platform.manage_users to assign roles', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $user = userWithPermissions($this->ctx['tenant_id'], []);
        $notAdmin = userWithPermissions($this->ctx['tenant_id'], ['platform.manage_roles']);

        expect(fn () => app(RoleAssignmentService::class)->assign($user, roleId('auditor'), 'tenant', $this->ctx['tenant_id'], $notAdmin))
            ->toThrow(App\Modules\Platform\Authorization\PermissionDenied::class);
    });
});
