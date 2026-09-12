<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Domain\Models;

use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $party_id
 * @property PartyRoleType $role
 */
final class PartyRole extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'party_roles';
    protected $guarded = [];
    protected $casts = ['role' => PartyRoleType::class, 'role_data' => 'array'];
}
