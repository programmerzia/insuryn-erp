<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // UX brief §7 first paint: the stylesheet does not block the skeleton frame in the HTML; app.ts waits for it before mounting.
        Vite::useStyleTagAttributes(fn (string $src): array => str_contains($src, 'corebari') ? [] : ['media' => 'print', 'onload' => "this.media='all'"]);
        $this->registerDevProcesses();
    }

    /**
     * Gap fix GA-05: `composer dev` (`php artisan dev`) runs what the product needs to behave as in production — a queue worker on the posting,
     * batch, recon and default queues (Horizon's default supervisor watches only `default`, so postings and nightly batches never ran) and the
     * scheduler, so policies become Active, reminders go out and renewals are prepared without anyone running a command.
     */
    private function registerDevProcesses(): void
    {
        if (! class_exists(DevCommands::class) || ! $this->app->runningInConsole()) {
            return;
        }
        DevCommands::except('horizon', 'queue');
        DevCommands::artisan('queue:work --queue=posting,batch,recon,default --tries=1', 'worker');
        DevCommands::artisan('schedule:work', 'scheduler');
    }
}
