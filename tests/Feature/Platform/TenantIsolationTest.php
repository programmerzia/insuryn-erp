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
