<?php

declare(strict_types=1);

namespace App\Providers;

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
    }
}
