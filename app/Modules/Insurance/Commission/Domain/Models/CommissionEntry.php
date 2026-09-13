<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Commission\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Design §2.4 commission_entries, states §5.6. Clawbacks are negative entries (amount and withholding), netted on the statement.
 * Net payable to the beneficiary = amount − withholding. One trigger yields one entry per beneficiary (seller and overrides, slice D5).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $branch_id
 * @property string $agent_id
 * @property string $policy_id
 * @property string|null $receipt_allocation_id
 * @property string|null $policy_transaction_id
 * @property string|null $commission_plan_id
 * @property string|null $scheme_id
 * @property string|null $rule_id
 * @property string $beneficiary_role direct|override
 * @property string|null $level_code
 * @property string $kind earned|clawback|bonus
 * @property int $base_minor
 * @property int|null $rate_bp
 * @property int $amount_minor
 * @property int $withholding_minor
 * @property string $currency
 * @property CarbonImmutable $earned_on
 * @property string $status conditional|accrued|approved|paid|reversed
 * @property string|null $statement_id
 * @property CarbonImmutable|null $paid_on
 */
final class CommissionEntry extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    public const UPDATED_AT = null;

    protected $table = 'commission_entries';
    protected $guarded = [];
    protected $casts = ['earned_on' => 'immutable_date', 'paid_on' => 'immutable_date', 'base_minor' => 'int', 'rate_bp' => 'int', 'amount_minor' => 'int', 'withholding_minor' => 'int'];
}
