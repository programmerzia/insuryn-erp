<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 People and Payroll MVP (design addendum v2 §B.9, §B.10, §B.11): organisation reference data, employees (a person party plus the employee record),
 * effective-dated employment history, salary structures per grade, payroll settings and income tax slabs as data, payroll inputs (the commission payroll
 * route), payroll runs, payslips with their lines, and salary bank files.
 *
 * INVARIANT one employment record per employee per day (exclusion constraint). INVARIANT one regular run per entity and month (partial unique index).
 * INVARIANT a commission statement produces at most one payroll input (unique by source). DECISION D-121: `producers.employee_id` gets no foreign key yet
 * (existing Distribution data and tests carry employee ids without employees; PD-20's cut-over check is LATER).
 */
return new class extends Migration
{
    private const TABLES = ['departments', 'designations', 'grades', 'employees', 'employments', 'salary_structures', 'payroll_settings', 'payroll_tax_slabs',
        'payroll_inputs', 'payroll_runs', 'payslips', 'payslip_lines', 'salary_bank_files'];

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        foreach (['departments', 'designations', 'grades'] as $reference) {
            Schema::create($reference, function (Blueprint $t) use ($reference): void {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id')->index();
                $t->string('code', 32);
                $t->string('name');
                if ($reference === 'grades') {
                    $t->unsignedSmallInteger('rank')->default(1);
                }
                $t->string('status', 16)->default('active');
                $t->timestampsTz();
                $t->unique(['tenant_id', 'code']);
            });
        }

        Schema::create('employees', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('party_id');
            $t->string('code', 32);
            $t->string('full_name');
            $t->string('status', 16)->default('active'); // onboarding | active | separated
            $t->date('joined_on');
            $t->date('separated_on')->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('gender', 8)->nullable();
            $t->text('tin_enc')->nullable();
            $t->string('tin_masked', 32)->nullable();
            $t->text('nid_enc')->nullable();
            $t->string('nid_masked', 32)->nullable();
            $t->string('bank_name')->nullable();
            $t->string('bank_branch')->nullable();
            $t->string('routing_no', 16)->nullable();
            $t->text('account_no_enc')->nullable();
            $t->string('account_no_masked', 32)->nullable();
            $t->string('mobile', 20)->nullable();
            $t->uuid('user_id')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'party_id']);
        });
        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_status_valid CHECK (status IN ('onboarding','active','separated'))");

        Schema::create('employments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('employee_id');
            $t->uuid('entity_id');
            $t->uuid('branch_id');
            $t->uuid('department_id');
            $t->uuid('designation_id');
            $t->uuid('grade_id');
            $t->string('employment_type', 16); // permanent | probation | contract | intern
            $t->bigInteger('basic_minor');
            $t->char('currency', 3);
            $t->date('effective_from');
            $t->date('effective_to')->nullable(); // exclusive
            $t->string('change_kind', 16); // hire | promotion | transfer | pay_change | separation
            $t->text('note')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->index(['employee_id', 'effective_from']);
            $t->foreign('employee_id')->references('id')->on('employees');
        });
        DB::statement("ALTER TABLE employments ADD CONSTRAINT employments_type_valid CHECK (employment_type IN ('permanent','probation','contract','intern'))");
        DB::statement('ALTER TABLE employments ADD CONSTRAINT employments_basic_positive CHECK (basic_minor > 0)');
        DB::statement('ALTER TABLE employments ADD CONSTRAINT employments_dates_valid CHECK (effective_to IS NULL OR effective_to > effective_from)');
        DB::statement("ALTER TABLE employments ADD CONSTRAINT employments_one_record_per_day EXCLUDE USING gist (employee_id WITH =, daterange(effective_from, effective_to, '[)') WITH &&)");

        Schema::create('salary_structures', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('grade_id');
            $t->bigInteger('basic_min_minor')->default(0);
            $t->bigInteger('basic_max_minor')->nullable();
            $t->unsignedInteger('house_rent_bp');
            $t->unsignedInteger('medical_bp');
            $t->bigInteger('medical_cap_minor')->nullable();
            $t->bigInteger('conveyance_minor')->default(0);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->boolean('verify')->default(true);
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
            $t->foreign('grade_id')->references('id')->on('grades');
        });
        DB::statement("ALTER TABLE salary_structures ADD CONSTRAINT salary_structures_one_per_grade EXCLUDE USING gist (grade_id WITH =, daterange(effective_from, effective_to, '[)') WITH &&)");

        Schema::create('payroll_settings', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->unsignedInteger('pf_employee_bp');
            $t->unsignedInteger('pf_employer_bp');
            $t->jsonb('pf_employment_types'); // employment types that contribute
            $t->unsignedInteger('festival_bonus_bp'); // of basic, per festival
            $t->unsignedSmallInteger('festival_bonus_min_service_months')->default(0);
            $t->jsonb('festivals'); // [{name, month: "YYYY-MM"}]
            $t->unsignedSmallInteger('tax_year_start_month')->default(7);
            $t->unsignedInteger('tax_exempt_fraction_bp'); // share of annual income exempt, capped
            $t->bigInteger('tax_exempt_cap_minor');
            $t->bigInteger('minimum_tax_minor')->default(0);
            $t->string('tax_category', 32)->default('general');
            $t->boolean('commission_taxable')->default(false); // CQ-F4, ASSUMPTION A-283
            $t->boolean('verify')->default(true);
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE payroll_settings ADD CONSTRAINT payroll_settings_one_per_entity EXCLUDE USING gist (entity_id WITH =, daterange(effective_from, effective_to, '[)') WITH &&)");

        Schema::create('payroll_tax_slabs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('tax_year', 7); // 2026-27
            $t->string('category', 32)->default('general');
            $t->unsignedSmallInteger('seq');
            $t->bigInteger('band_minor')->nullable(); // width of the band; null = the rest
            $t->unsignedInteger('rate_bp');
            $t->boolean('verify')->default(true);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'tax_year', 'category', 'seq']);
        });

        Schema::create('payroll_inputs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id')->nullable();
            $t->uuid('employee_id')->nullable(); // null while parked (EMPLOYEE_UNKNOWN)
            $t->string('employee_ref')->nullable(); // the employee id the message named
            $t->unsignedSmallInteger('period_year');
            $t->unsignedSmallInteger('period_month');
            $t->string('component_code', 32);
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('source_type', 32); // commission_statement | manual
            $t->uuid('source_id')->nullable();
            $t->string('source_reference')->nullable();
            $t->boolean('pre_accrued')->default(false);
            $t->boolean('taxable')->default(true);
            $t->date('accrued_on')->nullable(); // when its liability reached salary_payable (pre-accrued inputs)
            $t->string('status', 16)->default('open'); // open | consumed | parked | cancelled
            $t->string('parked_reason', 32)->nullable();
            $t->uuid('consumed_by_run_id')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->index(['employee_id', 'period_year', 'period_month']);
        });
        DB::statement('CREATE UNIQUE INDEX payroll_inputs_one_per_source ON payroll_inputs (tenant_id, source_type, source_id, component_code) WHERE source_id IS NOT NULL');

        Schema::create('payroll_runs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->string('number', 40)->nullable();
            $t->string('kind', 16)->default('regular');
            $t->unsignedSmallInteger('period_year');
            $t->unsignedSmallInteger('period_month');
            $t->string('status', 16); // preview | posted | paid | cancelled
            $t->string('inputs_hash', 64)->nullable();
            $t->unsignedInteger('employee_count')->default(0);
            $t->bigInteger('gross_minor')->default(0);
            $t->bigInteger('bonus_minor')->default(0);
            $t->bigInteger('commission_minor')->default(0);
            $t->bigInteger('tax_minor')->default(0);
            $t->bigInteger('pf_employee_minor')->default(0);
            $t->bigInteger('pf_employer_minor')->default(0);
            $t->bigInteger('net_minor')->default(0);
            $t->char('currency', 3);
            $t->timestampTz('calculated_at')->nullable();
            $t->uuid('prepared_by');
            $t->uuid('approved_by')->nullable();
            $t->date('posted_on')->nullable();
            $t->uuid('paid_by')->nullable();
            $t->date('paid_on')->nullable();
            $t->uuid('bank_account_id')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE payroll_runs ADD CONSTRAINT payroll_runs_status_valid CHECK (status IN ('preview','posted','paid','cancelled'))");
        DB::statement("CREATE UNIQUE INDEX payroll_runs_one_regular_per_month ON payroll_runs (tenant_id, entity_id, period_year, period_month) WHERE kind = 'regular' AND status <> 'cancelled'");

        Schema::create('payslips', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('run_id');
            $t->uuid('employee_id');
            $t->string('number', 40)->nullable();
            $t->uuid('branch_id');
            $t->uuid('department_id');
            $t->jsonb('employment_snapshot');
            $t->bigInteger('basic_minor');
            $t->bigInteger('gross_minor');
            $t->bigInteger('bonus_minor')->default(0);
            $t->bigInteger('commission_minor')->default(0);
            $t->bigInteger('taxable_annual_minor')->default(0);
            $t->bigInteger('tax_minor')->default(0);
            $t->bigInteger('pf_employee_minor')->default(0);
            $t->bigInteger('pf_employer_minor')->default(0);
            $t->bigInteger('net_minor');
            $t->jsonb('bank_account_snapshot')->nullable();
            $t->jsonb('trace');
            $t->uuid('stored_document_id')->nullable();
            $t->timestampsTz();
            $t->unique(['run_id', 'employee_id']);
            $t->foreign('run_id')->references('id')->on('payroll_runs')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE payslips ADD CONSTRAINT payslips_net_not_negative CHECK (net_minor >= 0)');

        Schema::create('payslip_lines', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('payslip_id')->index();
            $t->unsignedSmallInteger('line_no');
            $t->string('component_code', 32);
            $t->string('kind', 24); // earning | deduction | employer_contribution
            $t->string('label');
            $t->bigInteger('amount_minor');
            $t->boolean('pre_accrued')->default(false);
            $t->foreign('payslip_id')->references('id')->on('payslips')->cascadeOnDelete();
        });

        Schema::create('salary_bank_files', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('run_id');
            $t->unsignedSmallInteger('version');
            $t->string('file_name');
            $t->text('content_enc'); // the CSV carries full account numbers: stored encrypted (application key)
            $t->string('sha256', 64);
            $t->bigInteger('total_minor');
            $t->unsignedInteger('item_count');
            $t->uuid('generated_by');
            $t->timestampTz('generated_at');
            $t->unique(['run_id', 'version']);
        });

        foreach (self::TABLES as $table) {
            RowLevelSecurity::enable($table);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
