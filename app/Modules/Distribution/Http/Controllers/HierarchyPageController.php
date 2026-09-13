<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Distribution\Application\Hierarchy\HierarchyQuery;
use App\Modules\Distribution\Application\Hierarchy\HierarchyService;
use App\Modules\Distribution\Application\ProducerDirectory;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Distribution design note §6 "hierarchy tree with drag-transfer + effective date" (slice D8): the tree on a date and dated moves. */
final class HierarchyPageController
{
    private const AREA = ['agent.manage', 'commission.manage_plans', 'commission.approve', 'reports.financial'];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ProducerDirectory $producers,
        private readonly HierarchyQuery $hierarchy,
    ) {}

    public function index(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $on = CarbonImmutable::parse((string) $request->query('on', 'today'));
        $schemes = DB::table('compensation_schemes')->orderBy('code')->get(['id', 'code', 'name']);
        $schemeId = (string) $request->query('scheme', (string) ($schemes->first()->id ?? ''));
        $names = DB::table('parties')->pluck('display_name', 'id');

        $nodes = [];
        foreach ($this->producers->all() as $producer) {
            if ($producer->status === 'terminated') {
                continue;
            }
            $position = $this->hierarchy->positionAt($producer->id, $on);
            $nodes[] = ['id' => $producer->id, 'code' => $producer->code, 'name' => (string) ($names[$producer->partyId] ?? ''), 'type' => $producer->type, 'status' => $producer->status,
                'level' => $position?->level_code, 'parent_id' => $position?->parent_producer_id === null ? null : (string) $position->parent_producer_id];
        }

        return Inertia::render('distribution/hierarchy/Index', [
            'on' => $on->toDateString(),
            'schemes' => $schemes->map(fn (object $s): array => (array) $s)->values()->all(),
            'scheme' => $schemeId === '' ? null : $schemeId,
            'levels' => DB::table('hierarchy_levels')->where('scheme_id', $schemeId)->orderBy('rank')->get(['level_code', 'rank', 'label'])->map(fn (object $l): array => (array) $l)->values()->all(),
            'nodes' => $nodes,
            'can' => ['move' => $this->permissions->has($actor, 'agent.manage')],
        ]);
    }

    public function move(Request $request, HierarchyService $service): RedirectResponse
    {
        /** @var array{producer_id: string, parent_id?: string|null, level_code?: string|null, effective_from: string} $data */
        $data = $request->validate(['producer_id' => ['required', 'uuid', Rule::exists('producers', 'id')], 'parent_id' => ['nullable', 'uuid', Rule::exists('producers', 'id')],
            'level_code' => ['nullable', 'string', 'max:32'], 'effective_from' => ['required', 'date_format:Y-m-d']]);
        $from = CarbonImmutable::parse($data['effective_from']);
        $parentId = ($data['parent_id'] ?? null) ?: null;
        $service->place($data['producer_id'], $parentId, ($data['level_code'] ?? null) ?: null, $from, PageSupport::actor($request));
        $code = (string) DB::table('producers')->where('id', $data['producer_id'])->value('code');
        $parent = $parentId === null ? null : (string) DB::table('producers')->where('id', $parentId)->value('code');

        return back()->with('status', $parent === null ? "{$code} reports to nobody from {$from->format('j M Y')}." : "{$code} moves under {$parent} from {$from->format('j M Y')}.");
    }
}
