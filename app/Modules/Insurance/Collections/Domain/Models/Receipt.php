<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Domain\Models;

use App\Modules\Insurance\Collections\Domain\Enums\ReceiptStatus;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Design §2.4 receipts: money received, in minor units. Status follows how much of it is allocated.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $branch_id
 * @property string $number
 * @property string|null $party_id
 * @property string $channel
 * @property int $amount_minor
 * @property string $currency
 * @property CarbonImmutable $value_date
 * @property CarbonImmutable $received_at
 * @property string|null $bank_account_id
 * @property string|null $reference
 * @property ReceiptStatus $status
 * @property string|null $created_by
 * @property string|null $collected_by_agent_id
 * @property string|null $cheque_no
 * @property string|null $cheque_bank
 * @property CarbonImmutable|null $cheque_date
 * @property CarbonImmutable|null $bounced_on
 * @property string|null $bounce_reason
 * @property string|null $for_policy_id
 */
final class Receipt extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'receipts';
    protected $guarded = [];
    protected $casts = ['status' => ReceiptStatus::class, 'value_date' => 'immutable_date', 'received_at' => 'immutable_datetime', 'cheque_date' => 'immutable_date', 'bounced_on' => 'immutable_date', 'amount_minor' => 'int'];
}
