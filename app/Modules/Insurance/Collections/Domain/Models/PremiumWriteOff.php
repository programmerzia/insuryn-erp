<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Domain\Models;

use App\Modules\Insurance\Collections\Domain\Enums\WriteOffStatus;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Gap fixes W7 (GA-24): the write-off of the small premium a cancelled policy still owes, requested by one person and approved by another.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $branch_id
 * @property string $policy_id
 * @property int $requested_minor
 * @property int|null $written_off_minor
 * @property string $currency
 * @property WriteOffStatus $status
 * @property string $reason
 * @property string|null $approval_id
 * @property string $requested_by
 * @property CarbonImmutable $requested_at
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $written_off_on
 * @property string|null $rejection_reason
 */
final class PremiumWriteOff extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'premium_write_offs';
    protected $guarded = [];
    protected $casts = ['status' => WriteOffStatus::class, 'requested_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'written_off_on' => 'immutable_date',
        'requested_minor' => 'int', 'written_off_minor' => 'int'];
}
