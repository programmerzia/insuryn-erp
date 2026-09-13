<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Slice 1C.5 (spec §4 multi-payer): payer shares per policy and the payer of each installment row. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_payers', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('policy_id')->index();
            $t->uuid('party_id');
            $t->unsignedSmallInteger('share_bp');
            $t->timestampTz('created_at');
            $t->unique(['policy_id', 'party_id']);
        });
        DB::statement('ALTER TABLE policy_payers ADD CONSTRAINT policy_payers_share_valid CHECK (share_bp BETWEEN 1 AND 10000)');
        RowLevelSecurity::enable('policy_payers');

        Schema::table('installments', function (Blueprint $t): void {
            $t->uuid('payer_party_id')->nullable();
        });
        DB::statement('UPDATE installments i SET payer_party_id = p.policyholder_party_id FROM policies p WHERE p.id = i.policy_id');
        DB::statement('ALTER TABLE installments ALTER COLUMN payer_party_id SET NOT NULL');
        Schema::table('installments', function (Blueprint $t): void {
            $t->dropUnique(['policy_id', 'no']);
            $t->unique(['policy_id', 'no', 'payer_party_id']);
            $t->index('payer_party_id');
        });
    }

    public function down(): void
    {
        Schema::table('installments', function (Blueprint $t): void {
            $t->dropIndex(['payer_party_id']);
            $t->dropUnique(['policy_id', 'no', 'payer_party_id']);
            $t->unique(['policy_id', 'no']);
            $t->dropColumn('payer_party_id');
        });
        Schema::dropIfExists('policy_payers');
    }
};
