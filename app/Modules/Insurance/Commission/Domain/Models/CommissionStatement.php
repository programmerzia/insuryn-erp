<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A commission payout statement: an agent's accrued entries up to a date, netted (clawbacks included), approved then paid (design §5.6).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $agent_id
 * @property string $number
 * @property CarbonImmutable $up_to
 * @property int $gross_minor
 * @property int $withholding_minor
 * @property int $net_minor
 * @property string $currency
 * @property string $status approved|paid
 * @property string $approved_by
 * @property CarbonImmutable $approved_on
 * @property string|null $paid_by
 * @property CarbonImmutable|null $paid_on
 * @property string|null $bank_account_id
 */
final class CommissionStatement extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'commission_statements';
    protected $guarded = [];
    protected $casts = ['up_to' => 'immutable_date', 'approved_on' => 'immutable_date', 'paid_on' => 'immutable_date',
        'gross_minor' => 'int', 'withholding_minor' => 'int', 'net_minor' => 'int'];
}
