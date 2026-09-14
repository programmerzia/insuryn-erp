<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Insurance\Rating\Application\DutyBook;
use App\Modules\Insurance\Rating\Application\RatingPlanService;
use App\Modules\Insurance\Rating\Application\Templates\TariffTemplates;

/**
 * Phase 3 demo tariffs (slice R3): active rating plans for motor, fire, marine cargo and misc, and the duties on premium, for the demo tenants.
 * The definitions are the tariff templates a new tenant also starts from in the setup wizard (gap fix GA-18, `TariffTemplates`).
 *
 * EVERY VALUE HERE IS AN ILLUSTRATIVE PLACEHOLDER — not an IDRA tariff or NBR duty. Plans carry `verify = true`, duties `verify = true` and
 * `source = placeholder_verify`; the list is in docs/PROGRESS.md (R3, "Placeholder values to verify"). One user drafts, another approves and
 * activates (maker ≠ checker).
 */
final class DemoRatingPlans
{
    public const SOURCE = TariffTemplates::SOURCE;

    /** Records the duties and drafts, approves and activates the four plans. */
    public static function seed(string $makerUserId, string $checkerUserId, string $effectiveFrom = '2026-01-01'): void
    {
        foreach (self::duties($effectiveFrom) as $duty) {
            app(DutyBook::class)->record($duty, $makerUserId);
        }
        $plans = app(RatingPlanService::class);
        foreach (self::plans($effectiveFrom) as $definition) {
            $plan = $plans->createFromDefinition($definition, $makerUserId);
            $plans->approve($plan->id, $checkerUserId);
            $plans->activate($plan->id, $checkerUserId);
        }
    }

    /** @return list<array<string, mixed>> */
    public static function duties(string $from = '2026-01-01'): array
    {
        return TariffTemplates::duties($from);
    }

    /** @return list<array<string, mixed>> */
    public static function plans(string $from = '2026-01-01'): array
    {
        return TariffTemplates::plans($from);
    }
}
