<?php

declare(strict_types=1);

use App\Modules\Accounting\AccountingServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AccountingServiceProvider::class,
];
