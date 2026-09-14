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

    /**
     * Gap fix GA-15: opens the fiscal year after the entity's latest one — twelve open monthly periods from the day after its last period ends, in
     * every book that has the latest year — from the close screen, by the owner of the fiscal calendar (periods.lock, A-27). Audited
     * `fiscal_year.opened`.
     *
     * ASSUMPTION A-202: a year is opened at most one year ahead — only once the latest open year has started on the business clock — so a
     * mistaken click cannot open years nobody asked for; and it never overlaps a period that exists.
     *
     * @return array{year: int, starts: string, ends: string, periods: int}
     *
     * @throws BusinessRuleViolation FISCAL_YEAR_MISSING | FISCAL_YEAR_TOO_EARLY | FISCAL_YEAR_OVERLAP
     */
    public function openNext(string $entityId, CarbonImmutable $today, string $actorUserId): array
    {
        $this->permissions->authorize($actorUserId, self::PERMISSION, \App\Modules\Platform\Authorization\AuthorizationScope::entity($entityId));

        return DB::transaction(function () use ($entityId, $today, $actorUserId): array {
            DB::table('legal_entities')->where('id', $entityId)->lockForUpdate()->value('id');
            $latest = DB::table('fiscal_periods')->where('entity_id', $entityId)->orderByDesc('ends')->first(['year', 'ends']);
            if ($latest === null) {
                throw new BusinessRuleViolation('FISCAL_YEAR_MISSING', 'There is no fiscal year to follow yet. Open the first one in the setup wizard.');
            }
            $latestYear = (int) $latest->year;
            $latestStarts = CarbonImmutable::parse((string) DB::table('fiscal_periods')->where('entity_id', $entityId)->where('year', $latestYear)->min('starts'));
            if ($today->lessThan($latestStarts)) {
                throw new BusinessRuleViolation('FISCAL_YEAR_TOO_EARLY', 'Fiscal year '.$latestYear.' has not started yet (it starts on '.$latestStarts->format('j M Y')
                    .'). The year after it can be opened once it has.');
            }
            $starts = CarbonImmutable::parse((string) $latest->ends)->addDay()->startOfMonth();
            $ends = $starts->addMonths(11)->endOfMonth();
            $overlap = DB::table('fiscal_periods')->where('entity_id', $entityId)->where('starts', '<=', $ends->toDateString())->where('ends', '>=', $starts->toDateString())->exists()
                || DB::table('fiscal_periods')->where('entity_id', $entityId)->where('year', $latestYear + 1)->exists();
            if ($overlap) {
                throw new BusinessRuleViolation('FISCAL_YEAR_OVERLAP', 'Periods from '.$starts->format('j M Y').' to '.$ends->format('j M Y').' already exist.');
            }
            $bookIds = DB::table('fiscal_periods')->where('entity_id', $entityId)->where('year', $latestYear)->distinct()->pluck('book_id')->map(fn (mixed $id): string => (string) $id)->all();
            foreach ($bookIds as $bookId) {
                for ($period = 1; $period <= 12; $period++) {
                    $month = $starts->addMonths($period - 1);
                    DB::table('fiscal_periods')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => TenantContext::id(), 'entity_id' => $entityId, 'book_id' => $bookId,
                        'year' => $latestYear + 1, 'period' => $period, 'starts' => $month->toDateString(), 'ends' => $month->endOfMonth()->toDateString(), 'status' => 'open']);
                }
            }
            $opened = ['year' => $latestYear + 1, 'starts' => $starts->toDateString(), 'ends' => $ends->toDateString(), 'periods' => 12];
            $this->audit->record('fiscal_year.opened', AuditSubject::of('legal_entity', $entityId), null, $opened + ['books' => count($bookIds)], null, self::PERMISSION, Actor::user($actorUserId));

            return $opened;
        });
    }
}
