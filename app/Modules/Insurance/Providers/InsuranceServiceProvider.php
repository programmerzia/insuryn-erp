<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Providers;

use App\Modules\Insurance\Policy\Application\PremiumEarning\CatchUpEarningOnCancellation;
use App\Modules\Insurance\Policy\Domain\Events\PolicyCancelled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Wires the Insurance context's in-process domain event listeners (all run inside the emitting transaction). */
final class InsuranceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(PolicyCancelled::class, [CatchUpEarningOnCancellation::class, 'handle']);
    }
}
