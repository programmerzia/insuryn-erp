<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Insurance\Product\Application\Templates\ProductClassTemplates;

/**
 * Phase 3 demo configuration shared by the demo seeders (DemoBusinessSeeder, PartADemoSeeder, DistributionDemoSeeder): the risk schema and
 * coverages of each MVP product class (slice R1). The definitions are the product class templates the setup wizard also offers (gap fix GA-18).
 * Illustrative, not a regulator's form: review with underwriting before real use.
 */
final class DemoRatingCatalogue
{
    /**
     * The product version terms that give a demo product its class, risk schema and coverages (merge into ProductCatalogue::addVersion terms).
     *
     * @return array{class_code: string, risk_schema: list<array<string, mixed>>, coverage_definitions: list<array<string, mixed>>}
     */
    public static function versionTerms(string $classCode): array
    {
        return ProductClassTemplates::versionTerms($classCode);
    }

    /** @return list<array<string, mixed>> */
    public static function riskSchema(string $classCode): array
    {
        return ProductClassTemplates::riskSchema($classCode);
    }

    /** @return list<array<string, mixed>> */
    public static function coverages(string $classCode): array
    {
        return ProductClassTemplates::coverages($classCode);
    }
}
