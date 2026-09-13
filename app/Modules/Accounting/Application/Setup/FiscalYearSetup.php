<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Setup;

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
 * Setup wizard step 2 (session S1): the primary book (LOCAL) and the first fiscal year of twelve open monthly periods, in the company's base
 * currency. Opening is done once; saving again only changes the base currency, and only while nothing has been posted. ASSUMPTION A-27:
 * the Finance Manager owns the fiscal calendar (periods.lock).
 */
final class FiscalYearSetup
{
    public const PERMISSION = 'periods.lock';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /** @return int periods created (0 when the year was already open) */
    public function open(CarbonImmutable $firstMonth, string $baseCurrency, string $actorUserId): int
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION);

        return DB::transaction(function () use ($firstMonth, $baseCurrency, $actorUserId): int {
            $tenantId = TenantContext::id();
            $entity = DB::table('legal_entities')->orderBy('created_at')->lockForUpdate()->first(['id', 'base_currency'])
                ?? throw new BusinessRuleViolation('SETUP_COMPANY_MISSING', 'Save the company first: the fiscal year belongs to it.');
            $entityId = (string) $entity->id;
            if ($entity->base_currency !== $baseCurrency) {
                if (DB::table('journals')->where('entity_id', $entityId)->exists()) {
                    throw new BusinessRuleViolation('SETUP_CURRENCY_LOCKED', 'The base currency cannot change once anything has been posted.');
                }
                DB::table('legal_entities')->where('id', $entityId)->update(['base_currency' => $baseCurrency, 'updated_at' => now()]);
                DB::table('tenants')->where('id', $tenantId)->update(['base_currency' => $baseCurrency, 'updated_at' => now()]);
            }
            if (DB::table('fiscal_periods')->where('entity_id', $entityId)->exists()) {
                return 0;
            }
            $bookId = DB::table('books')->where('is_primary', true)->value('id');
            if (! is_string($bookId)) {
                $bookId = (string) Str::uuid7();
                DB::table('books')->insert(['id' => $bookId, 'tenant_id' => $tenantId, 'code' => 'LOCAL', 'name' => 'Local GAAP', 'is_primary' => true]);
            }
            $start = $firstMonth->startOfMonth();
            for ($period = 1; $period <= 12; $period++) {
                $month = $start->addMonths($period - 1);
                DB::table('fiscal_periods')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'entity_id' => $entityId, 'book_id' => $bookId,
                    'year' => $start->year, 'period' => $period, 'starts' => $month->toDateString(), 'ends' => $month->endOfMonth()->toDateString(), 'status' => 'open']);
            }
            DB::table('tenants')->where('id', $tenantId)->update(['fiscal_year_start_month' => $start->month, 'updated_at' => now()]);
            $this->audit->record('setup.fiscal_year_opened', AuditSubject::of('legal_entity', $entityId), null,
                ['first_month' => $start->format('Y-m'), 'base_currency' => $baseCurrency, 'periods' => 12], null, self::PERMISSION, Actor::user($actorUserId));

            return 12;
        });
    }
}
