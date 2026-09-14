<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap fix GA-03 (DECISION D-65): a branch officer records the premium taken at the counter but does not allocate it (design §7.2 gives receipt.allocate
 * to the branch manager and the accountant). The receipt keeps the policy the money was taken for, so the money waits in suspense marked for that policy
 * and the branch manager allocates it from their Home queue. A note only: it posts nothing and allocates nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $t): void {
            $t->uuid('for_policy_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $t): void {
            $t->dropColumn('for_policy_id');
        });
    }
};
