<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gap fix GA-21 (DECISION D-88): a claim recovery is money coming in, so it is receipted like any inflow — a recovery receipt number (`RCV-<branch>-<fy>-n`),
 * the payer and the bank account it was paid into. Recoveries recorded before keep no number or payer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claim_recoveries', function (Blueprint $t): void {
            $t->string('number', 64)->nullable();
            $t->uuid('payer_party_id')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX claim_recoveries_tenant_number_unique ON claim_recoveries (tenant_id, number) WHERE number IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS claim_recoveries_tenant_number_unique');
        Schema::table('claim_recoveries', function (Blueprint $t): void {
            $t->dropColumn(['number', 'payer_party_id']);
        });
    }
};
