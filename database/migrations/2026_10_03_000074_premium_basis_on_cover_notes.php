<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GA-28: a cover note is issued on the same basis as the policy (A-117): on credit when the product version allows credit issue, otherwise against a premium
 * the officer confirms was received, whose reference is kept on the cover note. Existing cover notes were issued before the rule and are marked `credit`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cover_notes', function (Blueprint $t): void {
            $t->string('issue_basis', 32)->default('credit');
            $t->string('premium_received_reference', 128)->nullable();
        });
        DB::statement("ALTER TABLE cover_notes ADD CONSTRAINT cover_notes_issue_basis_valid CHECK (issue_basis IN ('credit','premium_received') AND (issue_basis <> 'premium_received' OR premium_received_reference IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cover_notes DROP CONSTRAINT IF EXISTS cover_notes_issue_basis_valid');
        Schema::table('cover_notes', function (Blueprint $t): void {
            $t->dropColumn(['issue_basis', 'premium_received_reference']);
        });
    }
};
