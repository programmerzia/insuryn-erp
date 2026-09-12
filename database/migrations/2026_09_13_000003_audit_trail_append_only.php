<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Design §2.1: audit_events is append-only — enforced by the database. `permission` records the
 * permission exercised so segregation-of-duties checks can read maker/checker history (§7.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $t): void {
            $t->string('permission')->nullable();
            $t->index(['object_type', 'object_id', 'actor_user_id']);
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_audit_events() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'AUDIT_APPEND_ONLY: audit event % cannot be %', OLD.id, lower(TG_OP) USING ERRCODE = 'check_violation';
END $$;

CREATE TRIGGER audit_events_append_only BEFORE UPDATE OR DELETE ON audit_events
  FOR EACH ROW EXECUTE FUNCTION protect_audit_events();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_append_only ON audit_events; DROP FUNCTION IF EXISTS protect_audit_events;');
        Schema::table('audit_events', function (Blueprint $t): void {
            $t->dropIndex(['object_type', 'object_id', 'actor_user_id']);
            $t->dropColumn('permission');
        });
    }
};
