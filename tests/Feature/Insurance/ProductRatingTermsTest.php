<?php

declare(strict_types=1);

use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Insurance\Product\Domain\Enums\CoverageBasis;
use App\Modules\Insurance\Product\Domain\Enums\PremiumRecognition;
use App\Modules\Insurance\Product\Domain\Models\Coverage;
use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoRatingCatalogue;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 design §1 product model extensions (slice R1): product classes, the rating fields of a product version (class, risk schema, duty
 * profile, minimum premium, recognise_at / allow_credit_issue flags) and coverages, set through the catalogue and audited.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->manager = userWithPermissions($this->ctx['tenant_id'], ['product.manage']);
    $this->catalogue = app(ProductCatalogue::class);
    $this->product = asTenant($this->ctx['tenant_id'], fn () => $this->catalogue->createProduct('MOTOR-R1', 'Motor', 'motor', $this->manager));
    $this->terms = ['effective_from' => '2026-01-01', 'term_months' => 12, 'earning_method' => 'monthly', 'tax_profile' => ['tax_type' => 'VAT', 'jurisdiction' => 'BD', 'inclusive' => true]];
});

it('seeds the product classes: the four MVP classes active, the rest reserved for later', function (): void {
    expect(DB::table('product_classes')->where('status', 'active')->orderBy('sort_order')->pluck('code')->all())->toBe(['motor', 'fire', 'marine_cargo', 'misc'])
        ->and(DB::table('product_classes')->where('status', 'later')->orderBy('sort_order')->pluck('code')->all())->toBe(['marine_hull', 'engineering', 'health', 'life'])
        ->and(DB::table('product_classes')->where('code', 'motor')->value('name_bn'))->toBe('মোটর');
});

it('adds a version with class, risk schema, duty profile, minimum premium and coverages, audited', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $version = $this->catalogue->addVersion($this->product->id, [...$this->terms, ...DemoRatingCatalogue::versionTerms('motor'),
            'duty_profile' => ['exclude' => ['levy']], 'min_premium_minor' => 250_000, 'allow_short_period' => true], $this->manager);
        $version = ProductVersion::query()->whereKey($version->id)->firstOrFail();

        expect($version->class_code)->toBe('motor')
            ->and($version->riskSchema()->field('engine_cc')?->max)->toBe(10000)
            ->and($version->dutyProfile()->applies('levy'))->toBeFalse()
            ->and($version->min_premium_minor)->toBe(250_000)
            ->and($version->allow_short_period)->toBeTrue()
            ->and($version->recognise_at)->toBe(PremiumRecognition::Policy)
            ->and($version->allow_credit_issue)->toBeFalse()
            ->and($version->coverages)->toBe([])
            ->and($version->coverageDefinitions()->pluck('code')->all())->toBe(['own_damage', 'third_party', 'passenger_liability'])
            ->and(Coverage::query()->where('code', 'passenger_liability')->firstOrFail()->basis)->toBe(CoverageBasis::PerUnit)
            ->and(DB::table('audit_events')->where('action', 'product_version.coverage_added')->count())->toBe(3)
            ->and(json_decode((string) DB::table('audit_events')->where('action', 'product_version.created')->value('after'), true)['class_code'])->toBe('motor');
    });
});

it('keeps Phase 1 versions working without the new fields', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $version = ProductVersion::query()->whereKey($this->catalogue->addVersion($this->product->id, $this->terms, $this->manager)->id)->firstOrFail();

        expect($version->class_code)->toBeNull()
            ->and($version->riskSchema()->isEmpty())->toBeTrue()
            ->and($version->min_premium_minor)->toBeNull()
            ->and($version->recognise_at)->toBe(PremiumRecognition::Policy);
    });
});

it('refuses unknown, unavailable or mismatched classes, bad schemas, bad duty profiles and bad coverages', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $reason = fn (array $extra): string => thrownBy(fn () => $this->catalogue->addVersion($this->product->id, [...$this->terms, ...$extra], $this->manager), BusinessRuleViolation::class)->reasonCode;
        $life = $this->catalogue->createProduct('LIFE', 'Life', 'life', $this->manager);

        expect($reason(['class_code' => 'aviation']))->toBe('PRODUCT_CLASS_UNKNOWN')
            ->and($reason(['class_code' => 'engineering']))->toBe('PRODUCT_CLASS_NOT_AVAILABLE')
            ->and($reason(['risk_schema' => [['key' => 'x', 'type' => 'text']]]))->toBe('RISK_SCHEMA_INVALID')
            ->and($reason(['duty_profile' => ['exclude' => ['income_tax']]]))->toBe('DUTY_PROFILE_INVALID')
            ->and($reason(['min_premium_minor' => -1]))->toBe('MIN_PREMIUM_INVALID')
            ->and($reason(['recognise_at' => 'receipt']))->toBe('RECOGNISE_AT_INVALID')
            ->and($reason(['coverage_definitions' => [['code' => 'od', 'name_en' => 'Own damage', 'name_bn' => 'ক্ষতি', 'basis' => 'percent']]]))->toBe('COVERAGE_INVALID')
            ->and(DB::table('product_versions')->count())->toBe(0);

        DB::table('product_classes')->where('code', 'life')->update(['status' => 'active']); // a LATER class made usable, to reach the mismatch check
        expect(thrownBy(fn () => $this->catalogue->addVersion($this->product->id, [...$this->terms, 'class_code' => 'life'], $this->manager), BusinessRuleViolation::class)->reasonCode)
            ->toBe('PRODUCT_CLASS_MISMATCH')
            ->and($this->catalogue->addVersion($life->id, [...$this->terms, 'class_code' => 'life'], $this->manager)->class_code)->toBe('life');
    });
});

it('configures rating fields and adds coverages only while no policy uses the version', function (): void {
    $world = seedInsuranceWorld($this->ctx);
    asTenant($this->ctx['tenant_id'], function () use ($world): void {
        $version = $this->catalogue->configureRating($world['product_version_id'], ['class_code' => 'motor', 'risk_schema' => DemoRatingCatalogue::riskSchema('motor')], $world['admin']);
        $coverage = $this->catalogue->addCoverage($world['product_version_id'], ['code' => 'own_damage', 'name_en' => 'Own damage', 'name_bn' => 'নিজস্ব ক্ষতি', 'basis' => 'sum_insured', 'mandatory' => true], $world['admin']);

        expect($version->class_code)->toBe('motor')
            ->and($version->riskSchema()->field('vehicle_type'))->not->toBeNull()
            ->and($coverage->mandatory)->toBeTrue()
            ->and(thrownBy(fn () => $this->catalogue->addCoverage($world['product_version_id'], ['code' => 'own_damage', 'name_en' => 'Again', 'name_bn' => 'আবার', 'basis' => 'flat'], $world['admin']),
                BusinessRuleViolation::class)->reasonCode)->toBe('COVERAGE_DUPLICATE')
            ->and(DB::table('audit_events')->where('action', 'product_version.rating_configured')->count())->toBe(1);

        app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $world['product_id'], $world['policyholder_id'], null,
            CarbonImmutable::parse('2026-07-01'), 1_000_000, 'BDT', 1), $world['admin']);
        expect(thrownBy(fn () => $this->catalogue->configureRating($world['product_version_id'], ['min_premium_minor' => 1], $world['admin']), BusinessRuleViolation::class)->reasonCode)
            ->toBe('PRODUCT_VERSION_IN_USE')
            ->and(thrownBy(fn () => $this->catalogue->addCoverage($world['product_version_id'], ['code' => 'tp', 'name_en' => 'TP', 'name_bn' => 'টিপি', 'basis' => 'flat'], $world['admin']),
                BusinessRuleViolation::class)->reasonCode)->toBe('PRODUCT_VERSION_IN_USE');
    });
});

it('needs product.manage to change rating fields', function (): void {
    $stranger = userWithPermissions($this->ctx['tenant_id'], ['policy.create']);
    asTenant($this->ctx['tenant_id'], function () use ($stranger): void {
        $version = $this->catalogue->addVersion($this->product->id, $this->terms, $this->manager);

        expect(fn () => $this->catalogue->configureRating($version->id, ['class_code' => 'motor'], $stranger))->toThrow(PermissionDenied::class)
            ->and(fn () => $this->catalogue->addCoverage($version->id, ['code' => 'x', 'name_en' => 'X', 'name_bn' => 'X', 'basis' => 'flat'], $stranger))->toThrow(PermissionDenied::class);
    });
});
