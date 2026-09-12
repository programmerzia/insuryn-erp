<?php

declare(strict_types=1);

namespace App\Modules\Finance\Providers;

use App\Modules\Accounting\Application\Contracts\CloseTaskCheck;
use App\Modules\Finance\Bank\Application\BankReconciliationCloseCheck;
use Illuminate\Support\ServiceProvider;

/** Wires the Finance context into the kernel's extension points (close task checks). */
final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([BankReconciliationCloseCheck::class], CloseTaskCheck::class);
    }
}
