<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Application;

use App\Modules\Distribution\Application\Compensation\CompensationSchemeDirectory;
use App\Modules\Insurance\Product\Domain\DutyProfile;
use App\Modules\Insurance\Product\Domain\Enums\CoverageBasis;
use App\Modules\Insurance\Product\Domain\Enums\PremiumRecognition;
use App\Modules\Insurance\Product\Domain\Models\Coverage;
use App\Modules\Insurance\Product\Domain\Models\Product;
use App\Modules\Insurance\Product\Domain\Models\ProductClass;
use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
use App\Modules\Insurance\Product\Domain\Risk\RiskSchema;
use App\Modules\Insurance\Product\Domain\Risk\RiskSchemaInvalid;
use BackedEnum;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Product catalogue (design §2.4, spec §4): products and their effective-dated, immutable versions.
 * A version is in force on [effective_from, effective_to); versions of a product never overlap (also a
 * database exclusion constraint).
 */
final class ProductCatalogue
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /** @param string|null $insuranceClass life | non_life; null = from the line of business (A-17, erp.products.life_lobs) */
    public function createProduct(string $code, string $name, string $lob, string $actorUserId, ?string $insuranceClass = null): Product
    {
        $this->permissions->authorize($actorUserId, 'product.manage');
        /** @var list<string> $lifeLobs */
        $lifeLobs = config('erp.products.life_lobs', ['life']);
        $insuranceClass ??= in_array(strtolower($lob), array_map('strtolower', $lifeLobs), true) ? 'life' : 'non_life';
        if (! in_array($insuranceClass, ['life', 'non_life'], true)) {
            throw new BusinessRuleViolation('INVALID_INSURANCE_CLASS', "Insurance class {$insuranceClass} is not life or non_life.");
        }

        return DB::transaction(function () use ($code, $name, $lob, $insuranceClass, $actorUserId): Product {
            $product = Product::query()->create(['code' => $code, 'name' => $name, 'lob' => $lob, 'insurance_class' => $insuranceClass, 'status' => 'active']);
            $this->audit->record('product.created', AuditSubject::of('product', $product->id), null, ['code' => $code, 'name' => $name, 'lob' => $lob, 'insurance_class' => $insuranceClass],
                null, 'product.manage', Actor::user($actorUserId));

            return $product;
        });
    }

    /**
     * @param array{effective_from: string, effective_to?: string|null, term_months: int, earning_method: string, short_rate_table?: array<int, mixed>|null,
     *   tax_profile: array{tax_type?: string|null, jurisdiction?: string|null, inclusive?: bool, refund_tax_on_cancellation?: bool},
     *   commission_plan_id?: string|null, compensation_scheme_id?: string|null, posting_rule_set?: string|null, coverages?: list<array<string, mixed>>,
     *   class_code?: string|null, risk_schema?: list<array<string, mixed>>|null, duty_profile?: array<string, mixed>|null, document_set_id?: string|null,
     *   allow_short_period?: bool, min_premium_minor?: int|null, recognise_at?: string, allow_credit_issue?: bool, rating_plan_id?: string|null,
     *   coverage_definitions?: list<array<string, mixed>>} $terms Phase 3 keys (slice R1) are optional; see configureRating() and addCoverage()
     */
    public function addVersion(string $productId, array $terms, string $actorUserId): ProductVersion
    {
        $this->permissions->authorize($actorUserId, 'product.manage');

        return DB::transaction(function () use ($productId, $terms, $actorUserId): ProductVersion {
            $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            if (($terms['compensation_scheme_id'] ?? null) !== null && ! app(CompensationSchemeDirectory::class)->exists($terms['compensation_scheme_id'])) {
                throw new BusinessRuleViolation('COMPENSATION_SCHEME_UNKNOWN', "Compensation scheme {$terms['compensation_scheme_id']} does not exist.");
            }
            $this->assertNoOverlap($product->id, $terms['effective_from'], $terms['effective_to'] ?? null, null);
            $ratingTerms = $this->ratingTerms($product, $terms);
            $version = ProductVersion::query()->create([...$ratingTerms,
                'product_id' => $product->id,
                'version' => (int) ProductVersion::query()->where('product_id', $product->id)->max('version') + 1,
                'effective_from' => $terms['effective_from'], 'effective_to' => $terms['effective_to'] ?? null,
                'term_months' => $terms['term_months'], 'earning_method' => $terms['earning_method'],
                'short_rate_table' => $terms['short_rate_table'] ?? null,
                'tax_profile' => [
                    'tax_type' => $terms['tax_profile']['tax_type'] ?? null,
                    'jurisdiction' => $terms['tax_profile']['jurisdiction'] ?? null,
                    'inclusive' => (bool) ($terms['tax_profile']['inclusive'] ?? true),
                    // ASSUMPTION: A-1 / D-06 — OPEN #2 (is VAT on cancelled premium refundable?) is unanswered, so the
                    // default keeps the design's POLICY_CANCELLED line 2 (refund the tax). Set false per product version.
                    'refund_tax_on_cancellation' => (bool) ($terms['tax_profile']['refund_tax_on_cancellation'] ?? true),
                ],
                'commission_plan_id' => $terms['commission_plan_id'] ?? null, 'compensation_scheme_id' => $terms['compensation_scheme_id'] ?? null, 'posting_rule_set' => $terms['posting_rule_set'] ?? null,
                'coverages' => $terms['coverages'] ?? [],
            ]);
            $this->audit->record('product_version.created', AuditSubject::of('product', $product->id), null,
                ['version' => $version->version, 'effective_from' => $terms['effective_from'], 'effective_to' => $terms['effective_to'] ?? null, ...$ratingTerms], null, 'product.manage', Actor::user($actorUserId));
            foreach ($terms['coverage_definitions'] ?? [] as $coverage) {
                $this->createCoverage($product->id, $version, $coverage, $actorUserId);
            }

            return $version;
        });
    }

    /**
     * Sets the Phase 3 rating fields of a version that no policy uses yet (a version in use is immutable: add a new version instead).
     *
     * @param array<string, mixed> $fields any of class_code, risk_schema, duty_profile, document_set_id, allow_short_period, min_premium_minor,
     *   recognise_at, allow_credit_issue, rating_plan_id; only the given keys change
     *
     * @throws BusinessRuleViolation PRODUCT_VERSION_IN_USE, PRODUCT_CLASS_UNKNOWN, PRODUCT_CLASS_NOT_AVAILABLE, PRODUCT_CLASS_MISMATCH, RISK_SCHEMA_INVALID, DUTY_PROFILE_INVALID
     */
    public function configureRating(string $versionId, array $fields, string $actorUserId): ProductVersion
    {
        $this->permissions->authorize($actorUserId, 'product.manage');

        return DB::transaction(function () use ($versionId, $fields, $actorUserId): ProductVersion {
            $version = ProductVersion::query()->whereKey($versionId)->lockForUpdate()->firstOrFail();
            $this->assertNotInUse($version);
            $product = Product::query()->findOrFail($version->product_id);
            $changes = array_intersect_key($this->ratingTerms($product, ['class_code' => $version->class_code, 'rating_plan_id' => $version->rating_plan_id, ...$fields]), $fields);
            $before = $this->auditable($version->only(array_keys($changes)));
            $version->forceFill($changes)->save();
            $this->audit->record('product_version.rating_configured', AuditSubject::of('product', $product->id), $before,
                ['version' => $version->version, ...$changes], null, 'product.manage', Actor::user($actorUserId));

            return $version->refresh();
        });
    }

    /**
     * Adds a coverage (Phase 3 design §1) to a version that no policy uses yet.
     *
     * @param array<string, mixed> $coverage code, name_en, name_bn, basis (sum_insured|flat|per_unit|pct_of_base), mandatory?, rating_rule_ref?, limit_rule?,
     *   deductible_rule?, sort_order?
     *
     * @throws BusinessRuleViolation COVERAGE_INVALID, COVERAGE_DUPLICATE, PRODUCT_VERSION_IN_USE
     */
    public function addCoverage(string $versionId, array $coverage, string $actorUserId): Coverage
    {
        $this->permissions->authorize($actorUserId, 'product.manage');

        return DB::transaction(function () use ($versionId, $coverage, $actorUserId): Coverage {
            $version = ProductVersion::query()->whereKey($versionId)->lockForUpdate()->firstOrFail();
            $this->assertNotInUse($version);

            return $this->createCoverage($version->product_id, $version, $coverage, $actorUserId);
        });
    }

    /** Ends an open-ended (or later-ending) version so a successor can start. */
    public function endVersion(string $productId, string $versionId, string $effectiveTo, string $actorUserId): ProductVersion
    {
        $this->permissions->authorize($actorUserId, 'product.manage');

        return DB::transaction(function () use ($productId, $versionId, $effectiveTo, $actorUserId): ProductVersion {
            $version = ProductVersion::query()->where('product_id', $productId)->whereKey($versionId)->lockForUpdate()->firstOrFail();
            if (CarbonImmutable::parse($effectiveTo)->lessThanOrEqualTo($version->effective_from)) {
                throw new BusinessRuleViolation('PRODUCT_VERSION_RANGE_INVALID', 'A version must end after it starts.');
            }
            $before = $version->effective_to?->toDateString();
            $version->forceFill(['effective_to' => $effectiveTo])->save();
            $this->audit->record('product_version.ended', AuditSubject::of('product', $productId), ['effective_to' => $before], ['version' => $version->version, 'effective_to' => $effectiveTo],
                null, 'product.manage', Actor::user($actorUserId));

            return $version;
        });
    }

    /** The version in force on $date. @throws BusinessRuleViolation PRODUCT_VERSION_NOT_EFFECTIVE */
    public function versionOn(string $productId, CarbonImmutable $date): ProductVersion
    {
        $day = $date->toDateString();

        return ProductVersion::query()->where('product_id', $productId)->where('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $day))
            ->first()
            ?? throw new BusinessRuleViolation('PRODUCT_VERSION_NOT_EFFECTIVE', "Product {$productId} has no version in force on {$day}.");
    }

    /**
     * The Phase 3 columns (slice R1), validated, with their defaults for keys not given.
     *
     * @param array<string, mixed> $terms
     * @return array{class_code: string|null, risk_schema: list<array<string, mixed>>|null, duty_profile: array{exclude: list<string>}|null, document_set_id: string|null,
     *   allow_short_period: bool, min_premium_minor: int|null, recognise_at: string, allow_credit_issue: bool, rating_plan_id: string|null}
     */
    private function ratingTerms(Product $product, array $terms): array
    {
        $classCode = $terms['class_code'] ?? null;
        if ($classCode !== null) {
            $class = is_string($classCode) ? ProductClass::query()->find($classCode) : null;
            if ($class === null) {
                throw new BusinessRuleViolation('PRODUCT_CLASS_UNKNOWN', 'Product class '.(is_string($classCode) ? $classCode : '?').' does not exist.');
            }
            if ($class->status !== 'active') {
                throw new BusinessRuleViolation('PRODUCT_CLASS_NOT_AVAILABLE', "Product class {$class->code} is not available yet.");
            }
            if ($class->insurance_class !== $product->insurance_class) {
                throw new BusinessRuleViolation('PRODUCT_CLASS_MISMATCH', "Product class {$class->code} is {$class->insurance_class}; product {$product->code} is {$product->insurance_class}.");
            }
        }
        $schema = $terms['risk_schema'] ?? null;
        if ($schema !== null && ! is_array($schema)) {
            throw new RiskSchemaInvalid('A risk schema is a list of fields.');
        }
        $profile = $terms['duty_profile'] ?? null;
        if ($profile !== null && ! is_array($profile)) {
            throw new BusinessRuleViolation('DUTY_PROFILE_INVALID', 'A duty profile is {"exclude": [...]} listing vat, stamp or levy.');
        }
        $minPremium = $terms['min_premium_minor'] ?? null;
        if ($minPremium !== null && (! is_int($minPremium) || $minPremium < 0)) {
            throw new BusinessRuleViolation('MIN_PREMIUM_INVALID', 'The minimum premium is a non-negative amount in minor units.');
        }
        // ASSUMPTION: A-65 — design OPEN 3 (is premium recognised at the cover note?) defaults to 'policy'; OPEN 4 (credit issuance) defaults to not allowed.
        $recogniseAt = is_string($terms['recognise_at'] ?? null) ? PremiumRecognition::tryFrom($terms['recognise_at']) : PremiumRecognition::Policy;
        if ($recogniseAt === null) {
            throw new BusinessRuleViolation('RECOGNISE_AT_INVALID', 'recognise_at is policy or cover_note.');
        }
        $documentSet = $terms['document_set_id'] ?? null;
        $planId = $terms['rating_plan_id'] ?? null;
        if ($planId !== null) {
            // Slice R2: a version may name its rating plan; the plan must exist and be for the version's class (read as a table: rating depends on products, not the reverse).
            $planClass = is_string($planId) ? DB::table('rating_plans')->where('id', $planId)->value('class_code') : null;
            if ($planClass === null) {
                throw new BusinessRuleViolation('RATING_PLAN_UNKNOWN', 'The rating plan does not exist.');
            }
            if ($planClass !== $classCode) {
                throw new BusinessRuleViolation('RATING_PLAN_CLASS_MISMATCH', "The rating plan is for class {$planClass}, not ".(is_string($classCode) ? $classCode : 'no class').'.');
            }
        }

        return [
            'class_code' => is_string($classCode) ? $classCode : null,
            'risk_schema' => $schema === null ? null : RiskSchema::fromArray($schema)->toArray(),
            'duty_profile' => $profile === null ? null : DutyProfile::fromArray($profile)->toArray(),
            'document_set_id' => is_string($documentSet) ? $documentSet : null,
            'allow_short_period' => (bool) ($terms['allow_short_period'] ?? false),
            'min_premium_minor' => $minPremium,
            'recognise_at' => $recogniseAt->value,
            'allow_credit_issue' => (bool) ($terms['allow_credit_issue'] ?? false),
            'rating_plan_id' => is_string($planId) ? $planId : null,
        ];
    }

    /** @param array<string, mixed> $coverage */
    private function createCoverage(string $productId, ProductVersion $version, array $coverage, string $actorUserId): Coverage
    {
        $code = $coverage['code'] ?? null;
        $basis = is_string($coverage['basis'] ?? null) ? CoverageBasis::tryFrom($coverage['basis']) : null;
        $nameEn = $coverage['name_en'] ?? null;
        $nameBn = $coverage['name_bn'] ?? null;
        $ruleRef = $coverage['rating_rule_ref'] ?? null;
        $limit = $coverage['limit_rule'] ?? null;
        $deductible = $coverage['deductible_rule'] ?? null;
        $mandatory = $coverage['mandatory'] ?? false;
        $sort = $coverage['sort_order'] ?? 0;
        $unknown = array_diff(array_keys($coverage), ['code', 'name_en', 'name_bn', 'basis', 'mandatory', 'rating_rule_ref', 'limit_rule', 'deductible_rule', 'sort_order']);
        if ($unknown !== [] || ! is_string($code) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) !== 1 || $basis === null
            || ! is_string($nameEn) || trim($nameEn) === '' || ! is_string($nameBn) || trim($nameBn) === '' || ! is_bool($mandatory) || ! is_int($sort)
            || ($ruleRef !== null && ! is_string($ruleRef)) || ($limit !== null && ! is_array($limit)) || ($deductible !== null && ! is_array($deductible))) {
            throw new BusinessRuleViolation('COVERAGE_INVALID', 'A coverage needs a snake_case code, name_en, name_bn and a basis of sum_insured, flat, per_unit or pct_of_base'
                .($unknown === [] ? '.' : '; unknown settings: '.implode(', ', $unknown).'.'));
        }
        if (Coverage::query()->where('product_version_id', $version->id)->where('code', $code)->exists()) {
            throw new BusinessRuleViolation('COVERAGE_DUPLICATE', "Version {$version->version} already has coverage {$code}.");
        }
        $row = Coverage::query()->create([
            'product_version_id' => $version->id, 'code' => $code, 'name_en' => $nameEn, 'name_bn' => $nameBn, 'mandatory' => $mandatory, 'basis' => $basis,
            'rating_rule_ref' => $ruleRef, 'limit_rule' => $limit, 'deductible_rule' => $deductible, 'sort_order' => $sort,
        ]);
        $this->audit->record('product_version.coverage_added', AuditSubject::of('product', $productId), null,
            ['version' => $version->version, 'code' => $code, 'basis' => $basis->value, 'mandatory' => $mandatory], null, 'product.manage', Actor::user($actorUserId));

        return $row;
    }

    private function assertNotInUse(ProductVersion $version): void
    {
        if (DB::table('policies')->where('product_version_id', $version->id)->exists()) {
            throw new BusinessRuleViolation('PRODUCT_VERSION_IN_USE', "Version {$version->version} already has policies; add a new version instead.");
        }
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed> enum values as their strings, for the audit trail
     */
    private function auditable(array $values): array
    {
        return array_map(fn (mixed $value): mixed => $value instanceof BackedEnum ? $value->value : $value, $values);
    }

    private function assertNoOverlap(string $productId, string $from, ?string $to, ?string $exceptVersionId): void
    {
        $overlapping = ProductVersion::query()->where('product_id', $productId)
            ->when($exceptVersionId !== null, fn ($q) => $q->whereKeyNot($exceptVersionId))
            ->when($to !== null, fn ($q) => $q->where('effective_from', '<', $to))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $from))
            ->exists();
        if ($overlapping) {
            throw new BusinessRuleViolation('PRODUCT_VERSION_OVERLAP', "Product {$productId} already has a version in force during {$from}–".($to ?? 'open').'.');
        }
    }
}
