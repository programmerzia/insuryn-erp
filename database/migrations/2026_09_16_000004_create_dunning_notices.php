<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Slice 1C.4 (spec §4 dunning, grace, auto-lapse): reminder notices per installment and level; reinstatement date for a fresh grace period. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dunning_notices', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('policy_id')->index();
            $t->uuid('installment_id');
            $t->unsignedSmallInteger('level');
            $t->unsignedInteger('days_overdue');
            $t->bigInteger('outstanding_minor');
            $t->date('issued_on');
            $t->timestampTz('created_at');
            $t->unique(['installment_id', 'level']);
        });
        RowLevelSecurity::enable('dunning_notices');

        Schema::table('policies', function (Blueprint $t): void {
            $t->date('reinstated_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('policies', fn (Blueprint $t) => $t->dropColumn('reinstated_on'));
        Schema::dropIfExists('dunning_notices');
    }
};
