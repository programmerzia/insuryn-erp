<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Http\Controllers;

use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Insurance\Product\Domain\Models\Product;
use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
use App\Modules\Insurance\Product\Http\Requests\StoreProductRequest;
use App\Modules\Insurance\Product\Http\Requests\StoreProductVersionRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductController
{
    public function __construct(private readonly ProductCatalogue $catalogue) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => Product::query()->with('versions')->orderBy('code')->get()->map(fn (Product $p): array => $this->present($p))->values()->all()]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        /** @var array{code: string, name: string, lob: string} $data */
        $data = $request->validated();
        $product = $this->catalogue->createProduct($data['code'], $data['name'], $data['lob'], self::actor($request));

        return response()->json(['data' => $this->present($product->load('versions'))], 201);
    }

    public function show(string $product): JsonResponse
    {
        return response()->json(['data' => $this->present(Product::query()->with('versions')->findOrFail($product))]);
    }

    public function storeVersion(StoreProductVersionRequest $request, string $product): JsonResponse
    {
        /** @var array{effective_from: string, effective_to?: string|null, term_months: int, earning_method: string, tax_profile: array{tax_type?: string|null, jurisdiction?: string|null, inclusive?: bool, refund_tax_on_cancellation?: bool}, commission_plan_id?: string|null, posting_rule_set?: string|null, coverages?: list<array<string, mixed>>} $data */
        $data = $request->validated();

        return response()->json(['data' => self::presentVersion($this->catalogue->addVersion($product, $data, self::actor($request)))], 201);
    }

    public function endVersion(Request $request, string $product, string $version): JsonResponse
    {
        /** @var array{effective_to: string} $data */
        $data = $request->validate(['effective_to' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => self::presentVersion($this->catalogue->endVersion($product, $version, $data['effective_to'], self::actor($request)))]);
    }

    public function resolveVersion(Request $request, string $product): JsonResponse
    {
        /** @var array{date: string} $data */
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => self::presentVersion($this->catalogue->versionOn($product, CarbonImmutable::parse($data['date'])))]);
    }

    /** @return array<string, mixed> */
    private function present(Product $product): array
    {
        return ['id' => $product->id, 'code' => $product->code, 'name' => $product->name, 'lob' => $product->lob, 'status' => $product->status,
            'versions' => $product->versions->map(fn (ProductVersion $v): array => self::presentVersion($v))->values()->all()];
    }

    /** @return array<string, mixed> */
    private static function presentVersion(ProductVersion $version): array
    {
        return [
            'id' => $version->id, 'version' => $version->version, 'effective_from' => $version->effective_from->toDateString(),
            'effective_to' => $version->effective_to?->toDateString(), 'term_months' => $version->term_months,
            'earning_method' => $version->earning_method->value, 'tax_profile' => $version->tax_profile,
            'commission_plan_id' => $version->commission_plan_id, 'posting_rule_set' => $version->posting_rule_set, 'coverages' => $version->coverages,
        ];
    }

    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
