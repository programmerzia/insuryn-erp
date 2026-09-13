<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distribution design note §1 producer_advances and producer_statements, §2 step 6 (slice D6). The Phase 1 `commission_statements` table is the
 * producer statement (D-15): it gains the monthly split (earned, override, bonus, clawback, advances recovered), a `draft` state for the prepared
 * run, and the payout route. Account roles for advances and accounts payable are added; existing tenants map them to accounts before using them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('account_roles')->insertOrIgnore([
            ['code' => 'producer_advances', 'description' => 'Advances paid to producers, recovered from their commission (Distribution D6)'],
            ['code' => 'accounts_payable', 'description' => 'Amounts owed to suppliers and producers paid through payables (Distribution D6)'],
        ]);

        DB::statement('ALTER TABLE commission_statements DROP CONSTRAINT commission_statements_status_valid');
        DB::statement('ALTER TABLE commission_statements DROP CONSTRAINT commission_statements_net_positive');
        Schema::table('commission_statements', function (Blueprint $t): void {
            $t->string('number')->nullable()->change();
            $t->uuid('approved_by')->nullable()->change();
            $t->date('approved_on')->nullable()->change();
            $t->date('period_end')->nullable();
            $t->bigInteger('earned_minor')->default(0);
            $t->bigInteger('override_minor')->default(0);
            $t->bigInteger('bonus_minor')->default(0);
            $t->bigInteger('clawback_minor')->default(0);
            $t->bigInteger('advances_recovered_minor')->default(0);
            $t->string('paid_via')->default('bank');
            $t->uuid('prepared_by')->nullable();
        });
        DB::statement("ALTER TABLE commission_statements ADD CONSTRAINT commission_statements_status_valid CHECK (status IN ('draft','approved','paid'))");
        DB::statement("ALTER TABLE commission_statements ADD CONSTRAINT commission_statements_paid_via_valid CHECK (paid_via IN ('bank','payroll','ap'))");
        DB::statement('ALTER TABLE commission_statements ADD CONSTRAINT commission_statements_amounts_valid CHECK (net_minor >= 0 AND gross_minor - withholding_minor > 0 AND advances_recovered_minor >= 0 AND net_minor = gross_minor - withholding_minor - advances_recovered_minor)');
        DB::statement("ALTER TABLE commission_statements ADD CONSTRAINT commission_statements_numbered CHECK (status = 'draft' OR (number IS NOT NULL AND approved_by IS NOT NULL AND approved_on IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX commission_statements_one_per_period ON commission_statements (agent_id, period_end) WHERE period_end IS NOT NULL');

        // The withholding rate an entry was accrued with, so conditional commission released later posts exactly the stored withholding.
        Schema::table('commission_entries', fn (Blueprint $t) => $t->unsignedInteger('withholding_bp')->nullable());

        Schema::create('producer_advances', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('entity_id');
            $t->uuid('producer_id')->index();
            $t->bigInteger('amount_minor');
            $t->bigInteger('balance_minor');
            $t->char('currency', 3);
            $t->date('issued_on');
            $t->jsonb('recovery_rule');
            $t->string('status')->default('open');
            $t->uuid('bank_account_id')->nullable();
            $t->uuid('issued_by');
            $t->timestampsTz();
            $t->foreign('producer_id')->references('id')->on('producers');
        });
        DB::statement("ALTER TABLE producer_advances ADD CONSTRAINT producer_advances_status_valid CHECK (status IN ('open','recovered'))");
        DB::statement("ALTER TABLE producer_advances ADD CONSTRAINT producer_advances_balance_valid CHECK (amount_minor > 0 AND balance_minor >= 0 AND balance_minor <= amount_minor AND (status = 'recovered') = (balance_minor = 0))");

        Schema::create('producer_advance_recoveries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('advance_id')->index();
            $t->uuid('statement_id');
            $t->bigInteger('amount_minor');
            $t->date('recovered_on');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['advance_id', 'statement_id']);
            $t->foreign('advance_id')->references('id')->on('producer_advances');
        });
        DB::statement('ALTER TABLE producer_advance_recoveries ADD CONSTRAINT producer_advance_recoveries_positive CHECK (amount_minor > 0)');

        RowLevelSecurity::enable('producer_advances');
        RowLevelSecurity::enable('producer_advance_recoveries');
    }

    public function down(): void
    {
        Schema::dropIfExists('producer_advance_recoveries');
        Schema::dropIfExists('producer_advances');
        Schema::table('commission_entries', fn (Blueprint $t) => $t->dropColumn('withholding_bp'));
        DB::statement('DROP INDEX IF EXISTS commission_statements_one_per_period');
        foreach (['commission_statements_numbered', 'commission_statements_amounts_valid', 'commission_statements_paid_via_valid', 'commission_statements_status_valid'] as $constraint) {
            DB::statement("ALTER TABLE commission_statements DROP CONSTRAINT IF EXISTS {$constraint}");
        }
        Schema::table('commission_statements', fn (Blueprint $t) => $t->dropColumn(['period_end', 'earned_minor', 'override_minor', 'bonus_minor', 'clawback_minor', 'advances_recovered_minor', 'paid_via', 'prepared_by']));
        DB::statement("ALTER TABLE commission_statements ADD CONSTRAINT commission_statements_status_valid CHECK (status IN ('approved','paid'))");
        DB::statement('ALTER TABLE commission_statements ADD CONSTRAINT commission_statements_net_positive CHECK (net_minor > 0)');
    }
};
