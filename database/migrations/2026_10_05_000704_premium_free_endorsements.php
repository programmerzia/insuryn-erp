<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gap fixes W7 (GA-25 remainder): endorsements that change no premium — the insured's name, the address, the mortgagee, the contact details on the policy.
 * The policy keeps what an endorsement set in `insured_details` (only the keys that were endorsed; the rest still come from the customer), and the
 * endorsement transaction records its kind and the details before and after. No accounting event, no premium change (the deltas stay 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $t): void {
            $t->jsonb('insured_details')->nullable();
        });
        Schema::table('policy_transactions', function (Blueprint $t): void {
            $t->string('endorsement_kind', 16)->nullable();
            $t->jsonb('details_change')->nullable();
        });
        DB::statement("ALTER TABLE policy_transactions ADD CONSTRAINT policy_transactions_endorsement_kind_valid CHECK (endorsement_kind IS NULL OR (type = 'endorsement'
            AND endorsement_kind IN ('name', 'address', 'mortgagee', 'contact') AND premium_delta_minor = 0 AND net_delta_minor = 0 AND tax_delta_minor = 0 AND stamp_duty_delta_minor = 0
            AND details_change IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE policy_transactions DROP CONSTRAINT IF EXISTS policy_transactions_endorsement_kind_valid');
        Schema::table('policy_transactions', function (Blueprint $t): void {
            $t->dropColumn(['endorsement_kind', 'details_change']);
        });
        Schema::table('policies', function (Blueprint $t): void {
            $t->dropColumn('insured_details');
        });
    }
};
