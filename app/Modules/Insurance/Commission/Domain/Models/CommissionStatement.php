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
 * @property string|null $number null while draft
 * @property CarbonImmutable|null $period_end statement run period (slice D6); null for Phase 1 per-agent statements
 * @property int $earned_minor
 * @property int $override_minor
 * @property int $bonus_minor
 * @property int $clawback_minor
 * @property int $advances_recovered_minor
 * @property string $paid_via bank|payroll|ap
 * @property CarbonImmutable $up_to
 * @property int $gross_minor
 * @property int $withholding_minor
 * @property int $net_minor
 * @property string $currency
 * @property string $status draft|approved|paid
 * @property string|null $approved_by
 * @property CarbonImmutable|null $approved_on
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
    protected $casts = ['up_to' => 'immutable_date', 'period_end' => 'immutable_date', 'approved_on' => 'immutable_date', 'paid_on' => 'immutable_date',
        'gross_minor' => 'int', 'withholding_minor' => 'int', 'net_minor' => 'int'];
}
