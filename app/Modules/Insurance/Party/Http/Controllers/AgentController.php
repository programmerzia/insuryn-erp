<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Party\Http\Controllers;

use App\Modules\Insurance\Party\Application\AgentService;
use App\Modules\Insurance\Party\Domain\Models\Agent;
use App\Modules\Insurance\Party\Http\Requests\StoreAgentRequest;
use App\Modules\Insurance\Party\Http\Requests\UpdateAgentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentController
{
    public function __construct(private readonly AgentService $agents) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => Agent::query()->orderBy('code')->get()->map(fn (Agent $agent): array => $this->present($agent, false))->values()->all()]);
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
        return response()->json(['data' => $this->present(Agent::query()->with('party.roles')->findOrFail($agent), true)]);
    }

    public function update(UpdateAgentRequest $request, string $agent): JsonResponse
    {
        /** @var array{parent_agent_id?: string|null, branch_id?: string, commission_plan_id?: string|null, status?: string} $data */
        $data = $request->validated();

        return response()->json(['data' => $this->present($this->agents->update($agent, $data, (string) $request->user()?->getAuthIdentifier()), false)]);
    }

    /** @return array<string, mixed> */
    private function present(Agent $agent, bool $withHierarchy): array
    {
        $data = ['id' => $agent->id, 'code' => $agent->code, 'party_id' => $agent->party_id, 'branch_id' => $agent->branch_id,
            'parent_agent_id' => $agent->parent_agent_id, 'commission_plan_id' => $agent->commission_plan_id, 'status' => $agent->status];
        if ($withHierarchy) {
            $data['ancestors'] = $this->agents->ancestors($agent->id);
            $data['party'] = $agent->party === null ? null : PartyController::present($agent->party->loadMissing(['roles', 'bankAccounts']));
        }

        return $data;
    }
}
