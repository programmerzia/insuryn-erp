<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Database\Seeders\ProductClassesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 design §1 product model extensions (slice R1): the global product_classes catalogue (D-18), the rating-related fields of
 * product_versions and the coverages table (tenant, forced RLS). The Phase 1 `product_versions.coverages` JSON stays as it is (D-19).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_classes', function (Blueprint $t): void {
            $t->string('code', 32)->primary();
            $t->string('name_en');
            $t->string('name_bn');
            $t->string('insurance_class');
            $t->string('status')->default('active');
            $t->unsignedSmallInteger('sort_order')->default(0);
        });
        DB::statement("ALTER TABLE product_classes ADD CONSTRAINT product_classes_status_valid CHECK (status IN ('active','later'))");
        DB::statement("ALTER TABLE product_classes ADD CONSTRAINT product_classes_insurance_class_valid CHECK (insurance_class IN ('life','non_life'))");
        (new ProductClassesSeeder())->run();

        Schema::table('product_versions', function (Blueprint $t): void {
            $t->string('class_code', 32)->nullable();
            $t->jsonb('risk_schema')->nullable();
            $t->jsonb('duty_profile')->nullable();
            $t->uuid('document_set_id')->nullable();
            $t->boolean('allow_short_period')->default(false);
            $t->bigInteger('min_premium_minor')->nullable();
            $t->string('recognise_at')->default('policy');
            $t->boolean('allow_credit_issue')->default(false);
            $t->foreign('class_code')->references('code')->on('product_classes');
        });
        DB::statement('ALTER TABLE product_versions ADD CONSTRAINT product_versions_min_premium_valid CHECK (min_premium_minor IS NULL OR min_premium_minor >= 0)');
        DB::statement("ALTER TABLE product_versions ADD CONSTRAINT product_versions_recognise_at_valid CHECK (recognise_at IN ('policy','cover_note'))");

        Schema::create('coverages', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('product_version_id')->index();
            $t->string('code', 64);
            $t->string('name_en');
            $t->string('name_bn');
            $t->boolean('mandatory')->default(false);
            $t->string('basis');
            $t->string('rating_rule_ref')->nullable();
            $t->jsonb('limit_rule')->nullable();
            $t->jsonb('deductible_rule')->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestampsTz();
            $t->unique(['product_version_id', 'code']);
            $t->foreign('product_version_id')->references('id')->on('product_versions');
        });
        DB::statement("ALTER TABLE coverages ADD CONSTRAINT coverages_basis_valid CHECK (basis IN ('sum_insured','flat','per_unit','pct_of_base'))");
        RowLevelSecurity::enable('coverages');
    }

    public function down(): void
    {
        Schema::dropIfExists('coverages');
        Schema::table('product_versions', function (Blueprint $t): void {
            $t->dropForeign(['class_code']);
            $t->dropColumn(['class_code', 'risk_schema', 'duty_profile', 'document_set_id', 'allow_short_period', 'min_premium_minor', 'recognise_at', 'allow_credit_issue']);
        });
        Schema::dropIfExists('product_classes');
    }
};
