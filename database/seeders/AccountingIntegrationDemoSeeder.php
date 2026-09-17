<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Accounting\Application\Integration\ExternalEventValidator;
use App\Modules\Accounting\Application\Integration\SubmitExternalEvent;
use App\Modules\Platform\Administration\IntegrationAccounts;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Ledger API demo data for erp:demo: an integration user and a few posted external events (KES) so
 * Accounting → Events shows recent API posts and the Ledger API demo has a real integration account.
 */
final class AccountingIntegrationDemoSeeder
{
    private const INTEGRATION_EMAIL = 'integration@nonlife.local';

    public function run(string $entityId, string $branchId, string $currency): void
    {
        $this->ensureIntegrationUser();
        $submit = app(SubmitExternalEvent::class);
        $validator = app(ExternalEventValidator::class);
        $date = CarbonImmutable::parse('2026-09-14');
        // What a customer system sends: the branch and product CODES and its own policy/customer/agent references. The API boundary
        // (ExternalEventValidator) turns codes into ids and references into stable uuids with `<name>_ref` kept for drill-down.
        $branchCode = (string) (DB::table('branches')->where('id', $branchId)->value('code') ?? 'HO');
        $productCode = (string) (DB::table('products')->where('code', 'MOTOR')->value('code') ?? DB::table('products')->orderBy('code')->value('code') ?? 'MOTOR');
        $dims = fn (string $policy, string $customer): array => [
            'branch' => $branchCode, 'product' => $productCode, 'product_code' => $productCode, 'lob' => 'motor', 'channel' => 'agent',
            'policy' => $policy, 'customer' => $customer, 'agent' => 'AGT-API-7',
        ];

        $samples = [
            ['PREMIUM_RECEIVED', 'PREMIUM_RECEIVED:DEMO-API-001', ['amount' => 5_000_000], $dims('POL-API-2026-0001', 'CUST-API-0001'), ['type' => 'receipt', 'id' => 'RCT-API-001', 'number' => 'RCT-HO-2026-API-001']],
            ['BANK_CHARGE', 'BANK_CHARGE:DEMO-API-001', ['amount' => 25_000], ['branch' => $branchCode], ['type' => 'bank_fee', 'id' => 'MPESA-SEP-001', 'number' => 'MPESA-FEE-001']],
            ['PREMIUM_RECEIVED', 'PREMIUM_RECEIVED:DEMO-API-002', ['amount' => 1_250_000], $dims('POL-API-2026-0002', 'CUST-API-0002'), ['type' => 'receipt', 'id' => 'RCT-API-002', 'number' => 'RCT-HO-2026-API-002']],
        ];

        foreach ($samples as [$type, $key, $payload, $dimensions, $source]) {
            $validated = $validator->validate($entityId, $type, $date, $date, $currency, $payload, $dimensions);
            $submit->submit($entityId, $type, $key, $date, $date, $currency, $payload, $validated->dimensions, $source, null, true);
        }
    }

    private function ensureIntegrationUser(): void
    {
        if (User::query()->whereRaw('lower(email) = ?', [self::INTEGRATION_EMAIL])->exists()) {
            return;
        }
        $tenantId = TenantContext::id();
        $userId = (string) Str::uuid7();
        DB::table('users')->insert([
            'id' => $userId, 'tenant_id' => $tenantId, 'email' => self::INTEGRATION_EMAIL, 'name' => 'Customer API (demo)',
            'password' => Hash::make((string) config('erp.seed.admin_password', 'ChangeMe123!')), 'status' => 'active', 'kind' => 'integration',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('roles')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => IntegrationAccounts::ROLE, 'name' => 'Ledger integration', 'created_at' => now(), 'updated_at' => now()]);
        $roleId = (string) DB::table('roles')->where('code', IntegrationAccounts::ROLE)->value('id');
        DB::table('user_roles')->insert(['tenant_id' => $tenantId, 'user_id' => $userId, 'role_id' => $roleId, 'scope_type' => 'tenant', 'scope_id' => $tenantId]);
    }
}
