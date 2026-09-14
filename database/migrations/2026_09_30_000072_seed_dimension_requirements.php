<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Posting\TenantDimensionRequirements;
use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gap audit GA-47 (D-74, ASSUMPTION A-187): `dimension_requirements` was empty, so nothing required class, product or branch on premium and claim lines.
 * Existing tenants get the default rows (branch, product, lob on the policy, premium earning and claim events); rows a tenant already has are kept.
 * The posting engine enforces them from now on.
 */
return new class extends Migration
{
    public function up(): void
    {
        RowLevelSecurity::forEachTenant(fn (string $tenantId): int => TenantDimensionRequirements::seedCurrentTenant($tenantId));
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(function (): void {
            foreach (TenantDimensionRequirements::DEFAULTS as $eventType => $codes) {
                DB::table('dimension_requirements')->where('event_type', $eventType)->whereIn('dimension_code', $codes)->delete();
            }
        });
    }
};
