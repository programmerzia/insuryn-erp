<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Session S1 (market cross-check G9): which setup wizard steps a tenant has saved, by whom and when. The wizard is re-openable, so a step saves again. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setup_progress', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('step', 32); // company|fiscal_year|chart_of_accounts|product|users|done
            $t->uuid('completed_by')->nullable();
            $t->timestampTz('completed_at');
            $t->unique(['tenant_id', 'step']);
        });
        RowLevelSecurity::enable('setup_progress');
    }

    public function down(): void
    {
        Schema::dropIfExists('setup_progress');
    }
};
