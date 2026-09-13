<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Platform user (design §2.1 users): tenant-scoped (RLS + TenantScope), UUIDv7 key. Loading a user
 * therefore needs the tenant first, which is why ResolveTenant runs before authentication (D-09).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property string $name
 * @property string $status
 * @property string $kind staff (web app) | portal (API tokens only, slice D9)
 * @property string|null $two_factor_secret
 * @property \Carbon\CarbonImmutable|null $two_factor_confirmed_at
 */
#[Fillable(['name', 'email', 'password', 'oidc_subject', 'status', 'kind'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    use BelongsToTenant;
    use HasApiTokens;
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasUuid7;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * Key of the password reset token row. password_reset_tokens is a platform table keyed by email, and the same email may exist in
     * several tenants, so the key carries the tenant: a token issued in one tenant can never reset a user of another.
     */
    public function getEmailForPasswordReset(): string
    {
        return $this->tenant_id.'|'.$this->email;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'immutable_datetime',
        ];
    }
}
