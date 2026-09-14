<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Platform\Authorization\AuthorizationScope;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Numbering\DocumentNumberer;
use App\Modules\Platform\Numbering\DocumentNumberScope;
use App\Modules\Platform\Numbering\VoidDocumentNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/** Design §7.1 permission catalogue, §7.2 role scope (tenant | entity | branch), Laravel Gate integration. */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->otherEntity = asTenant($this->ctx['tenant_id'], function (): string {
        $id = (string) Str::uuid7();
        DB::table('legal_entities')->insert(['id' => $id, 'tenant_id' => $this->ctx['tenant_id'], 'code' => 'OTHER', 'name' => 'Other Ltd', 'base_currency' => 'BDT', 'status' => 'active']);

        return $id;
    });
});

it('grants a tenant-wide role everywhere, an entity role within its entity and branches, a branch role only in its branch', function (): void {
    $tenantWide = userWithPermissions($this->ctx['tenant_id'], ['policy.issue']);
    $entityScoped = userWithPermissions($this->ctx['tenant_id'], ['policy.issue'], 'entity', $this->ctx['entity_id']);
    $branchScoped = userWithPermissions($this->ctx['tenant_id'], ['policy.issue'], 'branch', $this->ctx['branch_id']);

    asTenant($this->ctx['tenant_id'], function () use ($tenantWide, $entityScoped, $branchScoped): void {
        $checker = app(PermissionChecker::class);
        $homeBranch = AuthorizationScope::branch($this->ctx['entity_id'], $this->ctx['branch_id']);
        $otherBranch = AuthorizationScope::branch($this->ctx['entity_id'], (string) Str::uuid7());
        $otherEntity = AuthorizationScope::entity($this->otherEntity);

        expect($checker->has($tenantWide, 'policy.issue', $otherEntity))->toBeTrue()
            ->and($checker->has($entityScoped, 'policy.issue', $homeBranch))->toBeTrue()
            ->and($checker->has($entityScoped, 'policy.issue', $otherBranch))->toBeTrue()
            ->and($checker->has($entityScoped, 'policy.issue', $otherEntity))->toBeFalse()
            ->and($checker->has($branchScoped, 'policy.issue', $homeBranch))->toBeTrue()
            ->and($checker->has($branchScoped, 'policy.issue', $otherBranch))->toBeFalse()
            ->and($checker->has($branchScoped, 'policy.issue', AuthorizationScope::entity($this->ctx['entity_id'])))->toBeFalse()
            ->and($checker->has($tenantWide, 'policy.cancel'))->toBeFalse();
    });
});

it('answers Gate checks and can: middleware for catalogue permissions', function (): void {
    $viewerId = userWithPermissions($this->ctx['tenant_id'], ['accounting.view_journals']);
    $viewer = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail($viewerId));
    $stranger = asTenant($this->ctx['tenant_id'], fn (): User => User::factory()->create());
    Route::middleware(['web', 'auth', 'can:accounting.view_journals'])->get('/_test/journals', fn (): array => ['ok' => true]);

    asTenant($this->ctx['tenant_id'], function () use ($viewer, $stranger): void {
        expect(Gate::forUser($viewer)->allows('accounting.view_journals'))->toBeTrue()
            ->and(Gate::forUser($stranger)->allows('accounting.view_journals'))->toBeFalse()
            ->and(Gate::forUser($viewer)->allows('accounting.approve_journal'))->toBeFalse();
    });

    actingAs($viewer)->getJson('/_test/journals', ['X-Tenant' => $this->ctx['tenant_id']])->assertOk();
    actingAs($stranger)->getJson('/_test/journals', ['X-Tenant' => $this->ctx['tenant_id']])->assertForbidden();
});

it('seeds the design role templates per tenant', function (): void {
    seedRoleTemplates($this->ctx['tenant_id']);

    asTenant($this->ctx['tenant_id'], function (): void {
        $permissionsOf = fn (string $role): array => DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->where('r.code', $role)->orderBy('rp.permission_code')->pluck('rp.permission_code')->all();

        expect(DB::table('roles')->orderBy('code')->pluck('code')->all())->toBe([
            'accountant', 'auditor', 'branch_manager', 'branch_officer', 'cfo', 'claims_manager', 'claims_officer', 'finance_manager', 'tenant_admin',
        ])
            ->and($permissionsOf('auditor'))->toBe(['accounting.view_journals', 'audit.view', 'reports.financial', 'reports.regulatory'])
            ->and($permissionsOf('finance_manager'))->toContain('accounting.approve_journal', 'accounting.create_manual_journal', 'periods.lock')
            ->and($permissionsOf('cfo'))->toContain('periods.reopen', 'accounting.post_to_control', 'accounting.approve_journal')
            // Fix F3 (A-54): the Tenant Admin template also sets approval limits; slice R8 (A-101): and manages document templates; slice R5 (A-87): and underwriting limits.
            ->and($permissionsOf('tenant_admin'))->toBe(['document.manage_templates', 'platform.manage_approvals', 'platform.manage_roles', 'platform.manage_users', 'underwriting.manage_limits'])
            ->and($permissionsOf('branch_officer'))->toBe(['cover_note.issue', 'document.generate', 'party.manage', 'policy.create', 'policy.issue', 'quotation.create', 'receipt.create', 'renewal.manage']);
    });
});

it('voids a document number only for users holding numbering.void, and audits it', function (): void {
    $voider = userWithPermissions($this->ctx['tenant_id'], ['numbering.void']);
    $clerk = userWithPermissions($this->ctx['tenant_id'], ['receipt.create']);

    asTenant($this->ctx['tenant_id'], function () use ($voider, $clerk): void {
        $scope = new DocumentNumberScope($this->ctx['entity_id'], $this->ctx['branch_id'], 'receipt', 'RCT', CarbonImmutable::parse('2026-09-15'));
        $number = app(DocumentNumberer::class)->reserve($scope, $clerk);

        expect(thrownBy(fn () => app(VoidDocumentNumber::class)($number->id, 'spoiled', $clerk), PermissionDenied::class)->permission)->toBe('numbering.void');

        app(VoidDocumentNumber::class)($number->id, 'spoiled', $voider);

        $audit = DB::table('audit_events')->where('object_id', $number->id)->first();
        expect(DB::table('document_numbers')->where('id', $number->id)->value('status'))->toBe('voided')
            ->and($audit?->action)->toBe('document_number.voided')
            ->and($audit?->permission)->toBe('numbering.void')
            ->and($audit?->actor_user_id)->toBe($voider);
    });
});
