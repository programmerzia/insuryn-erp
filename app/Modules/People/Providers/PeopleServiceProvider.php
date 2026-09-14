<?php

declare(strict_types=1);

namespace App\Modules\People\Providers;

use App\Modules\Accounting\Application\Contracts\CloseTaskCheck;
use App\Modules\Accounting\Application\Contracts\CloseTaskContributor;
use App\Modules\Accounting\Application\Contracts\SubledgerReconciler;
use App\Modules\People\Payroll\Application\CommissionPayrollEarningConsumer;
use App\Modules\People\Payroll\Application\PayrollCloseTasks;
use App\Modules\People\Payroll\Application\PayrollReconciler;
use App\Modules\Platform\Messaging\OutboxConsumer;
use App\Modules\Platform\Messaging\OutboxConsumers;
use Illuminate\Support\ServiceProvider;

/** Wires People (employees, payroll) into the kernel and platform extension points: the payroll reconciler, close tasks and the commission outbox consumer. */
final class PeopleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([PayrollReconciler::class], SubledgerReconciler::class);
        $this->app->tag([PayrollCloseTasks::class], CloseTaskCheck::class);
        $this->app->tag([PayrollCloseTasks::class], CloseTaskContributor::class);
        $this->app->tag([CommissionPayrollEarningConsumer::class], OutboxConsumer::class);
        $this->app->when(OutboxConsumers::class)->needs('$consumers')->giveTagged(OutboxConsumer::class);
    }
}
