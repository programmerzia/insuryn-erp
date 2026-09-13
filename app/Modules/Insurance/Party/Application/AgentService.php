<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Application;

use App\Modules\Distribution\Application\CreateProducer;
use App\Modules\Distribution\Application\ProducerService;
use App\Modules\Distribution\Application\ProducerSummary;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Party\Domain\Models\Party;
use Illuminate\Support\Facades\DB;

/**
 * Agents as Phase 1 knew them, now producers of type agent (Distribution, slice D1). Keeps the agent API and screens working and adds the
 * party's agent role (spec §3: agents are parties, not employees).
 */
final class AgentService
{
    public function __construct(
        private readonly ProducerService $producers,
        private readonly PartyService $parties,
    ) {}

    public function create(string $partyId, string $code, string $branchId, ?string $parentAgentId, ?string $commissionPlanId, string $actorUserId): ProducerSummary
    {
        return DB::transaction(function () use ($partyId, $code, $branchId, $parentAgentId, $commissionPlanId, $actorUserId): ProducerSummary {
            $agent = $this->producers->create(new CreateProducer($partyId, $code, 'agent', $branchId, parentProducerId: $parentAgentId, commissionPlanId: $commissionPlanId), $actorUserId);
            $this->parties->ensureRole(Party::query()->findOrFail($partyId), PartyRoleType::Agent);

            return $agent;
        });
    }

    /** @param array{parent_agent_id?: string|null, branch_id?: string, commission_plan_id?: string|null, status?: string} $changes */
    public function update(string $agentId, array $changes, string $actorUserId): ProducerSummary
    {
        if (($changes['status'] ?? null) === 'inactive') {
            $changes['status'] = 'suspended';
        }

        return $this->producers->update($agentId, $changes, $actorUserId);
    }

    /** @return list<string> agent ids from the direct parent up to the root */
    public function ancestors(string $agentId): array
    {
        return $this->producers->ancestors($agentId);
    }
}
