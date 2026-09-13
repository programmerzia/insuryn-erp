<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Domain\Models;

use App\Modules\Insurance\Collections\Domain\Enums\SuspenseStatus;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Design §2.4 suspense_items: the unallocated part of a receipt, aged from the receipt's value date.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $receipt_id
 * @property int $amount_minor
 * @property int $allocated_minor
 * @property CarbonImmutable $aged_since
 * @property SuspenseStatus $status
 * @property CarbonImmutable|null $bounced_on
 */
final class SuspenseItem extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'suspense_items';
    protected $guarded = [];
    protected $casts = ['status' => SuspenseStatus::class, 'aged_since' => 'immutable_date', 'bounced_on' => 'immutable_date', 'amount_minor' => 'int', 'allocated_minor' => 'int'];

    public function openMinor(): int
    {
        return $this->amount_minor - $this->allocated_minor;
    }
}
