<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;

/**
 * Distribution design note §1 producers: a party that brings business in a channel (agent, agency organisation, BDO, broker, partner).
 * Phase 1 agents kept their ids (D1), so `agent_id` columns elsewhere and the `dim_agent` dimension hold producer ids.
 * `parent_agent_id` is the Phase 1 hierarchy until the effective-dated hierarchy replaces it (D3).
 *
 * @property string $id
 * @property string $party_id
 * @property string $code
 * @property string $type
 * @property string $channel_id
 * @property string $branch_id
 * @property string|null $parent_agent_id
 * @property string|null $commission_plan_id
 * @property string|null $employee_id
 * @property string $status
 * @property string|null $joined_on
 * @property string|null $terminated_on
 * @property string|null $termination_reason
 */
final class Producer extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'producers';
    protected $guarded = [];
}
