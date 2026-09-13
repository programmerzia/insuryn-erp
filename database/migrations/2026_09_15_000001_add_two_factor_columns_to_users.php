<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fortify two-factor authentication columns (its published migration, dated after the users table exists). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->text('two_factor_secret')->nullable();
            $t->text('two_factor_recovery_codes')->nullable();
            $t->timestampTz('two_factor_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
