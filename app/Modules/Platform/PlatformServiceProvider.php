<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Register in bootstrap/providers.php, before modules that depend on Platform. */
final class PlatformServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event): void {
            TenantContext::reapplyTo($event->connection);
        });
    }
}
