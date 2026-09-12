<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Design §2.4 products / product_versions (slice 1A.2). Versions of one product never overlap in time. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('code');
            $t->string('name');
            $t->string('lob');
            $t->string('status')->default('active');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('product_versions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('product_id');
            $t->unsignedInteger('version');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->unsignedSmallInteger('term_months');
            $t->string('earning_method');
            $t->jsonb('short_rate_table')->nullable();
            $t->jsonb('tax_profile');
            $t->uuid('commission_plan_id')->nullable();
            $t->string('posting_rule_set')->nullable();
            $t->jsonb('coverages');
            $t->timestampsTz();
            $t->unique(['product_id', 'version']);
        });
        DB::statement("ALTER TABLE product_versions ADD CONSTRAINT product_versions_earning_method_valid CHECK (earning_method IN ('daily_365','monthly','24ths'))");
        DB::statement('ALTER TABLE product_versions ADD CONSTRAINT product_versions_range_valid CHECK (effective_to IS NULL OR effective_to > effective_from)');
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement("ALTER TABLE product_versions ADD CONSTRAINT product_versions_no_overlap EXCLUDE USING gist (product_id WITH =, daterange(effective_from, effective_to, '[)') WITH &&)");

        RowLevelSecurity::enable('products');
        RowLevelSecurity::enable('product_versions');
        DB::table('permissions')->insertOrIgnore([['code' => 'product.manage', 'description' => 'Create products and product versions']]);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_versions');
        Schema::dropIfExists('products');
        DB::table('permissions')->where('code', 'product.manage')->delete();
    }
};
