<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-level enforcement of INVARIANTs (design §2.2, §8.6). Belt-and-braces: the application
 * layer enforces the same rules; these triggers make it impossible to bypass via raw SQL.
 */
return new class extends Migration
{
    /** Tables carrying tenant_id that get RLS. Extend as modules are added. */
    private const TENANT_TABLES = [
        'legal_entities','branches','users','roles','sod_rules','approval_policies','approvals',
        'audit_events','number_sequences','document_numbers','tax_rates','outbox',
        'books','fiscal_periods','accounts','account_role_mappings','dimension_requirements',
        'accounting_events','journal_batches','journals','journal_lines','subledger_controls',
        'reconciliation_runs','period_close_runs',
    ];

    public function up(): void
    {
        // --- 1. Journal must balance per currency when (and only when) it becomes 'posted' ---
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION assert_journal_balanced() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
  bad_count int;
BEGIN
  IF NEW.status = 'posted' THEN
    SELECT count(*) INTO bad_count FROM (
      SELECT currency,
             sum(CASE WHEN side='debit'  THEN amount_minor ELSE 0 END) AS dr,
             sum(CASE WHEN side='credit' THEN amount_minor ELSE 0 END) AS cr
      FROM journal_lines WHERE journal_id = NEW.id GROUP BY currency
    ) s WHERE dr <> cr;
    IF bad_count > 0 THEN
      RAISE EXCEPTION 'UNBALANCED_JOURNAL: journal % has debits <> credits', NEW.id
        USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM journal_lines WHERE journal_id = NEW.id) THEN
      RAISE EXCEPTION 'EMPTY_JOURNAL: journal % has no lines', NEW.id USING ERRCODE = 'check_violation';
    END IF;
  END IF;
  RETURN NEW;
END $$;

CREATE CONSTRAINT TRIGGER journal_must_balance
  AFTER INSERT OR UPDATE OF status ON journals
  DEFERRABLE INITIALLY DEFERRED
  FOR EACH ROW EXECUTE FUNCTION assert_journal_balanced();
SQL);

        // --- 2. Posted journals are immutable (only status->reversed and reversed_by_journal_id may change) ---
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_posted_journal() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    IF OLD.status = 'posted' OR OLD.status = 'reversed' THEN
      RAISE EXCEPTION 'IMMUTABLE_JOURNAL: cannot delete posted journal %', OLD.id USING ERRCODE = 'check_violation';
    END IF;
    RETURN OLD;
  END IF;
  IF OLD.status IN ('posted','reversed') THEN
    IF NEW.status NOT IN ('posted','reversed') OR
       row_to_json(NEW)::jsonb - 'status' - 'reversed_by_journal_id' - 'updated_at'
       <> row_to_json(OLD)::jsonb - 'status' - 'reversed_by_journal_id' - 'updated_at' THEN
      RAISE EXCEPTION 'IMMUTABLE_JOURNAL: posted journal % cannot be modified', OLD.id USING ERRCODE = 'check_violation';
    END IF;
  END IF;
  RETURN NEW;
END $$;

CREATE TRIGGER journals_immutable BEFORE UPDATE OR DELETE ON journals
  FOR EACH ROW EXECUTE FUNCTION protect_posted_journal();

CREATE OR REPLACE FUNCTION protect_posted_journal_lines() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE jstatus text; jid uuid;
BEGIN
  jid := CASE WHEN TG_OP = 'DELETE' THEN OLD.journal_id ELSE NEW.journal_id END;
  SELECT status INTO jstatus FROM journals WHERE id = jid;
  IF jstatus IN ('posted','reversed') THEN
    RAISE EXCEPTION 'IMMUTABLE_JOURNAL: lines of posted journal % cannot change', jid USING ERRCODE = 'check_violation';
  END IF;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;

CREATE TRIGGER journal_lines_immutable BEFORE INSERT OR UPDATE OR DELETE ON journal_lines
  FOR EACH ROW EXECUTE FUNCTION protect_posted_journal_lines();
SQL);

        // --- 3. Locked periods reject postings ---
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION assert_period_open_for_posting() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE pstatus text;
BEGIN
  IF NEW.status = 'posted' AND (TG_OP = 'INSERT' OR OLD.status IS DISTINCT FROM 'posted') THEN
    SELECT status INTO pstatus FROM fiscal_periods WHERE id = NEW.period_id;
    IF pstatus IS NULL THEN
      RAISE EXCEPTION 'PERIOD_MISSING: journal % has no period', NEW.id USING ERRCODE = 'check_violation';
    END IF;
    IF pstatus = 'locked' THEN
      RAISE EXCEPTION 'PERIOD_CLOSED: period % is locked', NEW.period_id USING ERRCODE = 'check_violation';
    END IF;
    -- soft_locked is allowed at DB level; the application checks the permission.
  END IF;
  RETURN NEW;
END $$;

CREATE TRIGGER journals_period_open BEFORE INSERT OR UPDATE OF status ON journals
  FOR EACH ROW EXECUTE FUNCTION assert_period_open_for_posting();
SQL);

        // --- 4. Row-level security on every tenant table ---
        foreach (self::TENANT_TABLES as $table) {
            DB::unprepared(<<<SQL
ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tenant_isolation ON {$table};
CREATE POLICY tenant_isolation ON {$table}
  USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
  WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
SQL);
        }
        // Grant the runtime role access (owner runs migrations; app runs as erp_app).
        DB::unprepared('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO erp_app');
        // Framework tables (jobs, failed_jobs) use bigserial keys.
        DB::unprepared('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO erp_app');
    }

    public function down(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            DB::unprepared("DROP POLICY IF EXISTS tenant_isolation ON {$table}; ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS journals_period_open ON journals; DROP FUNCTION IF EXISTS assert_period_open_for_posting;');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_immutable ON journal_lines; DROP FUNCTION IF EXISTS protect_posted_journal_lines;');
        DB::unprepared('DROP TRIGGER IF EXISTS journals_immutable ON journals; DROP FUNCTION IF EXISTS protect_posted_journal;');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_must_balance ON journals; DROP FUNCTION IF EXISTS assert_journal_balanced;');
    }
};
