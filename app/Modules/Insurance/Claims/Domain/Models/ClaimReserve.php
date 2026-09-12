<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of a claim's reserve history (append-only, enforced by trigger claim_reserves_append_only).
 *
 * @property string $id
 * @property string $claim_id
 * @property int $version
 * @property int $reserve_minor
 * @property int $delta_minor
 * @property string $kind reserve|adjustment|close_release|reject_release
 * @property string $reason
 * @property CarbonImmutable $recorded_on
 * @property string $recorded_by
 */
final class ClaimReserve extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    public const UPDATED_AT = null;

    protected $table = 'claim_reserves';
    protected $guarded = [];
    protected $casts = ['recorded_on' => 'immutable_date', 'version' => 'int', 'reserve_minor' => 'int', 'delta_minor' => 'int'];
}
