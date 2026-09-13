<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([AccountRolesSeeder::class, PermissionsSeeder::class, ProductClassesSeeder::class]);
        (new DemoTenantSeeder())->run('demo');
        $this->call([RolesSeeder::class, AdminUserSeeder::class]);
    }
}
