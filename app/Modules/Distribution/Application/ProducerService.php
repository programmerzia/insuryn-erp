<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application;

use App\Modules\Distribution\Application\Hierarchy\HierarchyNode;
use App\Modules\Distribution\Application\Hierarchy\HierarchyQuery;
use App\Modules\Distribution\Application\Hierarchy\HierarchyService;
use App\Modules\Distribution\Domain\Enums\ProducerStatus;
use App\Modules\Distribution\Domain\Enums\ProducerType;
use App\Modules\Distribution\Domain\Models\Producer;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Producers (Distribution design note §1): create in a channel and place in the hierarchy from the day they join (today for applicants);
 * change branch, plan or status. A parent changed here takes effect today; dated transfers and levels go through HierarchyService::place.
 */
final class ProducerService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ChannelDirectory $channels,
        private readonly ProducerDirectory $directory,
        private readonly HierarchyService $hierarchy,
        private readonly HierarchyQuery $hierarchyQuery,
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
            $joinedOn = $request->joinedOn ?? ($status === ProducerStatus::Active ? CarbonImmutable::today() : null);
            $producer = Producer::query()->create(['party_id' => $request->partyId, 'code' => $request->code, 'type' => $type->value,
                'channel_id' => $request->channelId ?? $this->channels->standard($type->defaultChannel()), 'branch_id' => $request->branchId,
                'commission_plan_id' => $request->commissionPlanId, 'employee_id' => $request->employeeId, 'status' => $status->value, 'joined_on' => $joinedOn?->toDateString()]);
            $this->hierarchy->placeNew($producer->id, $request->parentProducerId, $joinedOn ?? CarbonImmutable::today());
            $this->audit->record('producer.created', AuditSubject::of('producer', $producer->id), null,
                ['code' => $request->code, 'type' => $type->value, 'party_id' => $request->partyId, 'branch_id' => $request->branchId, 'parent_producer_id' => $request->parentProducerId],
                null, 'agent.manage', Actor::user($actorUserId));

            return $this->directory->get($producer->id);
        });
    }

    /** @param array{parent_agent_id?: string|null, branch_id?: string, commission_plan_id?: string|null, status?: string} $changes parent_agent_id: today's parent */
    public function update(string $producerId, array $changes, string $actorUserId): ProducerSummary
    {
        $this->permissions->authorize($actorUserId, 'agent.manage');
        if (isset($changes['status'])) {
            $changes['status'] = ProducerStatus::from($changes['status'])->value;
        }

        return DB::transaction(function () use ($producerId, $changes, $actorUserId): ProducerSummary {
            $producer = Producer::query()->whereKey($producerId)->lockForUpdate()->firstOrFail();
            if (array_key_exists('parent_agent_id', $changes)) {
                $today = CarbonImmutable::today();
                $level = $this->hierarchyQuery->positionAt($producer->id, $today)?->level_code;
                $this->hierarchy->place($producer->id, $changes['parent_agent_id'], $level === null ? null : (string) $level, $today, $actorUserId);
                unset($changes['parent_agent_id']);
            }
            $fields = ['branch_id', 'commission_plan_id', 'status'];
            $before = $producer->only($fields);
            $producer->fill($changes)->save();
            if ($producer->wasChanged()) {
                $this->audit->record('producer.updated', AuditSubject::of('producer', $producer->id), $before, $producer->only($fields), null, 'agent.manage', Actor::user($actorUserId));
            }

            return $this->directory->get($producer->id);
        });
    }

    /**
     * Producer ids from today's direct parent up to the root.
     *
     * @return list<string>
     */
    public function ancestors(string $producerId): array
    {
        return array_map(fn (HierarchyNode $node): string => $node->producerId, array_slice($this->hierarchyQuery->hierarchyAt($producerId, CarbonImmutable::today()), 1));
    }
}
