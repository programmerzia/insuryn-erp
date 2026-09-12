<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Design §7.3 distinguishes conflicts nobody may hold ("receipt.refund_request ✕ receipt.refund_release")
 * from conflicts on the same object ("claim.reserve ✕ claim.approve (same claim)"). The design's own role
 * templates combine the latter (Claims Manager), so they are checked per object, not at role assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sod_rules', fn (Blueprint $t) => $t->string('applies_to')->default('user'));
        DB::statement("ALTER TABLE sod_rules ADD CONSTRAINT sod_rules_applies_to_valid CHECK (applies_to IN ('user','object'))");
        DB::statement("ALTER TABLE sod_rules ADD CONSTRAINT sod_rules_mode_valid CHECK (mode IN ('block','warn'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sod_rules DROP CONSTRAINT IF EXISTS sod_rules_mode_valid');
        DB::statement('ALTER TABLE sod_rules DROP CONSTRAINT IF EXISTS sod_rules_applies_to_valid');
        Schema::table('sod_rules', fn (Blueprint $t) => $t->dropColumn('applies_to'));
    }
};
