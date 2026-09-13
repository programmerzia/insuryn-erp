<?php

declare(strict_types=1);

use App\Modules\Insurance\Rating\Application\RatingPlanRepository;
use App\Modules\Insurance\Rating\Application\RatingPlanService;
use App\Modules\Insurance\Rating\Domain\Enums\RatingPlanStatus;
use App\Modules\Insurance\Rating\Domain\Models\RatingPlan;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Authorization\SodViolation;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 design §1 / §6 (slice R2): rating plans go draft → approved → active → retired. Only a draft changes; the approver is never the drafter;
 * INVARIANT only one active plan per product class per date — in the service and in the database.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->maker = userWithPermissions($this->ctx['tenant_id'], ['rating.manage_plans']);
    $this->checker = userWithPermissions($this->ctx['tenant_id'], ['rating.approve_plans']);
    $this->plans = app(RatingPlanService::class);
});

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function simplePlan(array $overrides = []): array
{
    return [
        'code' => 'FIRE-TARIFF', 'name' => 'Fire tariff', 'class_code' => 'fire', 'effective_from' => '2026-01-01', 'source' => 'idra_tariff', 'verify' => true,
        'tables' => [['code' => 'fire_rate', 'name' => 'Rate by occupancy', 'dimensions' => ['occupancy'], 'value_type' => 'rate_pm', 'rows' => [
            ['keys' => ['occupancy' => 'dwelling'], 'value_bp' => 80], ['keys' => ['occupancy' => 'factory'], 'value_bp' => 250],
        ]]],
        'steps' => [
            ['order_no' => 10, 'code' => 'base', 'kind' => 'base', 'expression' => "per_mille(sum_insured, lookup('fire_rate', risk.occupancy))", 'label_en' => 'Fire premium', 'label_bn' => 'অগ্নি প্রিমিয়াম'],
            ['order_no' => 20, 'code' => 'rounding', 'kind' => 'rounding', 'expression' => 'round_to(running.premium, 100)', 'label_en' => 'Rounding', 'label_bn' => 'পূর্ণসংখ্যা'],
        ],
        ...$overrides,
    ];
}

function planStatus(string $planId): RatingPlanStatus
{
    return RatingPlan::query()->whereKey($planId)->firstOrFail()->status;
}

it('drafts a plan with tables and steps, edits it while it is a draft, and audits every change', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $plan = $this->plans->createFromDefinition(simplePlan(), $this->maker);
        $this->plans->addRows($plan->id, 'fire_rate', [['keys' => ['occupancy' => 'shop'], 'value_bp' => 150]], $this->maker);
        $this->plans->addStep($plan->id, ['order_no' => 15, 'code' => 'factory_loading', 'kind' => 'loading', 'expression' => 'pct(running.premium, 1000)',
            'condition' => "risk.occupancy == 'factory'", 'label_en' => 'Factory loading', 'label_bn' => 'কারখানা লোডিং'], $this->maker);
        $this->plans->updateDraft($plan->id, ['name' => 'Fire tariff 2026'], $this->maker);
        $definition = app(RatingPlanRepository::class)->definition($plan->refresh());

        expect($plan->status)->toBe(RatingPlanStatus::Draft)
            ->and($plan->version)->toBe(1)
            ->and($plan->name)->toBe('Fire tariff 2026')
            ->and($plan->verify)->toBeTrue()
            ->and($definition->table('fire_rate')?->rows)->toHaveCount(3)
            ->and(array_map(fn ($s) => $s->code, $definition->steps))->toBe(['base', 'factory_loading', 'rounding'])
            ->and(DB::table('audit_events')->where('object_type', 'rating_plan')->where('object_id', $plan->id)->pluck('action')->countBy()->all())
            ->toEqual(['rating_plan.drafted' => 1, 'rating_plan.table_added' => 1, 'rating_plan.step_added' => 3, 'rating_plan.rows_added' => 1, 'rating_plan.updated' => 1]);

        $this->plans->removeStep($plan->id, 'factory_loading', $this->maker);
        expect(thrownBy(fn () => $this->plans->addStep($plan->id, ['order_no' => 30, 'code' => 'bad', 'kind' => 'loading', 'expression' => 'running.premium / 10',
            'label_en' => 'Bad', 'label_bn' => 'খারাপ'], $this->maker), BusinessRuleViolation::class)->reasonCode)->toBe('RATING_EXPRESSION_INVALID')
            ->and(thrownBy(fn () => $this->plans->addStep($plan->id, ['order_no' => 20, 'code' => 'again', 'kind' => 'loading', 'expression' => '1',
                'label_en' => 'Again', 'label_bn' => 'আবার'], $this->maker), BusinessRuleViolation::class)->reasonCode)->toBe('RATING_PLAN_INVALID')
            ->and(thrownBy(fn () => $this->plans->createDraft([...simplePlan(), 'class_code' => 'engineering'], $this->maker), BusinessRuleViolation::class)->reasonCode)
            ->toBe('PRODUCT_CLASS_NOT_AVAILABLE');
    });
});

it('is approved only by someone who neither drafted nor edited it, and only when it is valid', function (): void {
    $editor = userWithPermissions($this->ctx['tenant_id'], ['rating.manage_plans', 'rating.approve_plans']);
    asTenant($this->ctx['tenant_id'], function () use ($editor): void {
        $plan = $this->plans->createFromDefinition(simplePlan(), $this->maker);
        $this->plans->addRows($plan->id, 'fire_rate', [['keys' => ['occupancy' => 'warehouse'], 'value_bp' => 200]], $editor);
        $invalid = $this->plans->createFromDefinition(simplePlan(['code' => 'EMPTY', 'steps' => []]), $this->maker);
        $both = userWithPermissions($this->ctx['tenant_id'], ['rating.manage_plans', 'rating.approve_plans']);
        $own = $this->plans->createFromDefinition(simplePlan(['code' => 'OWN']), $both);

        expect(fn () => $this->plans->approve($plan->id, $this->maker))->toThrow(PermissionDenied::class)
            ->and(thrownBy(fn () => $this->plans->approve($own->id, $both), BusinessRuleViolation::class)->reasonCode)->toBe('RATING_PLAN_SAME_APPROVER')
            ->and(fn () => $this->plans->approve($plan->id, $editor))->toThrow(SodViolation::class)
            ->and(thrownBy(fn () => $this->plans->approve($invalid->id, $this->checker), BusinessRuleViolation::class)->getMessage())->toContain('at least one base step')
            ->and($this->plans->approve($plan->id, $this->checker)->status)->toBe(RatingPlanStatus::Approved)
            ->and(RatingPlan::query()->whereKey($plan->id)->value('approved_by'))->toBe($this->checker);

        // Database backstop: the approver column can never hold the creator.
        expect(fn () => DB::table('rating_plans')->where('id', $own->id)->update(['approved_by' => $both, 'status' => 'approved']))->toThrow(QueryException::class, 'rating_plans_maker_checker');
    });
});

it('keeps approved, active and retired plans immutable in the service and in the database', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $plan = $this->plans->createFromDefinition(simplePlan(), $this->maker);
        $this->plans->approve($plan->id, $this->checker);
        $stepId = (string) DB::table('rating_steps')->where('plan_id', $plan->id)->value('id');
        $tableId = (string) DB::table('rate_tables')->where('plan_id', $plan->id)->value('id');

        foreach ([
            fn () => $this->plans->addStep($plan->id, ['order_no' => 30, 'code' => 'late', 'kind' => 'loading', 'expression' => '1', 'label_en' => 'Late', 'label_bn' => 'দেরি'], $this->maker),
            fn () => $this->plans->addRows($plan->id, 'fire_rate', [['keys' => ['occupancy' => 'shop'], 'value_bp' => 1]], $this->maker),
            fn () => $this->plans->updateDraft($plan->id, ['name' => 'Changed'], $this->maker),
            fn () => $this->plans->removeStep($plan->id, 'base', $this->maker),
            fn () => $this->plans->deleteDraft($plan->id, $this->maker),
        ] as $change) {
            expect(thrownBy($change, BusinessRuleViolation::class)->reasonCode)->toBe('RATING_PLAN_NOT_DRAFT');
        }
        foreach ([
            fn () => DB::table('rating_steps')->where('id', $stepId)->update(['expression' => 'sum_insured']),
            fn () => DB::table('rate_table_rows')->where('table_id', $tableId)->update(['value_bp' => 1]),
            fn () => DB::table('rate_table_rows')->insert(['id' => (string) Illuminate\Support\Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'table_id' => $tableId, 'keys' => '{"occupancy":"x"}', 'value_bp' => 1]),
            fn () => DB::table('rate_tables')->where('id', $tableId)->delete(),
            fn () => DB::table('rating_plans')->where('id', $plan->id)->update(['name' => 'Changed']),
            fn () => DB::table('rating_plans')->where('id', $plan->id)->update(['status' => 'draft']),
            fn () => DB::table('rating_plans')->where('id', $plan->id)->delete(),
        ] as $change) {
            expect($change)->toThrow(QueryException::class, 'RATING_PLAN_');
        }

        $this->plans->activate($plan->id, $this->checker);
        expect(fn () => DB::table('rating_plans')->where('id', $plan->id)->update(['effective_from' => '2025-01-01']))->toThrow(QueryException::class, 'RATING_PLAN_IMMUTABLE')
            ->and(fn () => DB::table('rating_plans')->where('id', $plan->id)->update(['effective_to' => null]))->not->toThrow(QueryException::class);
        $this->plans->retire($plan->id, $this->checker);
        expect(planStatus($plan->id))->toBe(RatingPlanStatus::Retired)
            ->and(fn () => DB::table('rating_plans')->where('id', $plan->id)->update(['status' => 'active']))->toThrow(QueryException::class, 'RATING_PLAN_TRANSITION')
            ->and(thrownBy(fn () => $this->plans->retire($plan->id, $this->checker), BusinessRuleViolation::class)->reasonCode)->toBe('RATING_PLAN_NOT_RETIRABLE');
    });
});

it('activates only approved plans, one per class per date, and supersedes the current plan only when asked', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $approved = function (array $overrides): RatingPlan {
            $plan = $this->plans->createFromDefinition(simplePlan($overrides), $this->maker);

            return $this->plans->approve($plan->id, $this->checker);
        };
        $current = $approved(['code' => 'FIRE-2026']);
        $draft = $this->plans->createFromDefinition(simplePlan(['code' => 'FIRE-DRAFT']), $this->maker);
        expect(thrownBy(fn () => $this->plans->activate($draft->id, $this->checker), BusinessRuleViolation::class)->reasonCode)->toBe('RATING_PLAN_NOT_APPROVED');

        $this->plans->activate($current->id, $this->checker);
        $next = $approved(['code' => 'FIRE-2027', 'effective_from' => '2027-01-01']);
        $earlier = $approved(['code' => 'FIRE-OLD', 'effective_from' => '2025-01-01', 'effective_to' => '2026-06-01']);
        $otherClass = $approved(['code' => 'MOTOR-X', 'class_code' => 'motor', 'tables' => [], 'steps' => [
            ['order_no' => 1, 'code' => 'base', 'kind' => 'base', 'expression' => 'pct(sum_insured, 100)', 'label_en' => 'Base', 'label_bn' => 'মূল'],
        ]]);

        expect(thrownBy(fn () => $this->plans->activate($next->id, $this->checker), BusinessRuleViolation::class)->reasonCode)->toBe('RATING_PLAN_OVERLAP')
            ->and(thrownBy(fn () => $this->plans->activate($earlier->id, $this->checker, supersede: true), BusinessRuleViolation::class)->reasonCode)->toBe('RATING_PLAN_OVERLAP')
            ->and($this->plans->activate($otherClass->id, $this->checker)->status)->toBe(RatingPlanStatus::Active);

        $this->plans->activate($next->id, $this->checker, supersede: true);
        $repository = app(RatingPlanRepository::class);
        expect(RatingPlan::query()->whereKey($current->id)->firstOrFail()->effective_to?->toDateString())->toBe('2027-01-01')
            ->and($repository->activeFor('fire', CarbonImmutable::parse('2026-12-31'))?->id)->toBe($current->id)
            ->and($repository->activeFor('fire', CarbonImmutable::parse('2027-01-01'))?->id)->toBe($next->id)
            ->and($repository->activeFor('fire', CarbonImmutable::parse('2025-06-01')))->toBeNull()
            ->and(DB::table('audit_events')->where('action', 'rating_plan.superseded')->where('object_id', $current->id)->exists())->toBeTrue();

        // Database backstop (INVARIANT): an overlapping active plan cannot be written even around the service.
        expect(fn () => DB::table('rating_plans')->where('id', $earlier->id)->update(['status' => 'active']))->toThrow(QueryException::class, 'rating_plans_one_active_per_class');
    });
});

it('copies any plan into a new draft version', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $plan = $this->plans->createFromDefinition(simplePlan(), $this->maker);
        $this->plans->approve($plan->id, $this->checker);
        $this->plans->activate($plan->id, $this->checker);
        $copy = $this->plans->newVersion($plan->id, $this->maker, '2027-01-01');
        $this->plans->addRows($copy->id, 'fire_rate', [['keys' => ['occupancy' => 'shop'], 'value_bp' => 120]], $this->maker);
        $repository = app(RatingPlanRepository::class);

        expect($copy->version)->toBe(2)
            ->and($copy->status)->toBe(RatingPlanStatus::Draft)
            ->and($copy->copied_from_plan_id)->toBe($plan->id)
            ->and($copy->effective_from->toDateString())->toBe('2027-01-01')
            ->and($repository->definition($copy)->table('fire_rate')?->rows)->toHaveCount(3)
            ->and($repository->definition($plan)->table('fire_rate')?->rows)->toHaveCount(2)
            ->and(array_map(fn ($s) => $s->toArray(), $repository->definition($copy)->steps))->toBe(array_map(fn ($s) => $s->toArray(), $repository->definition($plan)->steps));
    });
});

it('needs rating.manage_plans to draft', function (): void {
    $stranger = userWithPermissions($this->ctx['tenant_id'], ['product.manage']);
    asTenant($this->ctx['tenant_id'], fn () => expect(fn () => $this->plans->createDraft(simplePlan(), $stranger))->toThrow(PermissionDenied::class));
});
