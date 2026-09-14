<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GA-25: an installment added by an endorsement's premium increase carries the endorsement's number n, so it is labelled `<policy>/E<n>` (the endorsement
 * number of A-104) instead of the next installment number. Installments of the original premium keep it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installments', function (Blueprint $t): void {
            $t->unsignedSmallInteger('endorsement_no')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('installments', function (Blueprint $t): void {
            $t->dropColumn('endorsement_no');
        });
    }
};
