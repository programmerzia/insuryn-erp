<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Domain\Models;

use App\Modules\Finance\Payables\Domain\Enums\PaymentRunStatus;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier payment run (addendum v2 §B.4): due bills paid from one bank account on one pay date. Prepared, approved and released by three people.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $number
 * @property string $bank_account_id
 * @property CarbonImmutable $pay_date
 * @property string $currency
 * @property int $total_minor
 * @property int $item_count
 * @property PaymentRunStatus $status
 * @property string|null $approval_id
 * @property string $created_by
 * @property string|null $submitted_by
 * @property string|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $released_by
 * @property CarbonImmutable|null $released_at
 * @property string|null $cancelled_reason
 * @property CarbonImmutable $created_at
 */
final class PaymentRun extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'payment_runs';
    protected $guarded = [];
    protected $casts = ['status' => PaymentRunStatus::class, 'pay_date' => 'immutable_date', 'approved_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime', 'total_minor' => 'int', 'item_count' => 'int'];

    /** @return HasMany<PaymentRunItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PaymentRunItem::class, 'run_id');
    }
}
