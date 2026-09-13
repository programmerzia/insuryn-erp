<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application\Hierarchy;

use App\Modules\Distribution\Application\ProducerDirectory;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Changes to the effective-dated hierarchy (Distribution design note §1). A change from a date closes the position in force and opens a new one,
 * so transfers keep history; a second change on the same day corrects that day's position. Changes dated before an existing later change are
 * refused (change the later one first). INVARIANTS checked after writing, inside the transaction: no cycle on the change date or on any later
 * date where the hierarchy changes; a parent outranks its child wherever one scheme defines both levels. Changes are serialised per tenant.
 */
final class HierarchyService
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ProducerDirectory $producers,
        private readonly HierarchyQuery $query,
        private readonly Audit $audit,
    ) {}

    /** @throws BusinessRuleViolation AGENT_HIERARCHY_CYCLE, HIERARCHY_LATER_CHANGE, HIERARCHY_LEVEL_UNKNOWN, HIERARCHY_LEVEL_ORDER */
    public function place(string $producerId, ?string $parentId, ?string $levelCode, CarbonImmutable $from, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, 'agent.manage');

        DB::transaction(function () use ($producerId, $parentId, $levelCode, $from, $actorUserId): void {
            $before = $this->write($producerId, $parentId, $levelCode, $from);
            $this->audit->record('producer.hierarchy_changed', AuditSubject::of('producer', $producerId), $before,
                ['parent_producer_id' => $parentId, 'level_code' => $levelCode, 'effective_from' => $from->toDateString()], null, 'agent.manage', Actor::user($actorUserId));
        });
    }

    /** A new producer's first position, recorded with the producer (its creation is the audited event). */
    public function placeNew(string $producerId, ?string $parentId, CarbonImmutable $from): void
    {
        $this->write($producerId, $parentId, null, $from);
    }

    /**
     * Replaces a scheme's levels. Ranks and codes must be unique within the scheme; a level that open positions use cannot disappear unless
     * another scheme still defines it.
     *
     * @param list<array{code: string, rank: int, label: string}> $levels
     */
    public function defineLevels(string $schemeId, array $levels, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, 'commission.manage_plans');
        $codes = array_column($levels, 'code');
        $ranks = array_column($levels, 'rank');
        if ($levels === [] || count(array_unique($codes)) !== count($codes) || count(array_unique($ranks)) !== count($ranks) || array_filter($ranks, fn (int $rank): bool => $rank < 1) !== []) {
            throw new BusinessRuleViolation('HIERARCHY_LEVELS_INVALID', 'Each level needs its own code and its own rank of 1 or more.');
        }

        DB::transaction(function () use ($schemeId, $levels, $codes, $actorUserId): void {
            $this->lockTenantHierarchy();
            if (! DB::table('compensation_schemes')->where('id', $schemeId)->exists()) {
                throw new BusinessRuleViolation('COMPENSATION_SCHEME_UNKNOWN', "Compensation scheme {$schemeId} does not exist.");
            }
            $removed = DB::table('hierarchy_levels')->where('scheme_id', $schemeId)->whereNotIn('level_code', $codes)->pluck('level_code')->all();
            foreach ($removed as $code) {
                $definedElsewhere = DB::table('hierarchy_levels')->where('scheme_id', '<>', $schemeId)->where('level_code', $code)->exists();
                $inUse = DB::table('producer_hierarchy')->where('level_code', $code)->whereNull('effective_to')->exists();
                if ($inUse && ! $definedElsewhere) {
                    throw new BusinessRuleViolation('HIERARCHY_LEVEL_IN_USE', "Level {$code} is held by producers today; move them to another level first.");
                }
            }
            $before = DB::table('hierarchy_levels')->where('scheme_id', $schemeId)->orderBy('rank')->get(['level_code', 'rank', 'label'])->map(fn ($l): array => (array) $l)->all();
            DB::table('hierarchy_levels')->where('scheme_id', $schemeId)->delete();
            DB::table('hierarchy_levels')->insert(array_map(fn (array $level): array => ['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'scheme_id' => $schemeId,
                'level_code' => $level['code'], 'rank' => $level['rank'], 'label' => $level['label'], 'created_at' => now(), 'updated_at' => now()], $levels));
            $this->audit->record('hierarchy_levels.defined', AuditSubject::of('compensation_scheme', $schemeId), ['levels' => $before], ['levels' => $levels],
                null, 'commission.manage_plans', Actor::user($actorUserId));
        });
    }

    /** @return array{parent_producer_id: string|null, level_code: string|null, effective_from: string|null} the position in force before the change */
    private function write(string $producerId, ?string $parentId, ?string $levelCode, CarbonImmutable $from): array
    {
        $this->lockTenantHierarchy();
        $producer = $this->producers->get($producerId);
        if ($parentId !== null) {
            $this->producers->get($parentId);
        }
        if ($parentId === $producerId) {
            throw $this->cycle($producer->code);
        }
        if ($levelCode !== null && ! DB::table('hierarchy_levels')->where('level_code', $levelCode)->exists()) {
            throw new BusinessRuleViolation('HIERARCHY_LEVEL_UNKNOWN', "Level {$levelCode} is not defined in any compensation scheme.");
        }
        $day = $from->toDateString();
        $later = DB::table('producer_hierarchy')->where('producer_id', $producerId)->where('effective_from', '>', $day)->min('effective_from');
        if ($later !== null) {
            throw new BusinessRuleViolation('HIERARCHY_LATER_CHANGE', "{$producer->code} already has a hierarchy change on {$later}; change or remove that one first.");
        }

        $current = $this->query->positionAt($producerId, $from);
        $before = ['parent_producer_id' => $current?->parent_producer_id, 'level_code' => $current?->level_code, 'effective_from' => $current?->effective_from];
        if ($current !== null && $current->effective_from === $day) {
            DB::table('producer_hierarchy')->where('id', $current->id)->update(['parent_producer_id' => $parentId, 'level_code' => $levelCode, 'updated_at' => now()]);
        } else {
            if ($current !== null) {
                DB::table('producer_hierarchy')->where('id', $current->id)->update(['effective_to' => $day, 'updated_at' => now()]);
            }
            DB::table('producer_hierarchy')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'producer_id' => $producerId,
                'parent_producer_id' => $parentId, 'level_code' => $levelCode, 'effective_from' => $day, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertNoCycleFrom($producerId, $producer->code, $from);
        $this->assertLevelOrder($producerId, $producer->code, $from);

        return $before;
    }

    private function assertNoCycleFrom(string $producerId, string $code, CarbonImmutable $from): void
    {
        $days = [$from->toDateString(), ...DB::table('producer_hierarchy')->where('effective_from', '>', $from->toDateString())->distinct()->orderBy('effective_from')
            ->pluck('effective_from')->map(fn ($d): string => (string) $d)->all()];
        foreach ($days as $day) {
            $chain = $this->query->hierarchyAt($producerId, CarbonImmutable::parse($day));
            $above = array_slice(array_map(fn (HierarchyNode $node): string => $node->producerId, $chain), 1);
            if (in_array($producerId, $above, true) || count($chain) > 100) {
                throw $this->cycle($code);
            }
        }
    }

    private function assertLevelOrder(string $producerId, string $code, CarbonImmutable $on): void
    {
        $position = $this->query->positionAt($producerId, $on);
        $children = DB::table('producer_hierarchy as h')->join('producers as p', 'p.id', '=', 'h.producer_id')->where('h.parent_producer_id', $producerId)
            ->where('h.effective_from', '<=', $on->toDateString())->where(fn ($q) => $q->whereNull('h.effective_to')->orWhere('h.effective_to', '>', $on->toDateString()))
            ->get(['p.code', 'h.level_code']);
        $parent = $position?->parent_producer_id === null ? null : $this->query->positionAt((string) $position->parent_producer_id, $on);

        if ($position?->level_code !== null && $parent?->level_code !== null && ! $this->outranks((string) $parent->level_code, (string) $position->level_code)) {
            throw new BusinessRuleViolation('HIERARCHY_LEVEL_ORDER', "{$code} at level {$position->level_code} cannot report to a producer at level {$parent->level_code}.");
        }
        foreach ($children as $child) {
            if ($position?->level_code !== null && $child->level_code !== null && ! $this->outranks((string) $position->level_code, (string) $child->level_code)) {
                throw new BusinessRuleViolation('HIERARCHY_LEVEL_ORDER', "{$child->code} at level {$child->level_code} cannot report to {$code} at level {$position->level_code}.");
            }
        }
    }

    /** False when a scheme that defines both levels does not rank $upper above $lower; true when no scheme orders the two. */
    private function outranks(string $upper, string $lower): bool
    {
        return ! DB::table('hierarchy_levels as u')->join('hierarchy_levels as l', 'l.scheme_id', '=', 'u.scheme_id')
            ->where('u.level_code', $upper)->where('l.level_code', $lower)->whereColumn('u.rank', '<=', 'l.rank')->exists();
    }

    private function cycle(string $code): BusinessRuleViolation
    {
        return new BusinessRuleViolation('AGENT_HIERARCHY_CYCLE', "{$code} would report to itself through its own team; the hierarchy cannot loop.");
    }

    private function lockTenantHierarchy(): void
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext('producer_hierarchy:' || ?))", [TenantContext::id()]);
    }
}
