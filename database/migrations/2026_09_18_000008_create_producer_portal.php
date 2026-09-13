<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distribution design note §5 producer portal (slice D9). Sanctum personal access tokens, as a tenant table (CONTEXT.md #6) with UUID keys; users
 * gain a kind — `staff` sign in to the web app, `portal` only obtain API tokens — and a producer may be linked to its portal user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->string('kind')->default('staff'));
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_kind_valid CHECK (kind IN ('staff','portal'))");

        Schema::table('producers', function (Blueprint $t): void {
            $t->uuid('portal_user_id')->nullable()->unique();
            $t->foreign('portal_user_id')->references('id')->on('users');
        });

        Schema::create('personal_access_tokens', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuidMorphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestampTz('last_used_at')->nullable();
            $t->timestampTz('expires_at')->nullable()->index();
            $t->timestampsTz();
        });
        RowLevelSecurity::enable('personal_access_tokens');
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::table('producers', function (Blueprint $t): void {
            $t->dropForeign(['portal_user_id']);
            $t->dropColumn('portal_user_id');
        });
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_kind_valid');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('kind'));
    }
};
