<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Slice U2 (UX brief §4 "state remembered per user"): theme, density, sidebar, split widths, pinned tabs, table views, recents — one JSON document per user. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('user_id')->unique();
            $t->jsonb('preferences')->default('{}');
            $t->timestampsTz();
        });
        RowLevelSecurity::enable('user_preferences');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
