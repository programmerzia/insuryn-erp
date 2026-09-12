<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Design §2.4 receipt_allocations: part of a receipt applied to a target, directly or out of suspense.
 *
 * @property string $id
 * @property string $receipt_id
 * @property string $target_type
 * @property string $target_id
 * @property string|null $policy_id
 * @property string|null $suspense_item_id
 * @property int $amount_minor
 * @property CarbonImmutable $posted_on
 * @property CarbonImmutable $allocated_at
 * @property string|null $allocated_by
 */
final class ReceiptAllocation extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    public $timestamps = false;
    protected $table = 'receipt_allocations';
    protected $guarded = [];
    protected $casts = ['allocated_at' => 'immutable_datetime', 'posted_on' => 'immutable_date', 'amount_minor' => 'int'];
}
