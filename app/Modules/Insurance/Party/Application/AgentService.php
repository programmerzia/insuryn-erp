<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Application;

use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Party\Domain\Models\Agent;
use App\Modules\Insurance\Party\Domain\Models\Party;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Agents and their hierarchy (design §2.4 agents.parent_agent_id). The hierarchy is a tree: an agent can
 * never become its own ancestor.
 */
final class AgentService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly PartyService $parties,
        private readonly Audit $audit,
    ) {}

    public function create(string $partyId, string $code, string $branchId, ?string $parentAgentId, ?string $commissionPlanId, string $actorUserId): Agent
    {
        $this->permissions->authorize($actorUserId, 'agent.manage');

        return DB::transaction(function () use ($partyId, $code, $branchId, $parentAgentId, $commissionPlanId, $actorUserId): Agent {
            $party = Party::query()->whereKey($partyId)->lockForUpdate()->firstOrFail();
            if ($parentAgentId !== null) {
                Agent::query()->whereKey($parentAgentId)->firstOrFail();
            }
            $agent = Agent::query()->create(['party_id' => $party->id, 'code' => $code, 'branch_id' => $branchId,
                'parent_agent_id' => $parentAgentId, 'commission_plan_id' => $commissionPlanId, 'status' => 'active']);
            $this->parties->ensureRole($party, PartyRoleType::Agent);
            $this->audit->record('agent.created', AuditSubject::of('agent', $agent->id), null,
                ['code' => $code, 'party_id' => $partyId, 'branch_id' => $branchId, 'parent_agent_id' => $parentAgentId], null, 'agent.manage', Actor::user($actorUserId));

            return $agent;
        });
    }

    /** @param array{parent_agent_id?: string|null, branch_id?: string, commission_plan_id?: string|null, status?: string} $changes */
    public function update(string $agentId, array $changes, string $actorUserId): Agent
    {
        $this->permissions->authorize($actorUserId, 'agent.manage');

        return DB::transaction(function () use ($agentId, $changes, $actorUserId): Agent {
            $agent = Agent::query()->whereKey($agentId)->lockForUpdate()->firstOrFail();
            if (array_key_exists('parent_agent_id', $changes) && $changes['parent_agent_id'] !== null) {
                $this->assertNoCycle($agent->id, $changes['parent_agent_id']);
            }
            $before = $agent->only(['parent_agent_id', 'branch_id', 'commission_plan_id', 'status']);
            $agent->fill($changes)->save();
            $this->audit->record('agent.updated', AuditSubject::of('agent', $agent->id), $before,
                $agent->only(['parent_agent_id', 'branch_id', 'commission_plan_id', 'status']), null, 'agent.manage', Actor::user($actorUserId));

            return $agent;
        });
    }

    /**
     * Agent ids from the direct parent up to the root.
     *
     * @return list<string>
     */
    public function ancestors(string $agentId): array
    {
        $rows = DB::select(
            'with recursive chain(id, parent_agent_id, depth) as (
                select id, parent_agent_id, 0 from agents where id = ?
                union all
                select a.id, a.parent_agent_id, c.depth + 1 from agents a join chain c on a.id = c.parent_agent_id where c.depth < 100
             )
             select id from chain where depth > 0 order by depth',
            [$agentId],
        );

        /** @var list<object{id: string}> $rows */
        return array_map(fn (object $row): string => $row->id, $rows);
    }

    private function assertNoCycle(string $agentId, string $newParentId): void
    {
        Agent::query()->whereKey($newParentId)->firstOrFail();
        if ($newParentId === $agentId || in_array($agentId, $this->ancestors($newParentId), true)) {
            throw new BusinessRuleViolation('AGENT_HIERARCHY_CYCLE', "Agent {$newParentId} is {$agentId} or reports to it; the hierarchy cannot loop.");
        }
    }
}
