<?php

declare(strict_types=1);

namespace App\Modules\Platform\Authentication;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum API tokens as a tenant table (CONTEXT.md #6): the tenant is resolved before authentication, so a token only ever authenticates inside
 * the tenant that issued it; UUIDv7 keys like every other table.
 */
final class PersonalAccessToken extends SanctumToken
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'personal_access_tokens';
}
