<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Http\Controllers;

use App\Http\Pages\ObjectHistory;
use App\Http\Pages\PageSupport;
use App\Modules\Insurance\Rating\Application\RatingPlanDirectory;
use App\Modules\Insurance\Rating\Application\RatingPlanRepository;
use App\Modules\Insurance\Rating\Application\RatingPlanService;
use App\Modules\Insurance\Rating\Domain\Enums\RatingPlanStatus;
use App\Modules\Insurance\Rating\Domain\Enums\RatingStepKind;
use App\Modules\Insurance\Rating\Domain\Enums\RateValueType;
use App\Modules\Insurance\Rating\Domain\Expressions\RatingExpressions;
use App\Modules\Insurance\Rating\Domain\Models\RatingPlan;
use App\Modules\Insurance\Rating\Domain\RatingFailed;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Tenancy\BusinessClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 3 design §6 "Tariff editor (plan → tables grid with effective dates, draft/approve/activate, diff view)" (slice R10a). The page opens for
 * `rating.manage_plans` or `rating.approve_plans`; every change goes through RatingPlanService, which checks the permission, the draft-only rule, maker ≠
 * checker and the one-active-plan INVARIANT — its refusals come back as the form error with their reason. Rates arrive as the stored integers (D-20:
 * basis points of a percent, hundredths of a per mille, minor units), converted from what people type on the client.
 */
final class TariffsPageController
{
    /** ASSUMPTION: A-114 — the tariff editor opens for plan managers and approvers only (not report readers). */
    private const AREA = ['rating.manage_plans', 'rating.approve_plans'];

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly RatingPlanService $service,
        private readonly RatingPlanDirectory $directory,
        private readonly RatingExpressions $expressions,
    ) {}

    public function index(Request $request): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);

        return Inertia::render('rating/plans/Index', [
            'plans' => $this->directory->list(),
            'classes' => $this->classes(),
            'today' => app(BusinessClock::class)->today()->toDateString(),
            'can' => ['manage' => $this->permissions->has($actor, 'rating.manage_plans')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64'], 'name' => ['required', 'string', 'max:255'], 'class_code' => ['required', 'string'],
            'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
            'source' => ['required', Rule::in(['idra_tariff', 'company'])], 'verify' => ['boolean'], 'notes' => ['nullable', 'string']]);
        $plan = $this->service->createDraft([...$data, 'code' => strtoupper((string) $data['code']), 'verify' => (bool) ($data['verify'] ?? false)], PageSupport::actor($request));

        return redirect("/rating/plans/{$plan->id}")->with('status', "Draft {$plan->code} v{$plan->version} created. Add its rate tables and steps.");
    }

    public function show(Request $request, string $plan, ObjectHistory $history): Response
    {
        $actor = PageSupport::actor($request);
        $this->permissions->authorizeAny($actor, self::AREA);
        $model = RatingPlan::query()->whereKey($plan)->firstOrFail();
        $definition = app(RatingPlanRepository::class)->definition($model);
        $canManage = $this->permissions->has($actor, 'rating.manage_plans');
        $canApprove = $this->permissions->has($actor, 'rating.approve_plans');
        $isDraft = $model->status === RatingPlanStatus::Draft;
        $compareWith = $this->directory->comparison($model, is_string($request->query('compare')) ? $request->query('compare') : null);
        $today = app(BusinessClock::class)->today();
        $subjects = [['rating_plan', $model->id]];

        return Inertia::render('rating/plans/Show', [
            'plan' => $this->directory->header($model),
            'tables' => array_map(fn ($t): array => $t->toArray(), $definition->tables),
            'steps' => array_map(fn ($s): array => $s->toArray(), $definition->steps),
            'problems' => $isDraft ? $definition->problems($this->expressions) : [],
            'duties' => $this->directory->duties($model->class_code, $today),
            'versions' => $this->directory->versions($model),
            'compare_id' => $compareWith?->id,
            'diff' => $compareWith === null ? null : $this->directory->diff($compareWith, $model),
            'overlaps' => $model->status === RatingPlanStatus::Approved ? $this->directory->overlappingActive($model) : [],
            'classes' => $this->classes(),
            'step_kinds' => array_map(fn (RatingStepKind $k): string => $k->value, RatingStepKind::cases()),
            'value_types' => array_map(fn (RateValueType $t): string => $t->value, RateValueType::cases()),
            'today' => $today->toDateString(),
            'can' => [
                'manage' => $canManage,
                'edit' => $canManage && $isDraft,
                'approve' => $canApprove && $isDraft,
                'approve_blocked' => $canApprove && $isDraft && in_array($actor, $this->directory->editors($model), true) ? 'You drafted or edited this plan, so someone else approves it.' : null,
                'activate' => $canApprove && $model->status === RatingPlanStatus::Approved,
                'retire' => $canApprove && in_array($model->status, [RatingPlanStatus::Approved, RatingPlanStatus::Active], true),
                'record_duties' => $canManage,
            ],
            'timeline' => Inertia::defer(fn (): array => $history->timeline($subjects), 'history'),
            'audit' => Inertia::defer(fn (): array => $history->audit($subjects), 'history'),
        ]);
    }

    public function update(Request $request, string $plan): RedirectResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:255'], 'effective_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'effective_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'source' => ['sometimes', Rule::in(['idra_tariff', 'company'])], 'verify' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string']]);
        if (array_key_exists('verify', $data)) {
            $data['verify'] = (bool) $data['verify'];
        }
        $this->service->updateDraft($plan, $data, PageSupport::actor($request));

        return back()->with('status', 'Plan saved.');
    }

    public function destroy(Request $request, string $plan): RedirectResponse
    {
        $model = RatingPlan::query()->whereKey($plan)->firstOrFail();
        $this->service->deleteDraft($plan, PageSupport::actor($request));

        return redirect('/rating/plans')->with('status', "Draft {$model->code} v{$model->version} deleted.");
    }

    public function newVersion(Request $request, string $plan): RedirectResponse
    {
        /** @var array{effective_from?: string|null, effective_to?: string|null} $data */
        $data = $request->validate(['effective_from' => ['nullable', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from']]);
        $copy = $this->service->newVersion($plan, PageSupport::actor($request), ($data['effective_from'] ?? null) ?: null, ($data['effective_to'] ?? null) ?: null);

        return redirect("/rating/plans/{$copy->id}")->with('status', "Draft {$copy->code} v{$copy->version} created from v".(RatingPlan::query()->whereKey($plan)->value('version') ?? '').'.');
    }

    public function approve(Request $request, string $plan): RedirectResponse
    {
        $model = $this->service->approve($plan, PageSupport::actor($request));

        return back()->with('status', "{$model->code} v{$model->version} approved. Activate it to put it in force.");
    }

    public function activate(Request $request, string $plan): RedirectResponse
    {
        $data = $request->validate(['supersede' => ['boolean']]);
        $model = $this->service->activate($plan, PageSupport::actor($request), (bool) ($data['supersede'] ?? false));

        return back()->with('status', "{$model->code} v{$model->version} is active from ".$model->effective_from->format('j M Y').'.');
    }

    public function retire(Request $request, string $plan): RedirectResponse
    {
        $model = $this->service->retire($plan, PageSupport::actor($request));

        return back()->with('status', "{$model->code} v{$model->version} retired.");
    }

    public function storeTable(Request $request, string $plan): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'], 'name' => ['required', 'string', 'max:255'], 'dimensions' => ['array'],
            'dimensions.*' => ['string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'], 'value_type' => ['required', Rule::enum(RateValueType::class)]],
            ['code.regex' => 'Use lower-case letters, digits and _ (e.g. motor_base).', 'dimensions.*.regex' => 'Name each dimension in lower-case letters, digits and _ (e.g. vehicle_type).']);
        $this->service->addTable($plan, ['code' => $data['code'], 'name' => $data['name'], 'dimensions' => array_values((array) ($data['dimensions'] ?? [])), 'value_type' => $data['value_type']],
            PageSupport::actor($request));

        return back()->with('status', "Table {$data['code']} added.");
    }

    public function destroyTable(Request $request, string $plan, string $table): RedirectResponse
    {
        $this->service->removeTable($plan, $table, PageSupport::actor($request));

        return back()->with('status', "Table {$table} removed.");
    }

    public function replaceRows(Request $request, string $plan, string $table): RedirectResponse
    {
        $data = $request->validate([
            'rows' => ['present', 'array'], 'rows.*.keys' => ['present', 'array'], 'rows.*.keys.*' => ['string', 'max:255'],
            'rows.*.value_bp' => ['nullable', 'integer', 'min:0', 'max:2147483647'], 'rows.*.value_minor' => ['nullable', 'integer', 'min:0'],
            'rows.*.band_from' => ['nullable', 'integer'], 'rows.*.band_to' => ['nullable', 'integer'], 'rows.*.band_label' => ['nullable', 'string', 'max:64'],
            'rows.*.effective_from' => ['nullable', 'date_format:Y-m-d'], 'rows.*.effective_to' => ['nullable', 'date_format:Y-m-d'],
        ], ['rows.*.value_bp.integer' => 'Enter the rate as a number with at most two decimals.', 'rows.*.value_minor.integer' => 'Enter the amount with at most two decimals.',
            'rows.*.band_from.integer' => 'A band starts at a whole number.', 'rows.*.band_to.integer' => 'A band ends at a whole number.']);
        $rows = [];
        foreach ((array) $data['rows'] as $i => $row) {
            $row = (array) $row;
            $from = $row['effective_from'] ?? null;
            $to = $row['effective_to'] ?? null;
            if (is_string($from) && is_string($to) && $to <= $from) {
                throw ValidationException::withMessages(["rows.{$i}.effective_to" => 'The row ends after it starts.']);
            }
            $int = fn (string $key): ?int => ($row[$key] ?? null) === null ? null : (int) $row[$key];
            $rows[] = ['keys' => array_map(fn (mixed $v): string => (string) $v, (array) $row['keys']), 'value_bp' => $int('value_bp'), 'value_minor' => $int('value_minor'),
                'band_from' => $int('band_from'), 'band_to' => $int('band_to'), 'band_label' => ($row['band_label'] ?? null) ?: null, 'effective_from' => $from ?: null, 'effective_to' => $to ?: null];
        }
        $count = $this->service->replaceRows($plan, $table, $rows, PageSupport::actor($request));

        return back()->with('status', "Table {$table} saved with {$count} ".($count === 1 ? 'row.' : 'rows.'));
    }

    public function storeStep(Request $request, string $plan): RedirectResponse
    {
        $step = $this->step($request);
        $this->service->addStep($plan, $step, PageSupport::actor($request));

        return back()->with('status', "Step {$step['code']} added.");
    }

    public function updateStep(Request $request, string $plan, string $step): RedirectResponse
    {
        $fields = $this->step($request);
        $this->service->updateStep($plan, $step, $fields, PageSupport::actor($request));

        return back()->with('status', "Step {$fields['code']} saved.");
    }

    public function destroyStep(Request $request, string $plan, string $step): RedirectResponse
    {
        $this->service->removeStep($plan, $step, PageSupport::actor($request));

        return back()->with('status', "Step {$step} removed.");
    }

    /**
     * A step from the form, its expression and condition checked on the field they belong to (RATING_EXPRESSION_INVALID names the problem).
     *
     * @return array{order_no: int, code: string, kind: string, expression: string, condition: string|null, applies_to: string|null, label_en: string, label_bn: string}
     */
    private function step(Request $request): array
    {
        $data = $request->validate(['order_no' => ['required', 'integer', 'min:0', 'max:100000'], 'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'kind' => ['required', Rule::enum(RatingStepKind::class)], 'expression' => ['required', 'string', 'max:2000'], 'condition' => ['nullable', 'string', 'max:2000'],
            'applies_to' => ['nullable', 'string', 'max:64'], 'label_en' => ['required', 'string', 'max:255'], 'label_bn' => ['required', 'string', 'max:255']],
            ['code.regex' => 'Use lower-case letters, digits and _ (e.g. young_driver).']);
        $errors = [];
        foreach (['expression', 'condition'] as $field) {
            $text = $data[$field] ?? null;
            if (is_string($text) && trim($text) !== '') {
                try {
                    $this->expressions->check($text);
                } catch (RatingFailed $invalid) {
                    $errors[$field] = $invalid->getMessage();
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['order_no' => (int) $data['order_no'], 'code' => (string) $data['code'], 'kind' => (string) $data['kind'], 'expression' => (string) $data['expression'],
            'condition' => ($data['condition'] ?? null) ?: null, 'applies_to' => ($data['applies_to'] ?? null) ?: null, 'label_en' => (string) $data['label_en'], 'label_bn' => (string) $data['label_bn']];
    }

    /** @return list<array{code: string, name: string}> the product classes plans can be drafted for (active ones) */
    private function classes(): array
    {
        return array_values(DB::table('product_classes')->where('status', 'active')->orderBy('sort_order')->get(['code', 'name_en'])
            ->map(fn (object $c): array => ['code' => (string) $c->code, 'name' => (string) $c->name_en])->all());
    }
}
