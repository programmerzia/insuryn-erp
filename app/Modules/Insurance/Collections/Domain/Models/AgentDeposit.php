<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Collections\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Cash an agent collected, deposited in the company's bank (spec §4 agent cash collection with deposit reconciliation).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $branch_id
 * @property string $agent_id
 * @property string $number
 * @property int $amount_minor
 * @property string $currency
 * @property CarbonImmutable $deposited_on
 * @property string|null $bank_account_id
 * @property string|null $reference
 * @property string $recorded_by
 */
final class AgentDeposit extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'agent_deposits';
    protected $guarded = [];
    protected $casts = ['deposited_on' => 'immutable_date', 'amount_minor' => 'int'];
}
