<?php

declare(strict_types=1);

namespace App\Modules\Platform\Approvals\Http;

use App\Http\Pages\PageSupport;
use App\Modules\Platform\Approvals\ApprovalPolicyRequest;
use App\Modules\Platform\Approvals\ApprovalPolicyService;
use App\Modules\Platform\Authorization\PermissionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Approval limits (fix F3): the approval policies by what they approve, with amount bands, approvers in order and effective dates. */
final class ApprovalLimitsPageController
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly ApprovalPolicyService $policies,
    ) {}

    public function index(Request $request): Response
    {
        $this->permissions->authorize(PageSupport::actor($request), ApprovalPolicyService::PERMISSION);
        $today = CarbonImmutable::today();

        return Inertia::render('admin/approval-limits/Index', [
            'policies' => $this->policies->policies($today),
            'objectTypes' => array_map(fn (string $type, array $t): array => ['value' => $type, 'label' => $t['label']], array_keys(ApprovalPolicyService::objectTypes()), ApprovalPolicyService::objectTypes()),
            'roles' => DB::table('roles')->orderBy('name')->get(['code', 'name'])->map(fn (object $r): array => ['code' => (string) $r->code, 'name' => (string) $r->name])->values()->all(),
            'currency' => $this->policies->currency(),
            'today' => $today->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->policies->create($this->validated($request), PageSupport::actor($request));

        return redirect('/admin/approval-limits')->with('status', 'Approval limit added.');
    }

    public function update(Request $request, string $policy): RedirectResponse
    {
        $id = $this->policies->revise($policy, $this->validated($request), PageSupport::actor($request));

        return redirect('/admin/approval-limits')->with('status', $id === $policy ? 'Approval limit changed.' : 'Approval limit changed: the old one ends the day the new one starts.');
    }

    public function end(Request $request, string $policy): RedirectResponse
    {
        /** @var array{effective_to: string} $data */
        $data = $request->validate(['effective_to' => ['required', 'date_format:Y-m-d']]);
        $this->policies->end($policy, CarbonImmutable::parse($data['effective_to']), PageSupport::actor($request));

        return redirect('/admin/approval-limits')->with('status', 'Approval limit ended.');
    }

    private function validated(Request $request): ApprovalPolicyRequest
    {
        $this->permissions->authorize(PageSupport::actor($request), ApprovalPolicyService::PERMISSION);
        /** @var array{object_type: string, min_amount?: string|null, max_amount?: string|null, roles: list<string>, effective_from: string, effective_to?: string|null} $data */
        $data = $request->validate([
            'object_type' => ['required', 'string', 'max:64'], 'min_amount' => ['nullable', 'string', 'max:32'], 'max_amount' => ['nullable', 'string', 'max:32'],
            'roles' => ['required', 'array', 'min:1', 'max:5'], 'roles.*' => ['required', 'string', 'max:64'],
            'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d'],
        ], ['roles.required' => 'Add at least one approval step.', 'roles.min' => 'Add at least one approval step.', 'roles.*.required' => 'Choose a role for each step.']);
        $currency = $this->policies->currency();
        $amount = fn (string $field, ?string $value): ?int => trim((string) $value) === '' ? null : PageSupport::minor($field, $value, $currency);

        return new ApprovalPolicyRequest($data['object_type'], $amount('min_amount', $data['min_amount'] ?? null), $amount('max_amount', $data['max_amount'] ?? null), $data['roles'],
            CarbonImmutable::parse($data['effective_from']), ($data['effective_to'] ?? null) === null ? null : CarbonImmutable::parse((string) $data['effective_to']));
    }
}
