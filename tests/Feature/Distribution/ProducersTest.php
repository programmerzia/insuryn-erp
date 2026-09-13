<?php

declare(strict_types=1);

use App\Modules\Distribution\Application\CreateProducer;
use App\Modules\Distribution\Application\ProducerDirectory;
use App\Modules\Distribution\Application\ProducerService;
use App\Modules\Distribution\Infrastructure\LegacyAgentBackfill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Distribution design note §1 (slice D1): every way business reaches the insurer is a producer in a channel. Phase 1 `agents` became
 * `producers` of type agent in the tenant's agency channel, keeping their ids, so `policies.agent_id`, commission entries, agent cash and the
 * `dim_agent` dimension keep pointing at the same rows (they now mean producer).
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
});

it('replaced the agents table by producers of type agent in the agency channel', function (): void {
    expect(Schema::hasTable('agents'))->toBeFalse()
        ->and(Schema::hasColumns('producers', ['type', 'channel_id', 'employee_id', 'joined_on', 'terminated_on', 'termination_reason']))->toBeTrue();

    $producer = ($this->in)(fn () => app(ProducerDirectory::class)->find($this->world['agent_id']));
    expect($producer?->type)->toBe('agent')->and($producer?->code)->toBe('AG-001')->and($producer?->status)->toBe('active')
        ->and(($this->in)(fn () => DB::table('channels')->where('id', $producer?->channelId)->first(['code', 'type'])))->toEqual((object) ['code' => 'AGENCY', 'type' => 'agency']);

    $journalAgent = ($this->in)(fn () => DB::table('journal_lines')->whereNotNull('dim_agent')->value('dim_agent'));
    if ($journalAgent !== null) {
        expect($journalAgent)->toBe($this->world['agent_id']);
    }
});

it('refuses producer types, statuses and channels the design does not know', function (): void {
    ($this->in)(function (): void {
        $row = ['tenant_id' => $this->ctx['tenant_id'], 'party_id' => (string) Str::uuid7(), 'code' => 'X-1', 'branch_id' => $this->ctx['branch_id'],
            'channel_id' => DB::table('channels')->value('id'), 'created_at' => now(), 'updated_at' => now()];
        expect(fn () => DB::table('producers')->insert([...$row, 'id' => (string) Str::uuid7(), 'type' => 'salesman']))->toThrow(Illuminate\Database\QueryException::class);
        expect(fn () => DB::table('producers')->insert([...$row, 'id' => (string) Str::uuid7(), 'type' => 'agent', 'status' => 'inactive']))->toThrow(Illuminate\Database\QueryException::class);
        expect(fn () => DB::table('channels')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'code' => 'TV', 'name' => 'TV', 'type' => 'television']))
            ->toThrow(Illuminate\Database\QueryException::class);
    });
});

it('puts each producer type in its default channel, creating the channel once', function (): void {
    $party = fn (string $name): string => ($this->in)(function () use ($name): string {
        DB::table('parties')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'kind' => 'individual', 'display_name' => $name, 'status' => 'active']);

        return $id;
    });
    $service = app(ProducerService::class);
    $create = fn (string $type, string $code) => ($this->in)(fn () => $service->create(new CreateProducer($party($code), $code, $type, $this->ctx['branch_id'],
        joinedOn: CarbonImmutable::parse('2026-09-01')), $this->world['admin']));

    $bdo = $create('bdo', 'BDO-1');
    $broker = $create('broker', 'BRK-1');
    $agency = $create('agency_org', 'AGY-1');
    $secondBdo = $create('bdo', 'BDO-2');

    $channelOf = fn ($producer): array => (array) ($this->in)(fn () => DB::table('channels')->where('id', $producer->channelId)->first(['code', 'type']));
    expect($channelOf($bdo))->toBe(['code' => 'BDO', 'type' => 'bdo'])
        ->and($channelOf($broker))->toBe(['code' => 'BROKER', 'type' => 'broker'])
        ->and($channelOf($agency))->toBe(['code' => 'AGENCY', 'type' => 'agency'])
        ->and($secondBdo->channelId)->toBe($bdo->channelId)
        ->and($bdo->joinedOn)->toBe('2026-09-01')
        ->and(($this->in)(fn () => DB::table('audit_events')->where('action', 'producer.created')->where('object_id', $bdo->id)->count()))->toBe(1);
});

it('copies legacy agent rows into producers, keeping ids, parents and dates', function (): void {
    DB::statement('CREATE TABLE legacy_agents_fixture (id uuid, tenant_id uuid, party_id uuid, code varchar, parent_agent_id uuid, branch_id uuid, commission_plan_id uuid, status varchar, created_at timestamptz, updated_at timestamptz)');
    try {
        [$leader, $member] = [(string) Str::uuid7(), (string) Str::uuid7()];
        $legacy = fn (string $id, string $code, ?string $parent, string $status, string $created): array => ['id' => $id, 'tenant_id' => $this->ctx['tenant_id'], 'party_id' => (string) Str::uuid7(),
            'code' => $code, 'parent_agent_id' => $parent, 'branch_id' => $this->ctx['branch_id'], 'commission_plan_id' => null, 'status' => $status, 'created_at' => $created, 'updated_at' => $created];
        DB::table('legacy_agents_fixture')->insert([$legacy($leader, 'OLD-1', null, 'active', '2026-03-05 10:00:00+00'), $legacy($member, 'OLD-2', $leader, 'inactive', '2026-04-10 10:00:00+00')]);

        LegacyAgentBackfill::copy('legacy_agents_fixture');

        // Since slice D3 the parent lives in the effective-dated hierarchy, from the day the agent joined.
        $rows = ($this->in)(fn () => DB::table('producers as p')->join('channels as c', 'c.id', '=', 'p.channel_id')
            ->leftJoin('producer_hierarchy as h', fn ($j) => $j->on('h.producer_id', '=', 'p.id')->whereColumn('h.effective_from', 'p.joined_on'))
            ->whereIn('p.id', [$leader, $member])->orderBy('p.code')
            ->get(['p.id', 'p.type', 'p.status', 'h.parent_producer_id as parent_agent_id', 'p.joined_on', 'c.code as channel'])->map(fn (object $r): array => (array) $r)->all());
        expect($rows)->toBe([
            ['id' => $leader, 'type' => 'agent', 'status' => 'active', 'parent_agent_id' => null, 'joined_on' => '2026-03-05', 'channel' => 'AGENCY'],
            ['id' => $member, 'type' => 'agent', 'status' => 'suspended', 'parent_agent_id' => $leader, 'joined_on' => '2026-04-10', 'channel' => 'AGENCY'],
        ]);
    } finally {
        DB::statement('DROP TABLE legacy_agents_fixture');
    }
});
