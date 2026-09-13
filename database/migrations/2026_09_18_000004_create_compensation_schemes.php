<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distribution design note §1 compensation_schemes and compensation_rules (slice D4). A scheme's mode says how producers under it are paid; its
 * compliance profile (allowed producer types, caps, whether non-life commission is allowed) is validated when rules are written and, in D5, when
 * commission is calculated. Product versions name the scheme that applies to their policies; hierarchy levels belong to a real scheme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compensation_schemes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('code');
            $t->string('name');
            $t->string('mode');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->jsonb('compliance_profile');
            $t->string('withholding_jurisdiction')->nullable();
            $t->string('withholding_tax_type')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });
        DB::statement("ALTER TABLE compensation_schemes ADD CONSTRAINT compensation_schemes_mode_valid CHECK (mode IN ('commission','salary_incentive','hybrid','none'))");
        DB::statement('ALTER TABLE compensation_schemes ADD CONSTRAINT compensation_schemes_dates_valid CHECK (effective_to IS NULL OR effective_to > effective_from)');
        DB::statement('ALTER TABLE compensation_schemes ADD CONSTRAINT compensation_schemes_withholding_pair CHECK ((withholding_jurisdiction IS NULL) = (withholding_tax_type IS NULL))');

        Schema::create('compensation_rules', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('scheme_id')->index();
            $t->uuid('product_id')->nullable();
            $t->string('producer_type')->nullable();
            $t->string('level_code', 32)->nullable();
            $t->string('basis');
            $t->unsignedSmallInteger('policy_year_from');
            $t->unsignedSmallInteger('policy_year_to');
            $t->unsignedInteger('rate_bp')->default(0);
            $t->unsignedInteger('override_rate_bp')->default(0);
            $t->unsignedInteger('cap_bp')->nullable();
            $t->unsignedInteger('min_persistency_bp')->nullable();
            $t->boolean('renewal_requires_valid_licence')->default(true);
            $t->boolean('pays_after_termination')->default(false);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->timestampsTz();
            $t->foreign('scheme_id')->references('id')->on('compensation_schemes');
        });
        DB::statement("ALTER TABLE compensation_rules ADD CONSTRAINT compensation_rules_basis_valid CHECK (basis IN ('premium_received','premium_written','net_premium'))");
        DB::statement('ALTER TABLE compensation_rules ADD CONSTRAINT compensation_rules_years_valid CHECK (policy_year_from >= 1 AND policy_year_to >= policy_year_from AND policy_year_to <= 99)');
        DB::statement('ALTER TABLE compensation_rules ADD CONSTRAINT compensation_rules_rates_valid CHECK (rate_bp <= 10000 AND override_rate_bp <= 10000 AND (cap_bp IS NULL OR cap_bp <= 10000) AND (min_persistency_bp IS NULL OR min_persistency_bp <= 10000))');
        DB::statement('ALTER TABLE compensation_rules ADD CONSTRAINT compensation_rules_dates_valid CHECK (effective_to IS NULL OR effective_to > effective_from)');
        DB::statement("ALTER TABLE compensation_rules ADD CONSTRAINT compensation_rules_type_valid CHECK (producer_type IS NULL OR producer_type IN ('agent','agency_org','bdo','broker','partner'))");

        RowLevelSecurity::enable('compensation_schemes');
        RowLevelSecurity::enable('compensation_rules');

        Schema::table('hierarchy_levels', fn (Blueprint $t) => $t->foreign('scheme_id')->references('id')->on('compensation_schemes'));
        Schema::table('product_versions', function (Blueprint $t): void {
            $t->uuid('compensation_scheme_id')->nullable();
            $t->foreign('compensation_scheme_id')->references('id')->on('compensation_schemes');
        });
    }

    public function down(): void
    {
        Schema::table('product_versions', function (Blueprint $t): void {
            $t->dropForeign(['compensation_scheme_id']);
            $t->dropColumn('compensation_scheme_id');
        });
        Schema::table('hierarchy_levels', fn (Blueprint $t) => $t->dropForeign(['scheme_id']));
        Schema::dropIfExists('compensation_rules');
        Schema::dropIfExists('compensation_schemes');
    }
};
