<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GA-17: a customer's contact details — mobile (SMS renewal and claim updates, stored as +8801XXXXXXXXX), email, postal address (printed on the schedule),
 * national ID (NID) of an individual or business registration number (BRN) of an organisation, an individual's date of birth, and an organisation's contact
 * person. All optional in the database: existing parties have none, and which forms require the mobile is configuration (A-193). The table keeps its RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $t): void {
            $t->string('mobile', 16)->nullable();
            $t->string('email', 254)->nullable();
            $t->text('address')->nullable();
            $t->string('identity_no', 32)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('contact_person', 255)->nullable();
        });
        DB::statement("ALTER TABLE parties ADD CONSTRAINT parties_mobile_format CHECK (mobile IS NULL OR mobile ~ '^\\+[0-9]{8,15}$')");
        DB::statement("ALTER TABLE parties ADD CONSTRAINT parties_person_fields CHECK (kind = 'individual' OR date_of_birth IS NULL)");
        DB::statement('CREATE INDEX parties_mobile ON parties (tenant_id, mobile) WHERE mobile IS NOT NULL');
        DB::statement('CREATE INDEX parties_identity_no ON parties (tenant_id, identity_no) WHERE identity_no IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS parties_identity_no');
        DB::statement('DROP INDEX IF EXISTS parties_mobile');
        DB::statement('ALTER TABLE parties DROP CONSTRAINT IF EXISTS parties_person_fields');
        DB::statement('ALTER TABLE parties DROP CONSTRAINT IF EXISTS parties_mobile_format');
        Schema::table('parties', function (Blueprint $t): void {
            $t->dropColumn(['mobile', 'email', 'address', 'identity_no', 'date_of_birth', 'contact_person']);
        });
    }
};
