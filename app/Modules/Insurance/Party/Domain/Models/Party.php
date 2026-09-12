<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Domain\Models;

use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property PartyKind $kind
 * @property string $display_name
 * @property string|null $tax_id
 * @property string $status
 */
final class Party extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'parties';
    protected $guarded = [];
    protected $casts = ['kind' => PartyKind::class];

    /** @return HasMany<PartyRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(PartyRole::class)->orderBy('role');
    }

    /** @return HasMany<PartyBankAccount, $this> */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(PartyBankAccount::class)->orderBy('created_at')->orderBy('id');
    }
}
