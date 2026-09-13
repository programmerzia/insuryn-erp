<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice R8 (docs/rating-quotation-documents-design.md §3): tenant-editable document templates and the immutable record of every
 * generated PDF. A template is versioned per (code, product class, locale): one active version at a time, active and retired versions are never
 * edited (a change is a new draft version). A generated document points at its PDF in stored_documents (fix F2) and is append-only, like it;
 * regenerating is a new version.
 *
 * Permissions (ASSUMPTION A-101): document.generate for the branch officer and branch manager templates, document.manage_templates for the
 * tenant admin template; existing tenants' roles get them here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('code', 40);
            $t->string('product_class', 40)->nullable(); // a product_classes code; null = every class
            $t->unsignedInteger('version');
            $t->string('engine', 20)->default('blade_pdf');
            $t->char('locale', 2);
            $t->text('body');
            $t->text('letterhead')->nullable(); // null = the entity name as letterhead
            $t->string('status', 10)->default('draft');
            $t->uuid('created_by')->nullable(); // null = seeded by the system
            $t->timestampTz('created_at');
            $t->timestampTz('updated_at');
            $t->uuid('activated_by')->nullable();
            $t->timestampTz('activated_at')->nullable();
            $t->timestampTz('retired_at')->nullable();
        });
        RowLevelSecurity::enable('document_templates');

        Schema::create('generated_documents', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('template_id');
            $t->string('template_code', 40);
            $t->unsignedInteger('template_version');
            $t->char('locale', 2);
            $t->string('object_type', 40);
            $t->uuid('object_id');
            $t->string('number', 100)->nullable(); // the business number of the object (policy, receipt, …)
            $t->unsignedInteger('version');
            $t->uuid('stored_document_id');
            $t->char('sha256', 64); // of the PDF bytes, equal to the stored document's
            $t->char('content_sha256', 64); // of the rendered HTML (letterhead and body): the reference printed in the footer
            $t->bigInteger('size_bytes');
            $t->timestampTz('rendered_at');
            $t->uuid('rendered_by')->nullable();
            $t->foreign('template_id')->references('id')->on('document_templates');
            $t->foreign('stored_document_id')->references('id')->on('stored_documents');
            $t->unique(['tenant_id', 'object_type', 'object_id', 'template_code', 'version']);
        });
        RowLevelSecurity::enable('generated_documents');

        DB::unprepared(<<<'SQL'
ALTER TABLE document_templates
  ADD CONSTRAINT document_templates_code_valid CHECK (code IN ('quotation','cover_note','policy_schedule','endorsement','receipt','renewal_notice','claim_ack','discharge_voucher')),
  ADD CONSTRAINT document_templates_locale_valid CHECK (locale IN ('en','bn')),
  ADD CONSTRAINT document_templates_engine_valid CHECK (engine = 'blade_pdf'),
  ADD CONSTRAINT document_templates_status_valid CHECK (status IN ('draft','active','retired')),
  ADD CONSTRAINT document_templates_version_positive CHECK (version > 0),
  ADD CONSTRAINT document_templates_activated CHECK (status = 'draft' OR activated_at IS NOT NULL),
  ADD CONSTRAINT document_templates_retired CHECK ((status = 'retired') = (retired_at IS NOT NULL));

CREATE UNIQUE INDEX document_templates_version_unique ON document_templates (tenant_id, code, coalesce(product_class, ''), locale, version);
-- One active template per code, class and locale.
CREATE UNIQUE INDEX document_templates_one_active ON document_templates (tenant_id, code, coalesce(product_class, ''), locale) WHERE status = 'active';

CREATE OR REPLACE FUNCTION protect_document_templates() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    IF OLD.status <> 'draft' THEN
      RAISE EXCEPTION 'DOCUMENT_TEMPLATE_IMMUTABLE: % template % v% cannot be deleted', OLD.status, OLD.code, OLD.version USING ERRCODE = 'check_violation';
    END IF;
    RETURN OLD;
  END IF;
  IF NEW.code <> OLD.code OR NEW.product_class IS DISTINCT FROM OLD.product_class OR NEW.locale <> OLD.locale OR NEW.version <> OLD.version
     OR NEW.engine <> OLD.engine OR NEW.tenant_id <> OLD.tenant_id OR NEW.created_by IS DISTINCT FROM OLD.created_by THEN
    RAISE EXCEPTION 'DOCUMENT_TEMPLATE_IMMUTABLE: the identity of template % v% cannot change', OLD.code, OLD.version USING ERRCODE = 'check_violation';
  END IF;
  IF OLD.status <> 'draft' AND (NEW.body IS DISTINCT FROM OLD.body OR NEW.letterhead IS DISTINCT FROM OLD.letterhead) THEN
    RAISE EXCEPTION 'DOCUMENT_TEMPLATE_IMMUTABLE: % template % v% cannot be edited; save a new draft version', OLD.status, OLD.code, OLD.version USING ERRCODE = 'check_violation';
  END IF;
  IF NOT (NEW.status = OLD.status OR (OLD.status = 'draft' AND NEW.status = 'active') OR (OLD.status = 'active' AND NEW.status = 'retired')) THEN
    RAISE EXCEPTION 'DOCUMENT_TEMPLATE_TRANSITION: template % v% cannot go from % to %', OLD.code, OLD.version, OLD.status, NEW.status USING ERRCODE = 'check_violation';
  END IF;
  IF OLD.status <> 'draft' AND (NEW.activated_by IS DISTINCT FROM OLD.activated_by OR NEW.activated_at IS DISTINCT FROM OLD.activated_at) THEN
    RAISE EXCEPTION 'DOCUMENT_TEMPLATE_IMMUTABLE: the activation of template % v% cannot change', OLD.code, OLD.version USING ERRCODE = 'check_violation';
  END IF;
  IF OLD.status = 'retired' AND NEW.retired_at IS DISTINCT FROM OLD.retired_at THEN
    RAISE EXCEPTION 'DOCUMENT_TEMPLATE_IMMUTABLE: retired template % v% cannot change', OLD.code, OLD.version USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;

CREATE TRIGGER document_templates_protected BEFORE UPDATE OR DELETE ON document_templates
  FOR EACH ROW EXECUTE FUNCTION protect_document_templates();

ALTER TABLE generated_documents
  ADD CONSTRAINT generated_documents_code_valid CHECK (template_code IN ('quotation','cover_note','policy_schedule','endorsement','receipt','renewal_notice','claim_ack','discharge_voucher')),
  ADD CONSTRAINT generated_documents_locale_valid CHECK (locale IN ('en','bn')),
  ADD CONSTRAINT generated_documents_version_positive CHECK (version > 0 AND template_version > 0),
  ADD CONSTRAINT generated_documents_sha256_hex CHECK (sha256 ~ '^[0-9a-f]{64}$' AND content_sha256 ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT generated_documents_size_positive CHECK (size_bytes > 0);

CREATE OR REPLACE FUNCTION protect_generated_documents() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'GENERATED_DOCUMENT_APPEND_ONLY: generated document % cannot be %', OLD.id, lower(TG_OP) USING ERRCODE = 'check_violation';
END $$;

CREATE TRIGGER generated_documents_append_only BEFORE UPDATE OR DELETE ON generated_documents
  FOR EACH ROW EXECUTE FUNCTION protect_generated_documents();
SQL);

        DB::table('permissions')->insertOrIgnore([
            ['code' => 'document.generate', 'description' => 'Generate printable documents (policy schedule, endorsement, receipt) from the active templates'],
            ['code' => 'document.manage_templates', 'description' => 'Edit, preview and activate document templates'],
        ]);
        RowLevelSecurity::forEachTenant(function (string $tenantId): void {
            foreach (['branch_officer' => 'document.generate', 'branch_manager' => 'document.generate', 'tenant_admin' => 'document.manage_templates'] as $role => $permission) {
                $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('code', $role)->value('id');
                if (is_string($roleId)) {
                    DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => $roleId, 'permission_code' => $permission]);
                }
            }
        });
    }

    public function down(): void
    {
        RowLevelSecurity::forEachTenant(fn () => DB::table('role_permissions')->whereIn('permission_code', ['document.generate', 'document.manage_templates'])->delete());
        DB::table('permissions')->whereIn('code', ['document.generate', 'document.manage_templates'])->delete();
        DB::unprepared('DROP TRIGGER IF EXISTS generated_documents_append_only ON generated_documents; DROP FUNCTION IF EXISTS protect_generated_documents;');
        Schema::dropIfExists('generated_documents');
        DB::unprepared('DROP TRIGGER IF EXISTS document_templates_protected ON document_templates; DROP FUNCTION IF EXISTS protect_document_templates;');
        Schema::dropIfExists('document_templates');
    }
};
