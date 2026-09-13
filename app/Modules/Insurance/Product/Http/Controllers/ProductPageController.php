<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Product\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Insurance\Product\Domain\Enums\EarningMethod;
use App\Modules\Insurance\Product\Domain\Models\Product;
use App\Modules\Insurance\Product\Domain\Models\ProductVersion;
use App\Modules\Platform\Authorization\PermissionChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Product catalogue screen (spec §4): products with their effective-dated versions; create a product or a version. */
final class ProductPageController
{
    public const AREA = ['product.manage', 'policy.create', 'reports.financial'];

    public function __construct(
        private readonly ProductCatalogue $catalogue,
        private readonly PermissionChecker $permissions,
    ) {}

    public function index(Request $request): Response
    {
        $this->permissions->authorizeAny(PageSupport::actor($request), self::AREA);
        $versions = ProductVersion::query()->orderBy('version')->get()->groupBy('product_id');

        return Inertia::render('products/Index', [
            'products' => Product::query()->orderBy('code')->get()->map(fn (Product $p): array => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'lob' => $p->lob,
                'versions' => $versions->get($p->id, collect())->map(fn (ProductVersion $v): array => ['id' => $v->id, 'version' => $v->version,
                    'effective_from' => $v->effective_from->toDateString(), 'effective_to' => $v->effective_to?->toDateString(), 'term_months' => $v->term_months,
                    'earning_method' => $v->earning_method->value, 'tax_profile' => $v->tax_profile, 'commission_plan_id' => $v->commission_plan_id])->values()->all()])->values()->all(),
            'earningMethods' => EarningMethod::supported(),
            'commissionPlans' => DB::table('commission_plans')->orderBy('code')->get(['id', 'code', 'name'])->map(fn (object $p): array => (array) $p)->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var array{code: string, name: string, lob: string} $data */
        $data = $request->validate(['code' => ['required', 'string', 'max:32'], 'name' => ['required', 'string', 'max:255'], 'lob' => ['required', 'string', 'max:32']]);
        $product = $this->catalogue->createProduct($data['code'], $data['name'], $data['lob'], PageSupport::actor($request));

        return redirect('/products')->with('status', "Product {$product->code} created.");
    }

    public function storeVersion(Request $request, string $product): RedirectResponse
    {
        /** @var array{effective_from: string, effective_to?: string|null, term_months: int, earning_method: string, tax_type?: string|null, jurisdiction?: string|null, inclusive?: bool, refund_tax_on_cancellation?: bool, commission_plan_id?: string|null} $data */
        $data = $request->validate(['effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
            'term_months' => ['required', 'integer', 'min:1', 'max:60'], 'earning_method' => ['required', Rule::in(EarningMethod::supported())],
            'tax_type' => ['nullable', 'string', 'max:32'], 'jurisdiction' => ['required_with:tax_type', 'nullable', 'string', 'max:32'], 'inclusive' => ['sometimes', 'boolean'],
            'refund_tax_on_cancellation' => ['sometimes', 'boolean'], 'commission_plan_id' => ['nullable', 'uuid']]);
        $version = $this->catalogue->addVersion($product, [
            'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null, 'term_months' => (int) $data['term_months'], 'earning_method' => $data['earning_method'],
            'tax_profile' => ['tax_type' => $data['tax_type'] ?? null, 'jurisdiction' => $data['jurisdiction'] ?? null, 'inclusive' => (bool) ($data['inclusive'] ?? true),
                'refund_tax_on_cancellation' => (bool) ($data['refund_tax_on_cancellation'] ?? true)],
            'commission_plan_id' => $data['commission_plan_id'] ?? null,
        ], PageSupport::actor($request));

        return redirect('/products')->with('status', "Version {$version->version} added.");
    }
}
