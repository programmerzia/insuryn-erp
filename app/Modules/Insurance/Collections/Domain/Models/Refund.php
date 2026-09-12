<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Domain\Models;

use App\Modules\Insurance\Collections\Domain\Enums\RefundStatus;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer refund (design §4.4 event B): requested by one person, released or rejected by another (§7.3).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $branch_id
 * @property string $policy_id
 * @property string $party_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $reason
 * @property RefundStatus $status
 * @property string $requested_by
 * @property CarbonImmutable $requested_at
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_reason
 * @property string|null $bank_account_id
 */
final class Refund extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    public $timestamps = false;
    protected $table = 'refunds';
    protected $guarded = [];
    protected $casts = ['status' => RefundStatus::class, 'requested_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'amount_minor' => 'int'];
}
