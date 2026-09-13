<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Application;

use App\Modules\Insurance\Product\Domain\Models\Product;
use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
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
     *   commission_plan_id?: string|null, posting_rule_set?: string|null, coverages?: list<array<string, mixed>>} $terms
     */
    public function addVersion(string $productId, array $terms, string $actorUserId): ProductVersion
    {
        $this->permissions->authorize($actorUserId, 'product.manage');

        return DB::transaction(function () use ($productId, $terms, $actorUserId): ProductVersion {
            $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            $this->assertNoOverlap($product->id, $terms['effective_from'], $terms['effective_to'] ?? null, null);
            $version = ProductVersion::query()->create([
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
                'commission_plan_id' => $terms['commission_plan_id'] ?? null, 'posting_rule_set' => $terms['posting_rule_set'] ?? null,
                'coverages' => $terms['coverages'] ?? [],
            ]);
            $this->audit->record('product_version.created', AuditSubject::of('product', $product->id), null,
                ['version' => $version->version, 'effective_from' => $terms['effective_from'], 'effective_to' => $terms['effective_to'] ?? null], null, 'product.manage', Actor::user($actorUserId));

            return $version;
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
