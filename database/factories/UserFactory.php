<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Users are tenant-scoped: create them inside TenantContext::run() (tenant_id comes from the context).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }
}
