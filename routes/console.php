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

// Slice D9: regenerate the producer portal's OpenAPI document from the routes and their PortalOperation attributes.
Illuminate\Support\Facades\Artisan::command('portal:openapi', function (App\Http\Portal\OpenApi\PortalOpenApi $openApi): void {
    $path = base_path('docs/api/producer-portal.openapi.json');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, json_encode($openApi->document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    $this->info("Wrote {$path}");
})->purpose('Write docs/api/producer-portal.openapi.json from the portal routes');


// Session S1: a tenant on its first day (roles and segregation-of-duties rules, no company or products) and its admin, admin@<slug>.local.
// Sign in at http://<slug>.localhost:8000; the setup wizard opens.
Illuminate\Support\Facades\Artisan::command('erp:tenant {slug : subdomain, letters and digits} {name : the company name shown in the app}', function (string $slug, string $name): int {
    if (preg_match('/^[a-z0-9-]{2,32}$/', $slug) !== 1) {
        $this->error('The slug is 2–32 lowercase letters, digits or dashes.');

        return 1;
    }
    (new Database\Seeders\BlankTenantSeeder())->run($slug, $name);
    (new Database\Seeders\AdminUserSeeder())->run();
    $this->info("Tenant {$slug} is ready. Sign in as admin@{$slug}.local at http://{$slug}.localhost:8000 (password: config erp.seed.admin_password).");

    return 0;
})->purpose('Create a blank tenant whose first sign-in opens the setup wizard');

// Session S2: the market cross-check Part A story ("a week in a non-life insurer") in its own tenant, for demos and the guided tour. Local and staging only.
Illuminate\Support\Facades\Artisan::command('erp:demo {--tenant=nonlife : slug of the demo tenant}', function (): int {
    if (! app()->environment(['local', 'staging', 'testing'])) {
        $this->error('erp:demo seeds demonstration data and runs in local or staging only.');

        return 1;
    }
    $slug = is_string($this->option('tenant')) ? $this->option('tenant') : 'nonlife';
    if (! (new Database\Seeders\PartADemoSeeder())->run($slug)) {
        $this->info("Tenant {$slug} already has the Part A story; nothing changed.");

        return 0;
    }
    $this->info("Seeded the Part A story in tenant {$slug}. Sign in at http://{$slug}.localhost:8000 as admin@{$slug}.local or <role>@{$slug}.local (for example accountant@{$slug}.local).");
    $this->line('September bank statement: '.storage_path(Database\Seeders\PartADemoSeeder::STATEMENT_FILE));

    return 0;
})->purpose('Seed the Part A demo story (3 products, 8 policies, suspense, bank exceptions, 2 claims, August closed)');
