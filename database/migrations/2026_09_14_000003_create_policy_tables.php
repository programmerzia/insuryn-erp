<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Design §2.4 policies, policy_transactions, installments (slice 1A.3). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('branch_id');
            $t->string('number')->nullable();
            $t->uuid('product_id');
            $t->uuid('product_version_id');
            $t->uuid('policyholder_party_id')->index();
            $t->uuid('agent_id')->nullable()->index();
            $t->string('channel');
            $t->string('status');
            $t->date('inception');
            $t->date('expiry');
            $t->char('currency', 3);
            $t->bigInteger('gross_premium_minor');
            $t->bigInteger('tax_minor');
            $t->bigInteger('net_premium_minor');
            $t->unsignedSmallInteger('installment_count');
            $t->timestampTz('issued_at')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->date('cancel_date')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->uuid('renewal_of_policy_id')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
            $t->index(['status', 'inception']);
        });
        DB::statement("ALTER TABLE policies ADD CONSTRAINT policies_status_valid CHECK (status IN ('quote','issued','active','cancelled','lapsed','expired','renewed'))");
        DB::statement('ALTER TABLE policies ADD CONSTRAINT policies_premium_valid CHECK (net_premium_minor >= 0 AND tax_minor >= 0 AND gross_premium_minor = net_premium_minor + tax_minor)');

        Schema::create('policy_transactions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('policy_id')->index();
            $t->string('type');
            $t->date('effective_date');
            $t->bigInteger('premium_delta_minor');
            $t->bigInteger('net_delta_minor');
            $t->bigInteger('tax_delta_minor');
            $t->unsignedInteger('policy_version');
            $t->text('reason')->nullable();
            $t->jsonb('amounts')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestampTz('created_at');
        });
        DB::statement("ALTER TABLE policy_transactions ADD CONSTRAINT policy_transactions_type_valid CHECK (type IN ('new','endorsement','cancellation','renewal'))");

        Schema::create('installments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('policy_id');
            $t->unsignedSmallInteger('no');
            $t->date('due_date');
            $t->bigInteger('amount_minor');
            $t->bigInteger('paid_minor')->default(0);
            $t->bigInteger('cancelled_minor')->default(0);
            $t->string('status')->default('pending');
            $t->timestampsTz();
            $t->unique(['policy_id', 'no']);
            $t->index('due_date');
        });
        DB::statement("ALTER TABLE installments ADD CONSTRAINT installments_status_valid CHECK (status IN ('pending','partially_paid','paid','cancelled'))");
        DB::statement('ALTER TABLE installments ADD CONSTRAINT installments_amounts_valid CHECK (amount_minor > 0 AND paid_minor >= 0 AND cancelled_minor >= 0 AND paid_minor + cancelled_minor <= amount_minor)');

        foreach (['policies', 'policy_transactions', 'installments'] as $table) {
            RowLevelSecurity::enable($table);
        }
    }

    public function down(): void
    {
        foreach (['installments', 'policy_transactions', 'policies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
