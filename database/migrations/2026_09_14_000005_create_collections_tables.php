<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Design §2.4 receipts, receipt_allocations, suspense_items, plus refunds (§4.4 event B) — slice 1A.5. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('branch_id');
            $t->string('number');
            $t->uuid('party_id')->nullable();
            $t->string('channel');
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->date('value_date');
            $t->timestampTz('received_at');
            $t->uuid('bank_account_id')->nullable();
            $t->string('reference')->nullable();
            $t->string('status');
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'number']);
        });
        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_status_valid CHECK (status IN ('unallocated','partially_allocated','allocated','bounced','refunded'))");
        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_channel_valid CHECK (channel IN ('bank_transfer','cash','cheque','card','mobile_money'))");
        DB::statement('ALTER TABLE receipts ADD CONSTRAINT receipts_amount_positive CHECK (amount_minor > 0)');

        Schema::create('receipt_allocations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('receipt_id')->index();
            $t->string('target_type');
            $t->uuid('target_id');
            $t->uuid('policy_id')->nullable()->index();
            $t->uuid('suspense_item_id')->nullable();
            $t->bigInteger('amount_minor');
            $t->timestampTz('allocated_at');
            $t->uuid('allocated_by')->nullable();
        });
        DB::statement("ALTER TABLE receipt_allocations ADD CONSTRAINT receipt_allocations_target_valid CHECK (target_type IN ('installment','policy','claim_recovery','other'))");
        DB::statement('ALTER TABLE receipt_allocations ADD CONSTRAINT receipt_allocations_amount_positive CHECK (amount_minor > 0)');

        Schema::create('suspense_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('receipt_id')->index();
            $t->bigInteger('amount_minor');
            $t->bigInteger('allocated_minor')->default(0);
            $t->date('aged_since');
            $t->string('status')->default('open');
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE suspense_items ADD CONSTRAINT suspense_items_status_valid CHECK (status IN ('open','allocated','refunded','written_off'))");
        DB::statement('ALTER TABLE suspense_items ADD CONSTRAINT suspense_items_amounts_valid CHECK (amount_minor > 0 AND allocated_minor >= 0 AND allocated_minor <= amount_minor)');

        Schema::create('refunds', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('branch_id');
            $t->uuid('policy_id')->index();
            $t->uuid('party_id');
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->text('reason');
            $t->string('status')->default('requested');
            $t->uuid('requested_by');
            $t->timestampTz('requested_at');
            $t->uuid('decided_by')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->uuid('bank_account_id')->nullable();
        });
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_valid CHECK (status IN ('requested','released','rejected'))");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount_minor > 0)');

        foreach (['receipts', 'receipt_allocations', 'suspense_items', 'refunds'] as $table) {
            RowLevelSecurity::enable($table);
        }
    }

    public function down(): void
    {
        foreach (['refunds', 'suspense_items', 'receipt_allocations', 'receipts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
