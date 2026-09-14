<?php

declare(strict_types=1);

use App\Modules\Platform\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap fix GA-05: when each nightly job last ran for a tenant (policy start and expiry with premium earning, payment reminders, renewals, quotation
 * and cover note expiry, licence alerts, reconciliation). One row per run, per entity for jobs that work per entity; `triggered_by` is the user who
 * pressed "Run now" (null when the schedule ran it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_runs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('job', 64);
            $t->uuid('entity_id')->nullable();
            $t->string('status', 16); // running|succeeded|failed
            $t->uuid('triggered_by')->nullable();
            $t->timestampTz('started_at');
            $t->timestampTz('finished_at')->nullable();
            $t->json('summary')->nullable(); // json keeps the order the job reported its counts in
            $t->text('error')->nullable();
            $t->index(['tenant_id', 'job', 'started_at']);
        });
        RowLevelSecurity::enable('job_runs');
    }

    public function down(): void
    {
        Schema::dropIfExists('job_runs');
    }
};
