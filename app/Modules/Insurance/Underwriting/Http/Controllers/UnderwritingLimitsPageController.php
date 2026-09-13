<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Underwriting\Http\Controllers;

use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Underwriting\Application\UnderwritingLimits;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Underwriting limits (slice R5, design §5): the largest sum insured each role may accept per product class, from a date. */
final class UnderwritingLimitsPageController
{
    public function __construct(
        private readonly UnderwritingLimits $limits,
        private readonly \App\Modules\Platform\Authorization\PermissionChecker $permissions,
    ) {}

    public function index(Request $request): Response
    {
        $this->permissions->authorize(PageSupport::actor($request), UnderwritingLimits::PERMISSION);
        $entity = PageSupport::entity();
        $today = CarbonImmutable::today();

        return Inertia::render('admin/underwriting-limits/Index', [
            'currency' => $entity['currency'],
            'today' => $today->toDateString(),
            'limits' => array_map(fn (array $l): array => [...$l, 'max_sum_insured' => PageSupport::money($l['max_sum_insured_minor'], $entity['currency'])], $this->limits->all($today)),
            'roles' => DB::table('roles')->orderBy('name')->get(['code', 'name'])->map(fn (object $r): array => ['value' => (string) $r->code, 'label' => (string) $r->name])->values()->all(),
            'classes' => DB::table('product_classes')->where('status', 'active')->orderBy('code')->get(['code', 'name_en'])->map(fn (object $c): array => ['value' => (string) $c->code, 'label' => (string) $c->name_en])->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var array{role_code: string, class_code: string, max_sum_insured: string, effective_from: string} $data */
        $data = $request->validate(['role_code' => ['required', 'string', 'max:64'], 'class_code' => ['required', 'string', 'max:32'], 'max_sum_insured' => ['required', 'string'],
            'effective_from' => ['required', 'date_format:Y-m-d']]);
        $entity = PageSupport::entity();
        $this->limits->set($data['role_code'], $data['class_code'], PageSupport::minor('max_sum_insured', $data['max_sum_insured'], $entity['currency']),
            CarbonImmutable::parse($data['effective_from']), PageSupport::actor($request));

        return redirect('/admin/underwriting-limits')->with('status', 'Underwriting limit set.');
    }

    public function end(Request $request, string $limit): RedirectResponse
    {
        /** @var array{effective_to: string} $data */
        $data = $request->validate(['effective_to' => ['required', 'date_format:Y-m-d']]);
        $this->limits->end($limit, CarbonImmutable::parse($data['effective_to']), PageSupport::actor($request));

        return redirect('/admin/underwriting-limits')->with('status', 'Underwriting limit ended.');
    }
}
