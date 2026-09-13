<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Application;

use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Support\Facades\DB;

/** Read-only producer lookups for other contexts (Insurance commission, collections, policy screens). */
final class ProducerDirectory
{
    private const COLUMNS = ['id', 'party_id', 'code', 'type', 'status', 'channel_id', 'branch_id', 'parent_agent_id', 'commission_plan_id', 'joined_on'];

    public function find(string $producerId): ?ProducerSummary
    {
        $row = DB::table('producers')->where('id', $producerId)->first(self::COLUMNS);

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
        $row = DB::table('producers')->where('id', $producerId)->lockForUpdate()->first(self::COLUMNS);

        return $row === null ? throw new RecordsNotFoundException("Producer {$producerId} does not exist.") : ProducerSummary::fromRow($row);
    }

    /**
     * @param string|null $type null = every type
     * @return list<ProducerSummary>
     */
    public function all(?string $type = null): array
    {
        return array_values(DB::table('producers')->when($type !== null, fn ($q) => $q->where('type', $type))->orderBy('code')->get(self::COLUMNS)
            ->map(fn (\stdClass $row): ProducerSummary => ProducerSummary::fromRow($row))->all());
    }
}
