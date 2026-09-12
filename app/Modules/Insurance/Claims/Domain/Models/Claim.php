<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Claims\Domain\Models;

use App\Modules\Insurance\Claims\Domain\Enums\ClaimStatus;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Design §5.5 claim. reserve_minor is the current case reserve (total, not outstanding); reserve_version counts claim_reserves rows.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $branch_id
 * @property string $policy_id
 * @property string $number
 * @property CarbonImmutable $loss_date
 * @property CarbonImmutable $reported_on
 * @property string $description
 * @property ClaimStatus $status
 * @property int $reserve_minor
 * @property int $reserve_version
 * @property string $currency
 * @property string $created_by
 * @property CarbonImmutable|null $closed_on
 * @property string|null $status_reason
 */
final class Claim extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'claims';
    protected $guarded = [];
    protected $casts = ['status' => ClaimStatus::class, 'loss_date' => 'immutable_date', 'reported_on' => 'immutable_date', 'closed_on' => 'immutable_date',
        'reserve_minor' => 'int', 'reserve_version' => 'int'];
}
