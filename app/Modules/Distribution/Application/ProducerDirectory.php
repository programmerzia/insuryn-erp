<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application;

use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Support\Facades\DB;

/** Read-only producer lookups for other contexts (Insurance commission, collections, policy screens). */
final class ProducerDirectory
{
    private const COLUMNS = ['p.id', 'p.party_id', 'p.code', 'p.type', 'p.status', 'p.channel_id', 'p.branch_id', 'p.commission_plan_id', 'p.joined_on'];

    public function find(string $producerId): ?ProducerSummary
    {
        $row = $this->query()->where('p.id', $producerId)->first();

        return $row === null ? null : ProducerSummary::fromRow($row);
    }

    /** @throws RecordsNotFoundException (rendered as 404) */
    public function get(string $producerId): ProducerSummary
    {
        return $this->find($producerId) ?? throw new RecordsNotFoundException("Producer {$producerId} does not exist.");
    }

    /** Locks the producer row for the rest of the transaction (serialises work per producer, e.g. agent cash deposits). */
    public function lock(string $producerId): ProducerSummary
    {
        DB::table('producers')->where('id', $producerId)->lockForUpdate()->value('id');
        $row = $this->query()->where('p.id', $producerId)->first();

        return $row === null ? throw new RecordsNotFoundException("Producer {$producerId} does not exist.") : ProducerSummary::fromRow($row);
    }

    /**
     * @param string|null $type null = every type
     * @return list<ProducerSummary>
     */
    public function all(?string $type = null): array
    {
        return array_values($this->query()->when($type !== null, fn ($q) => $q->where('p.type', $type))->orderBy('p.code')->get()
            ->map(fn (\stdClass $row): ProducerSummary => ProducerSummary::fromRow($row))->all());
    }

    /** Producers with today's parent from the effective-dated hierarchy (slice D3). */
    private function query(): \Illuminate\Database\Query\Builder
    {
        $today = now()->toDateString();

        return DB::table('producers as p')->select(self::COLUMNS)->selectSub(fn ($q) => $q->from('producer_hierarchy as h')->whereColumn('h.producer_id', 'p.id')
            ->where('h.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('h.effective_to')->orWhere('h.effective_to', '>', $today))
            ->select('h.parent_producer_id')->limit(1), 'parent_producer_id');
    }
}
