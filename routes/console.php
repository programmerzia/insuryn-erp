<?php

declare(strict_types=1);

use App\Modules\Accounting\Infrastructure\Jobs\OutboxRelayJob;
use App\Modules\Accounting\Infrastructure\Jobs\ReconciliationJob;
use App\Modules\Distribution\Infrastructure\Jobs\LicenceExpiryAlertJob;
use App\Modules\Insurance\Policy\Infrastructure\Jobs\DunningJob;
use App\Modules\Insurance\Policy\Infrastructure\Jobs\PremiumEarningJob;
use App\Modules\Platform\Numbering\ReservationSweeperJob;
use Illuminate\Support\Facades\Schedule;

// Design §8.5 background jobs. Each job loops over tenants itself (D-07).
Schedule::job(new OutboxRelayJob(), 'posting')->everySecond()->withoutOverlapping();
Schedule::job(new ReservationSweeperJob())->everyFifteenMinutes();
Schedule::job(new PremiumEarningJob(), 'batch')->dailyAt('01:00')->withoutOverlapping();
Schedule::job(new ReconciliationJob(), 'recon')->dailyAt('02:00')->withoutOverlapping();
Schedule::job(new DunningJob(), 'batch')->dailyAt('01:30')->withoutOverlapping();
Schedule::job(new LicenceExpiryAlertJob(), 'batch')->dailyAt('01:45')->withoutOverlapping();
