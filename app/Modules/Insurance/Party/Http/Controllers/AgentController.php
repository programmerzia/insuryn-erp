<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Http\Controllers;

use App\Modules\Distribution\Application\ProducerDirectory;
use App\Modules\Distribution\Application\ProducerSummary;
use App\Modules\Insurance\Party\Application\AgentService;
use App\Modules\Insurance\Party\Domain\Models\Party;
use App\Modules\Insurance\Party\Http\Requests\StoreAgentRequest;
use App\Modules\Insurance\Party\Http\Requests\UpdateAgentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentController
{
    public function __construct(private readonly AgentService $agents, private readonly ProducerDirectory $producers) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => array_map(fn (ProducerSummary $agent): array => $this->present($agent, false), $this->producers->all('agent'))]);
    }

    public function store(StoreAgentRequest $request): JsonResponse
    {
        /** @var array{party_id: string, code: string, branch_id: string, parent_agent_id?: string|null, commission_plan_id?: string|null} $data */
        $data = $request->validated();
        $agent = $this->agents->create($data['party_id'], $data['code'], $data['branch_id'], $data['parent_agent_id'] ?? null,
            $data['commission_plan_id'] ?? null, (string) $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $this->present($agent, false)], 201);
    }

    public function show(string $agent): JsonResponse
    {
        return response()->json(['data' => $this->present($this->producers->get($agent), true)]);
    }

    public function update(UpdateAgentRequest $request, string $agent): JsonResponse
    {
        /** @var array{parent_agent_id?: string|null, branch_id?: string, commission_plan_id?: string|null, status?: string} $data */
        $data = $request->validated();

        return response()->json(['data' => $this->present($this->agents->update($agent, $data, (string) $request->user()?->getAuthIdentifier()), false)]);
    }

    /** @return array<string, mixed> */
    private function present(ProducerSummary $agent, bool $withHierarchy): array
    {
        $data = ['id' => $agent->id, 'code' => $agent->code, 'party_id' => $agent->partyId, 'branch_id' => $agent->branchId,
            'parent_agent_id' => $agent->parentProducerId, 'commission_plan_id' => $agent->commissionPlanId, 'status' => $agent->status];
        if ($withHierarchy) {
            $data['ancestors'] = $this->agents->ancestors($agent->id);
            $party = Party::query()->with(['roles', 'bankAccounts'])->find($agent->partyId);
            $data['party'] = $party === null ? null : PartyController::present($party);
        }

        return $data;
    }
}
