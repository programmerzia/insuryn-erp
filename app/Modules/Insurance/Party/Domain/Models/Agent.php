<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Domain\Models;

use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Design §2.4 agents: a party in the agent role (spec §3: agents are not employees), in a branch, with an
 * optional parent agent (hierarchy for overrides) and commission plan.
 *
 * @property string $id
 * @property string $party_id
 * @property string $code
 * @property string|null $parent_agent_id
 * @property string $branch_id
 * @property string|null $commission_plan_id
 * @property string $status
 */
final class Agent extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'agents';
    protected $guarded = [];

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
