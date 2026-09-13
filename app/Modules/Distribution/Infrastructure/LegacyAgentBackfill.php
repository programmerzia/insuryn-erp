<?php

declare(strict_types=1);

namespace App\Modules\Distribution\Infrastructure;

use App\Modules\Distribution\Application\ChannelDirectory;
use App\Modules\Distribution\Domain\Enums\ChannelType;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice D1 data migration: Phase 1 agent rows become producers of type agent in each tenant's standard agency channel, keeping id, party,
 * code, branch, parent and plan (the parent as a column while the producers table has one, as a hierarchy row from the joining day after slice D3). `inactive` becomes `suspended` (not allowed to sell, can be reactivated); joined_on is the day the agent
 * was created. Runs per tenant under row-level security (TenantContext::run), like every data migration.
 */
final class LegacyAgentBackfill
{
    /** @param literal-string $sourceTable a table shaped like the Phase 1 `agents` table */
    public static function copy(string $sourceTable): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $sourceTable) !== 1) {
            throw new \InvalidArgumentException("Invalid table name: {$sourceTable}");
        }

        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenant) {
            TenantContext::run((string) $tenant, fn () => self::copyTenant($sourceTable, (string) $tenant));
        }
    }

    /** @param literal-string $sourceTable */
    private static function copyTenant(string $sourceTable, string $tenantId): void
    {
        // Inside the tenant context: the Phase 1 table has forced row-level security, so its rows are invisible without it.
        if (DB::table($sourceTable)->where('tenant_id', $tenantId)->exists()) {
            $channelId = app(ChannelDirectory::class)->standard(ChannelType::Agency);
            $parentColumn = Schema::hasColumn('producers', 'parent_agent_id');
            $parentInsert = $parentColumn ? ', parent_agent_id' : '';
            DB::insert(<<<SQL
                INSERT INTO producers (id, tenant_id, party_id, code, type, channel_id, branch_id{$parentInsert}, commission_plan_id, status, joined_on, created_at, updated_at)
                SELECT id, tenant_id, party_id, code, 'agent', ?, branch_id{$parentInsert}, commission_plan_id,
                       CASE status WHEN 'inactive' THEN 'suspended' ELSE status END, (created_at AT TIME ZONE 'UTC')::date, created_at, updated_at
                FROM {$sourceTable} WHERE tenant_id = ?
                SQL, [$channelId, $tenantId]);
            if (! $parentColumn) {
                DB::insert(<<<SQL
                    INSERT INTO producer_hierarchy (id, tenant_id, producer_id, parent_producer_id, effective_from, created_at, updated_at)
                    SELECT gen_random_uuid(), tenant_id, id, parent_agent_id, (created_at AT TIME ZONE 'UTC')::date, now(), now()
                    FROM {$sourceTable} WHERE tenant_id = ?
                    SQL, [$tenantId]);
            }
        }
    }
}
