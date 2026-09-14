<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 2.1b (D-54, CQ-H2): the business clock follows the legal entity's time zone. Existing entities take their tenant's `timezone`
 * (Asia/Dhaka unless it was changed), so no business date moves for a tenant that had set one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_entities', function (Blueprint $t): void {
            $t->string('timezone')->default('Asia/Dhaka');
        });
        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            $zone = DB::table('tenants')->where('id', $tenantId)->value('timezone');
            if (is_string($zone) && in_array($zone, timezone_identifiers_list(), true)) {
                DB::table('legal_entities')->where('tenant_id', $tenantId)->update(['timezone' => $zone]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('legal_entities', fn (Blueprint $t) => $t->dropColumn('timezone'));
    }
};
