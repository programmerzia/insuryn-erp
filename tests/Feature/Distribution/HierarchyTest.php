<?php

declare(strict_types=1);

use App\Modules\Distribution\Application\CreateProducer;
use App\Modules\Distribution\Application\Hierarchy\HierarchyQuery;
use App\Modules\Distribution\Application\Hierarchy\HierarchyService;
use App\Modules\Distribution\Application\ProducerService;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Distribution design note §1 producer_hierarchy and hierarchy_levels (slice D3): the hierarchy is effective-dated, so a transfer keeps history
 * and a payout can use the tree as it was (`hierarchyAt`). INVARIANTS: no cycles on any date, one active parent per producer at a time.
 * Levels are defined per scheme with a rank (life example FA < UM < BM); a parent outranks its children where a scheme orders both levels.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->scheme = (string) Str::uuid7();
    ($this->in)(fn () => app(HierarchyService::class)->defineLevels($this->scheme, [
        ['code' => 'FA', 'rank' => 1, 'label' => 'Financial associate'], ['code' => 'UM', 'rank' => 2, 'label' => 'Unit manager'], ['code' => 'BM', 'rank' => 3, 'label' => 'Branch manager'],
    ], $this->world['admin']));
    $this->producer = function (string $code): string {
        return ($this->in)(function () use ($code): string {
            DB::table('parties')->insert(['id' => $party = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'kind' => 'individual', 'display_name' => $code, 'status' => 'active']);

            return app(ProducerService::class)->create(new CreateProducer($party, $code, 'agent', $this->ctx['branch_id'], joinedOn: CarbonImmutable::parse('2026-01-01')), $this->world['admin'])->id;
        });
    };
    $this->place = fn (string $producer, ?string $parent, ?string $level, string $from) => ($this->in)(fn () => app(HierarchyService::class)
        ->place($producer, $parent, $level, CarbonImmutable::parse($from), $this->world['admin']));
    $this->chain = fn (string $producer, string $on): array => ($this->in)(fn (): array => array_map(fn ($node): string => "{$node->code}:{$node->levelCode}",
        app(HierarchyQuery::class)->hierarchyAt($producer, CarbonImmutable::parse($on))));
    [$this->bm, $this->um1, $this->um2, $this->fa] = [($this->producer)('BM-1'), ($this->producer)('UM-1'), ($this->producer)('UM-2'), ($this->producer)('FA-1')];
    ($this->place)($this->bm, null, 'BM', '2026-01-01');
    ($this->place)($this->um1, $this->bm, 'UM', '2026-01-01');
    ($this->place)($this->um2, $this->bm, 'UM', '2026-01-01');
    ($this->place)($this->fa, $this->um1, 'FA', '2026-01-01');
});

it('keeps history when a producer is transferred, and answers the tree as it was on a date', function (): void {
    ($this->place)($this->fa, $this->um2, 'FA', '2026-07-01');

    expect(($this->chain)($this->fa, '2026-03-15'))->toBe(['FA-1:FA', 'UM-1:UM', 'BM-1:BM'])
        ->and(($this->chain)($this->fa, '2026-06-30'))->toBe(['FA-1:FA', 'UM-1:UM', 'BM-1:BM'])
        ->and(($this->chain)($this->fa, '2026-07-01'))->toBe(['FA-1:FA', 'UM-2:UM', 'BM-1:BM'])
        ->and(($this->in)(fn () => DB::table('producer_hierarchy')->where('producer_id', $this->fa)->orderBy('effective_from')
            ->get(['parent_producer_id', 'effective_from', 'effective_to'])->map(fn ($r): array => (array) $r)->all()))->toBe([
                ['parent_producer_id' => $this->um1, 'effective_from' => '2026-01-01', 'effective_to' => '2026-07-01'],
                ['parent_producer_id' => $this->um2, 'effective_from' => '2026-07-01', 'effective_to' => null],
            ])
        ->and(($this->in)(fn () => DB::table('audit_events')->where('action', 'producer.hierarchy_changed')->where('object_id', $this->fa)->count()))->toBe(2);

    ($this->place)($this->fa, $this->um1, 'FA', '2026-07-01'); // a correction on the same day replaces that day's row
    expect(($this->chain)($this->fa, '2026-07-01'))->toBe(['FA-1:FA', 'UM-1:UM', 'BM-1:BM'])
        ->and(($this->in)(fn () => DB::table('producer_hierarchy')->where('producer_id', $this->fa)->count()))->toBe(2);
});

it('refuses a cycle on the day it would start and on any later day', function (): void {
    $refusal = fn (callable $change): string => thrownBy($change, BusinessRuleViolation::class)->reasonCode;

    expect($refusal(fn () => ($this->place)($this->bm, $this->fa, 'BM', '2026-09-01')))->toBe('AGENT_HIERARCHY_CYCLE')
        ->and($refusal(fn () => ($this->place)($this->fa, $this->fa, 'FA', '2026-09-01')))->toBe('AGENT_HIERARCHY_CYCLE');

    // UM-2 will report to FA-1 from October; FA-1 reports to UM-1 now. Moving UM-1 under UM-2 from September makes FA → UM-1 → UM-2 → FA from October.
    ($this->place)($this->um2, $this->fa, null, '2026-10-01');
    expect($refusal(fn () => ($this->place)($this->um1, $this->um2, null, '2026-09-01')))->toBe('AGENT_HIERARCHY_CYCLE');
    expect($refusal(fn () => ($this->place)($this->um2, $this->bm, 'UM', '2026-08-01')))->toBe('HIERARCHY_LATER_CHANGE'); // UM-2 already changes on 1 October
    ($this->place)($this->um1, null, 'UM', '2026-09-01');
    expect(($this->chain)($this->um2, '2026-10-02'))->toBe(['UM-2:', 'FA-1:FA', 'UM-1:UM']);
});

it('lets the database refuse two parents at once', function (): void {
    ($this->in)(function (): void {
        expect(fn () => DB::table('producer_hierarchy')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'producer_id' => $this->fa,
            'parent_producer_id' => $this->um2, 'level_code' => 'FA', 'effective_from' => '2026-03-01', 'effective_to' => null]))->toThrow(QueryException::class);
    });
});

it('keeps a parent above its children in the scheme levels, and knows only defined levels', function (): void {
    $refusal = fn (callable $change): string => thrownBy($change, BusinessRuleViolation::class)->reasonCode;

    expect($refusal(fn () => ($this->place)($this->um2, $this->fa, 'UM', '2026-09-01')))->toBe('HIERARCHY_LEVEL_ORDER')
        ->and($refusal(fn () => ($this->place)($this->um1, $this->bm, 'FA', '2026-09-01')))->toBe('HIERARCHY_LEVEL_ORDER') // FA-1 below would outrank its parent
        ->and($refusal(fn () => ($this->place)($this->fa, $this->um1, 'RM', '2026-09-01')))->toBe('HIERARCHY_LEVEL_UNKNOWN');
});

it('defines levels per scheme and refuses ambiguous ranks or removing a level in use', function (): void {
    $service = app(HierarchyService::class);
    $other = (string) Str::uuid7();
    ($this->in)(fn () => $service->defineLevels($other, [['code' => 'BDO', 'rank' => 1, 'label' => 'Business development officer'], ['code' => 'FA', 'rank' => 2, 'label' => 'Same code, other scheme']], $this->world['admin']));

    expect(thrownBy(fn () => ($this->in)(fn () => $service->defineLevels($other, [['code' => 'A', 'rank' => 1, 'label' => 'A'], ['code' => 'B', 'rank' => 1, 'label' => 'B']], $this->world['admin'])), BusinessRuleViolation::class)->reasonCode)
        ->toBe('HIERARCHY_LEVELS_INVALID')
        ->and(thrownBy(fn () => ($this->in)(fn () => $service->defineLevels($this->scheme, [['code' => 'FA', 'rank' => 1, 'label' => 'FA'], ['code' => 'BM', 'rank' => 3, 'label' => 'BM']], $this->world['admin'])), BusinessRuleViolation::class)->reasonCode)
        ->toBe('HIERARCHY_LEVEL_IN_USE')
        ->and(($this->in)(fn () => DB::table('hierarchy_levels')->where('scheme_id', $this->scheme)->orderBy('rank')->pluck('level_code')->all()))->toBe(['FA', 'UM', 'BM']);
});

it('answers a producer outside any hierarchy on a date as a chain of one', function (): void {
    expect(($this->chain)($this->fa, '2025-12-31'))->toBe(['FA-1:']);
});
