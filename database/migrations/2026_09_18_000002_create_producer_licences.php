<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distribution design note §1 producer_licences and §3 licence rules (slice D2): licences per producer by authority and class, the alerts
 * raised before expiry (one row per licence and threshold, so reruns are no-ops), and the products' insurance class that a licence must cover.
 * ASSUMPTION A-17: existing products are life when their line of business is listed in erp.products.life_lobs (default: life), otherwise non-life.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->string('insurance_class')->default('non_life'));
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_insurance_class_valid CHECK (insurance_class IN ('life','non_life'))");
        $lifeLobs = array_values(array_map('strval', (array) config('erp.products.life_lobs', ['life'])));
        RowLevelSecurity::forEachTenant(fn () => DB::table('products')->whereIn(DB::raw('lower(lob)'), array_map('strtolower', $lifeLobs))->update(['insurance_class' => 'life']));

        Schema::create('producer_licences', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('producer_id')->index();
            $t->string('authority')->default('IDRA');
            $t->string('licence_no');
            $t->string('class');
            $t->date('issued_on');
            $t->date('expires_on');
            $t->uuid('document_id')->nullable();
            $t->string('status')->default('active');
            $t->text('status_reason')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'authority', 'licence_no']);
            $t->foreign('producer_id')->references('id')->on('producers');
        });
        DB::statement("ALTER TABLE producer_licences ADD CONSTRAINT producer_licences_class_valid CHECK (class IN ('life','non_life','both'))");
        DB::statement("ALTER TABLE producer_licences ADD CONSTRAINT producer_licences_status_valid CHECK (status IN ('active','suspended','revoked'))");
        DB::statement('ALTER TABLE producer_licences ADD CONSTRAINT producer_licences_dates_valid CHECK (expires_on >= issued_on)');

        Schema::create('producer_licence_alerts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('licence_id');
            $t->unsignedSmallInteger('days_before');
            $t->date('raised_on');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['licence_id', 'days_before']);
            $t->foreign('licence_id')->references('id')->on('producer_licences')->cascadeOnDelete();
        });

        RowLevelSecurity::enable('producer_licences');
        RowLevelSecurity::enable('producer_licence_alerts');
    }

    public function down(): void
    {
        Schema::dropIfExists('producer_licence_alerts');
        Schema::dropIfExists('producer_licences');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_insurance_class_valid');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('insurance_class'));
    }
};
