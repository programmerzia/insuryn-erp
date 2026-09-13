<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application;

use App\Modules\Distribution\Domain\Enums\ProducerStatus;
use App\Modules\Distribution\Domain\Enums\ProducerType;
use App\Modules\Distribution\Domain\Models\Producer;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Producers (Distribution design note §1): create in a channel, change branch, parent, plan or status. The Phase 1 hierarchy
 * (`parent_agent_id`) is a tree: a producer can never become its own ancestor.
 */
final class ProducerService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ChannelDirectory $channels,
        private readonly ProducerDirectory $directory,
        private readonly Audit $audit,
    ) {}

    public function create(CreateProducer $request, string $actorUserId): ProducerSummary
    {
        $this->permissions->authorize($actorUserId, 'agent.manage');
        $type = ProducerType::from($request->type);
        $status = ProducerStatus::from($request->status);

        return DB::transaction(function () use ($request, $type, $status, $actorUserId): ProducerSummary {
            if (! DB::table('parties')->where('id', $request->partyId)->lockForUpdate()->exists()) {
                throw new \Illuminate\Database\RecordsNotFoundException("Party {$request->partyId} does not exist.");
            }
            if ($request->parentProducerId !== null) {
                $this->directory->get($request->parentProducerId);
            }
            $producer = Producer::query()->create(['party_id' => $request->partyId, 'code' => $request->code, 'type' => $type->value,
                'channel_id' => $request->channelId ?? $this->channels->standard($type->defaultChannel()), 'branch_id' => $request->branchId,
                'parent_agent_id' => $request->parentProducerId, 'commission_plan_id' => $request->commissionPlanId, 'employee_id' => $request->employeeId,
                'status' => $status->value, 'joined_on' => ($request->joinedOn ?? ($status === ProducerStatus::Active ? now()->toImmutable() : null))?->toDateString()]);
            $this->audit->record('producer.created', AuditSubject::of('producer', $producer->id), null,
                ['code' => $request->code, 'type' => $type->value, 'party_id' => $request->partyId, 'branch_id' => $request->branchId, 'parent_producer_id' => $request->parentProducerId],
                null, 'agent.manage', Actor::user($actorUserId));

            return $this->directory->get($producer->id);
        });
    }

    /** @param array{parent_agent_id?: string|null, branch_id?: string, commission_plan_id?: string|null, status?: string} $changes */
    public function update(string $producerId, array $changes, string $actorUserId): ProducerSummary
    {
        $this->permissions->authorize($actorUserId, 'agent.manage');
        if (isset($changes['status'])) {
            $changes['status'] = ProducerStatus::from($changes['status'])->value;
        }

        return DB::transaction(function () use ($producerId, $changes, $actorUserId): ProducerSummary {
            $producer = Producer::query()->whereKey($producerId)->lockForUpdate()->firstOrFail();
            if (array_key_exists('parent_agent_id', $changes) && $changes['parent_agent_id'] !== null) {
                $this->assertNoCycle($producer->id, $changes['parent_agent_id']);
            }
            $fields = ['parent_agent_id', 'branch_id', 'commission_plan_id', 'status'];
            $before = $producer->only($fields);
            $producer->fill($changes)->save();
            $this->audit->record('producer.updated', AuditSubject::of('producer', $producer->id), $before, $producer->only($fields), null, 'agent.manage', Actor::user($actorUserId));

            return $this->directory->get($producer->id);
        });
    }

    /**
     * Producer ids from the direct parent up to the root.
     *
     * @return list<string>
     */
    public function ancestors(string $producerId): array
    {
        $rows = DB::select(
            'with recursive chain(id, parent_agent_id, depth) as (
                select id, parent_agent_id, 0 from producers where id = ?
                union all
                select p.id, p.parent_agent_id, c.depth + 1 from producers p join chain c on p.id = c.parent_agent_id where c.depth < 100
             )
             select id from chain where depth > 0 order by depth',
            [$producerId],
        );

        return array_values(array_map(fn (\stdClass $row): string => (string) $row->id, $rows));
    }

    private function assertNoCycle(string $producerId, string $newParentId): void
    {
        $this->directory->get($newParentId);
        if ($newParentId === $producerId || in_array($producerId, $this->ancestors($newParentId), true)) {
            throw new BusinessRuleViolation('AGENT_HIERARCHY_CYCLE', "Agent {$newParentId} is {$producerId} or reports to it; the hierarchy cannot loop.");
        }
    }
}
