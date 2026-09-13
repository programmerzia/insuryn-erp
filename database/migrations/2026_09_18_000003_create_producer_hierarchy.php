<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distribution design note §1 producer_hierarchy and hierarchy_levels (slice D3). A row is a producer's parent and level over [effective_from,
 * effective_to) — the end is exclusive, open when null. INVARIANT one active parent: an exclusion constraint forbids overlapping rows per producer.
 * INVARIANT no cycles: HierarchyService checks every date from the change onwards. The Phase 1 `producers.parent_agent_id` moves here (one row per
 * producer from the day it joined) and is dropped, so the dated hierarchy is the only source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('hierarchy_levels', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('scheme_id');
            $t->string('level_code', 32);
            $t->unsignedSmallInteger('rank');
            $t->string('label');
            $t->timestampsTz();
            $t->unique(['scheme_id', 'level_code']);
            $t->unique(['scheme_id', 'rank']);
        });
        DB::statement('ALTER TABLE hierarchy_levels ADD CONSTRAINT hierarchy_levels_rank_positive CHECK (rank > 0)');

        Schema::create('producer_hierarchy', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('producer_id');
            $t->uuid('parent_producer_id')->nullable()->index();
            $t->string('level_code', 32)->nullable();
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->timestampsTz();
            $t->index(['producer_id', 'effective_from']);
            $t->foreign('producer_id')->references('id')->on('producers');
            $t->foreign('parent_producer_id')->references('id')->on('producers');
        });
        DB::statement('ALTER TABLE producer_hierarchy ADD CONSTRAINT producer_hierarchy_not_own_parent CHECK (parent_producer_id IS DISTINCT FROM producer_id)');
        DB::statement('ALTER TABLE producer_hierarchy ADD CONSTRAINT producer_hierarchy_dates_valid CHECK (effective_to IS NULL OR effective_to > effective_from)');
        DB::statement("ALTER TABLE producer_hierarchy ADD CONSTRAINT producer_hierarchy_one_parent_at_a_time
            EXCLUDE USING gist (producer_id WITH =, daterange(effective_from, effective_to, '[)') WITH &&)");

        RowLevelSecurity::enable('hierarchy_levels');
        RowLevelSecurity::enable('producer_hierarchy');

        RowLevelSecurity::forEachTenant(fn (string $tenantId) => DB::insert(
            "INSERT INTO producer_hierarchy (id, tenant_id, producer_id, parent_producer_id, level_code, effective_from, created_at, updated_at)
             SELECT gen_random_uuid(), tenant_id, id, parent_agent_id, NULL, coalesce(joined_on, (created_at AT TIME ZONE 'UTC')::date), now(), now()
             FROM producers WHERE tenant_id = ?", [$tenantId]));

        DB::statement('ALTER TABLE producers DROP CONSTRAINT IF EXISTS producers_not_own_parent');
        Schema::table('producers', fn (Blueprint $t) => $t->dropColumn('parent_agent_id'));
    }

    public function down(): void
    {
        Schema::table('producers', fn (Blueprint $t) => $t->uuid('parent_agent_id')->nullable()->index());
        RowLevelSecurity::forEachTenant(fn (string $tenantId) => DB::update(
            "UPDATE producers p SET parent_agent_id = h.parent_producer_id FROM producer_hierarchy h
             WHERE h.producer_id = p.id AND p.tenant_id = ? AND h.effective_from <= current_date AND (h.effective_to IS NULL OR h.effective_to > current_date)", [$tenantId]));
        Schema::dropIfExists('producer_hierarchy');
        Schema::dropIfExists('hierarchy_levels');
    }
};
