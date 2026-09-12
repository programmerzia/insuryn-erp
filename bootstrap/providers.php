<?php

declare(strict_types=1);

use App\Modules\Accounting\AccountingServiceProvider;
use App\Modules\Finance\Providers\FinanceServiceProvider;
use App\Modules\Insurance\Providers\InsuranceServiceProvider;
use App\Modules\Platform\PlatformServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    AccountingServiceProvider::class,
    InsuranceServiceProvider::class,
    FinanceServiceProvider::class,
];
