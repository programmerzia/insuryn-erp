<?php

declare(strict_types=1);

namespace App\Modules\Platform\Tenancy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The one place that says what day it is for the business (slice 2.1b, DECISION D-54, CQ-H2): every business date — default dates on
 * forms, value and approval dates, "as of" defaults, the current fiscal period, renewal T-45, dunning, grace and lapse, quotation, cover note
 * and licence expiry, the nightly runs — follows the legal entity's time zone (`legal_entities.timezone`, default Asia/Dhaka). Technical
 * timestamps (created_at, audit occurred_at, decided_at, …) stay UTC and keep using `now()`.
 *
 * A business date is returned as a date at midnight in the application zone (UTC), as every business date read from the database is,
 * so it compares and formats like them: at 2026-09-30 19:30 UTC, today() in Dhaka is 2026-10-01 00:00 (UTC).
 *
 * Which zone: the given entity's; without an entity, the tenant's first legal entity (the company of the setup wizard); a tenant without an
 * entity, the tenant's `timezone`; outside a tenant (or with an unknown zone name), `erp.business_clock.default_timezone`. ASSUMPTION A-151.
 */
final class BusinessClock
{
    public function today(?string $entityId = null): CarbonImmutable
    {
        return CarbonImmutable::parse($this->now($entityId)->toDateString());
    }

    /** The current instant on the entity's wall clock (for display; store timestamps in UTC). */
    public function now(?string $entityId = null): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone($entityId));
    }

    public function timezone(?string $entityId = null): string
    {
        if (! TenantContext::has()) {
            return self::defaultTimezone();
        }
        $zone = $entityId !== null
            ? DB::table('legal_entities')->where('id', $entityId)->value('timezone')
            : DB::table('legal_entities')->orderBy('created_at')->orderBy('id')->value('timezone');
        if ($zone === null && $entityId === null) {
            $zone = DB::table('tenants')->where('id', TenantContext::id())->value('timezone');
        }

        return self::valid($zone) ? $zone : self::defaultTimezone();
    }

    public static function defaultTimezone(): string
    {
        $zone = config('erp.business_clock.default_timezone');

        return self::valid($zone) ? $zone : 'Asia/Dhaka';
    }

    /** @phpstan-assert-if-true non-empty-string $zone */
    public static function valid(mixed $zone): bool
    {
        return is_string($zone) && in_array($zone, timezone_identifiers_list(), true);
    }
}
