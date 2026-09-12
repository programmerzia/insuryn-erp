<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Design §2.1 INVARIANT "every number is reserved/used/voided — no unexplained gap", enforced by the
 * database: numbers are positioned by sequence_no, statuses only move reserved → used | voided, and
 * rows can never be deleted or renumbered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_numbers', function (Blueprint $t): void {
            $t->unsignedBigInteger('sequence_no')->nullable();
            $t->uuid('voided_by')->nullable();
        });
        DB::statement("UPDATE document_numbers SET sequence_no = substring(number from '([0-9]+)$')::bigint WHERE sequence_no IS NULL");
        DB::statement('ALTER TABLE document_numbers ALTER COLUMN sequence_no SET NOT NULL');
        Schema::table('document_numbers', fn (Blueprint $t) => $t->unique(['sequence_id', 'sequence_no']));
        DB::statement("ALTER TABLE document_numbers ADD CONSTRAINT document_numbers_status_valid CHECK (status IN ('reserved','used','voided'))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_document_numbers() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    RAISE EXCEPTION 'IMMUTABLE_DOCUMENT_NUMBER: number % cannot be deleted', OLD.number USING ERRCODE = 'check_violation';
  END IF;
  IF NEW.id <> OLD.id OR NEW.tenant_id <> OLD.tenant_id OR NEW.sequence_id <> OLD.sequence_id
     OR NEW.sequence_no <> OLD.sequence_no OR NEW.number <> OLD.number OR NEW.reserved_at <> OLD.reserved_at
     OR NEW.reserved_by IS DISTINCT FROM OLD.reserved_by THEN
    RAISE EXCEPTION 'IMMUTABLE_DOCUMENT_NUMBER: number % cannot be renumbered', OLD.number USING ERRCODE = 'check_violation';
  END IF;
  IF OLD.status <> 'reserved' OR NEW.status NOT IN ('used', 'voided') THEN
    RAISE EXCEPTION 'IMMUTABLE_DOCUMENT_NUMBER: number % cannot move from % to %', OLD.number, OLD.status, NEW.status USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;

CREATE TRIGGER document_numbers_immutable BEFORE UPDATE OR DELETE ON document_numbers
  FOR EACH ROW EXECUTE FUNCTION protect_document_numbers();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS document_numbers_immutable ON document_numbers; DROP FUNCTION IF EXISTS protect_document_numbers;');
        DB::statement('ALTER TABLE document_numbers DROP CONSTRAINT IF EXISTS document_numbers_status_valid');
        Schema::table('document_numbers', function (Blueprint $t): void {
            $t->dropUnique(['sequence_id', 'sequence_no']);
            $t->dropColumn(['sequence_no', 'voided_by']);
        });
    }
};
