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

/**
 * Platform user (design §2.1 users): tenant-scoped (RLS + TenantScope), UUIDv7 key. Loading a user
 * therefore needs the tenant first, which is why ResolveTenant runs before authentication (D-09).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property string $name
 * @property string $status
 */
#[Fillable(['name', 'email', 'password', 'oidc_subject', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use BelongsToTenant;
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasUuid7;
    use Notifiable;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
