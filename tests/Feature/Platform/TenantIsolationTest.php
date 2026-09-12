<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Design §8.6.7. Two tenants; nothing crosses. Checks Eloquent scope, RLS via raw SQL as the app role,
 * and that no-context queries return nothing.
 */
beforeEach(function (): void {
    $this->a = seedDemoTenant('tenant-a');
    $this->b = seedDemoTenant('tenant-b');
});

it('Eloquent never sees another tenant', function (): void {
    $countA = asTenant($this->a['tenant_id'], fn () => Account::query()->count());
    $countB = asTenant($this->b['tenant_id'], fn () => Account::query()->count());
    expect($countA)->toBeGreaterThan(0)->and($countA)->toBe($countB);
    asTenant($this->a['tenant_id'], fn () => expect(Account::query()->whereKey($this->b['accounts']['bank_main'])->exists())->toBeFalse());
});

it('returns nothing with no tenant context (never all tenants)', function (): void {
    TenantContext::clear();
    expect(Account::query()->count())->toBe(0);
});

/** D-08: child tables carry tenant_id and RLS like every other tenant table. */
function seedChildRows(string $tenantId): void
{
    asTenant($tenantId, function () use ($tenantId): void {
        $id = fn (): string => (string) \Illuminate\Support\Str::uuid7();
        $roleId = $id(); $userId = $id(); $approvalId = $id(); $runId = $id(); $closeRunId = $id();
        DB::table('roles')->insert(['id' => $roleId, 'tenant_id' => $tenantId, 'code' => 'ACC', 'name' => 'Accountant']);
        DB::table('role_permissions')->insert(['tenant_id' => $tenantId, 'role_id' => $roleId, 'permission_code' => 'accounting.view_journals']);
        DB::table('users')->insert(['id' => $userId, 'tenant_id' => $tenantId, 'email' => "u{$userId}@example.test", 'name' => 'U']);
        DB::table('user_roles')->insert(['tenant_id' => $tenantId, 'user_id' => $userId, 'role_id' => $roleId, 'scope_type' => 'tenant', 'scope_id' => $tenantId]);
        DB::table('approvals')->insert(['id' => $approvalId, 'tenant_id' => $tenantId, 'object_type' => 'journal', 'object_id' => $id(), 'policy_id' => $id(), 'requested_by' => $userId, 'requested_at' => now()]);
        DB::table('approval_decisions')->insert(['id' => $id(), 'tenant_id' => $tenantId, 'approval_id' => $approvalId, 'step_no' => 1, 'decided_by' => $userId, 'decision' => 'approved', 'decided_at' => now()]);
        DB::table('reconciliation_runs')->insert(['id' => $runId, 'tenant_id' => $tenantId, 'entity_id' => $id(), 'subledger' => 'premium', 'period_id' => $id(), 'run_at' => now(),
            'subledger_balance_minor' => 1, 'gl_balance_minor' => 0, 'variance_minor' => 1, 'status' => 'variance']);
        DB::table('reconciliation_exceptions')->insert(['id' => $id(), 'tenant_id' => $tenantId, 'run_id' => $runId, 'object_type' => 'policy', 'object_id' => $id(), 'expected_minor' => 1, 'actual_minor' => 0]);
        DB::table('period_close_runs')->insert(['id' => $closeRunId, 'tenant_id' => $tenantId, 'entity_id' => $id(), 'period_id' => $id(), 'started_by' => $userId, 'started_at' => now()]);
        DB::table('period_close_tasks')->insert(['id' => $id(), 'tenant_id' => $tenantId, 'close_run_id' => $closeRunId, 'code' => 'tb', 'order_no' => 13, 'owner_role' => 'finance_manager']);
    });
}

it('RLS hides child-table rows of another tenant from the app role', function (): void {
    seedChildRows($this->a['tenant_id']);
    seedChildRows($this->b['tenant_id']);

    DB::statement('SET ROLE erp_app');
    try {
        asTenant($this->a['tenant_id'], function (): void {
            foreach (['role_permissions', 'user_roles', 'approval_decisions', 'reconciliation_exceptions', 'period_close_tasks'] as $table) {
                expect(DB::table($table)->count())->toBe(1, "table {$table} should show only tenant A's row")
                    ->and(DB::table($table)->where('tenant_id', $this->b['tenant_id'])->count())->toBe(0, "table {$table} leaked tenant B rows");
            }
        });
    } finally {
        DB::statement('RESET ROLE');
    }
});

it('RLS blocks raw SQL cross-tenant reads and writes when running as the app role', function (): void {
    // Owner bypasses RLS; switch to the runtime role for this test.
    DB::statement('SET ROLE erp_app');
    try {
        $rlsTables = ['accounts', 'journals', 'journal_lines', 'accounting_events', 'fiscal_periods', 'legal_entities', 'branches', 'users', 'audit_events', 'outbox'];
        asTenant($this->a['tenant_id'], function () use ($rlsTables): void {
            foreach ($rlsTables as $t) {
                $seen = DB::table($t)->where('tenant_id', $this->b['tenant_id'])->count();
                expect($seen)->toBe(0, "table {$t} leaked tenant B rows to tenant A");
            }
            // WITH CHECK: cannot insert a row claiming another tenant
            expect(fn () => DB::table('books')->insert(['id' => (string) \Illuminate\Support\Str::uuid7(), 'tenant_id' => $this->b['tenant_id'], 'code' => 'X', 'name' => 'x', 'is_primary' => false]))
                ->toThrow(\Illuminate\Database\QueryException::class);
        });
        TenantContext::clear();
        expect(DB::table('accounts')->count())->toBe(0);
    } finally {
        DB::statement('RESET ROLE');
    }
});
