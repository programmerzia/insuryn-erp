<?php

declare(strict_types=1);

namespace App\Modules\Platform\Setup;

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Setup wizard step 1 (session S1): the company (the tenant's legal entity) and its branches. Saving again renames the company and the
 * branches by code and adds new ones; a branch left out is kept, because records may already carry it. ASSUMPTION A-27: company and
 * branches are tenant configuration, owned by the Tenant Admin (platform.manage_roles).
 */
final class CompanySetup
{
    public const PERMISSION = 'platform.manage_roles';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /** @param list<array{code: string, name: string}> $branches */
    public function save(string $code, string $name, array $branches, string $actorUserId): string
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION);

        return DB::transaction(function () use ($code, $name, $branches, $actorUserId): string {
            $tenantId = TenantContext::id();
            $entity = DB::table('legal_entities')->orderBy('created_at')->lockForUpdate()->first(['id', 'code', 'name']);
            $before = $entity === null ? null : ['code' => (string) $entity->code, 'name' => (string) $entity->name];
            if ($entity === null) {
                $entityId = (string) Str::uuid7();
                $currency = (string) DB::table('tenants')->where('id', $tenantId)->value('base_currency');
                DB::table('legal_entities')->insert(['id' => $entityId, 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'base_currency' => $currency,
                    'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $entityId = (string) $entity->id;
                DB::table('legal_entities')->where('id', $entityId)->update(['code' => $code, 'name' => $name, 'updated_at' => now()]);
            }
            foreach ($branches as $branch) {
                $updated = DB::table('branches')->where('entity_id', $entityId)->where('code', $branch['code'])->update(['name' => $branch['name'], 'updated_at' => now()]);
                if ($updated === 0) {
                    DB::table('branches')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'entity_id' => $entityId, 'code' => $branch['code'],
                        'name' => $branch['name'], 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $this->audit->record('setup.company_saved', AuditSubject::of('legal_entity', $entityId), $before,
                ['code' => $code, 'name' => $name, 'branches' => array_column($branches, 'code')], null, self::PERMISSION, Actor::user($actorUserId));

            return $entityId;
        });
    }
}
