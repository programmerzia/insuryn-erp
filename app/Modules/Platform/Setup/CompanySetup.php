<?php

declare(strict_types=1);

namespace App\Modules\Platform\Setup;

use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use App\Modules\Platform\Tenancy\BusinessClock;
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

    /**
     * @param list<array{code: string, name: string}> $branches
     * @param string|null $timezone slice 2.1b (D-54): the zone business dates follow; null keeps the entity's (a new entity: erp.business_clock.default_timezone)
     */
    public function save(string $code, string $name, array $branches, string $actorUserId, ?string $timezone = null): string
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION);
        if ($timezone !== null && ! BusinessClock::valid($timezone)) {
            throw new BusinessRuleViolation('TIMEZONE_UNKNOWN', "{$timezone} is not a time zone.");
        }

        return DB::transaction(function () use ($code, $name, $branches, $actorUserId, $timezone): string {
            $tenantId = TenantContext::id();
            $entity = DB::table('legal_entities')->orderBy('created_at')->lockForUpdate()->first(['id', 'code', 'name', 'timezone']);
            $before = $entity === null ? null : ['code' => (string) $entity->code, 'name' => (string) $entity->name, 'timezone' => (string) $entity->timezone];
            $zone = $timezone ?? ($entity === null ? BusinessClock::defaultTimezone() : (string) $entity->timezone);
            if ($entity === null) {
                $entityId = (string) Str::uuid7();
                $currency = (string) DB::table('tenants')->where('id', $tenantId)->value('base_currency');
                DB::table('legal_entities')->insert(['id' => $entityId, 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'base_currency' => $currency,
                    'timezone' => $zone, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $entityId = (string) $entity->id;
                DB::table('legal_entities')->where('id', $entityId)->update(['code' => $code, 'name' => $name, 'timezone' => $zone, 'updated_at' => now()]);
            }
            foreach ($branches as $branch) {
                $updated = DB::table('branches')->where('entity_id', $entityId)->where('code', $branch['code'])->update(['name' => $branch['name'], 'updated_at' => now()]);
                if ($updated === 0) {
                    DB::table('branches')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'entity_id' => $entityId, 'code' => $branch['code'],
                        'name' => $branch['name'], 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $this->audit->record('setup.company_saved', AuditSubject::of('legal_entity', $entityId), $before,
                ['code' => $code, 'name' => $name, 'timezone' => $zone, 'branches' => array_column($branches, 'code')], null, self::PERMISSION, Actor::user($actorUserId));

            return $entityId;
        });
    }
}
