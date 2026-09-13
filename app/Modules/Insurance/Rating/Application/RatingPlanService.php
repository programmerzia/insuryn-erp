<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Application;

use App\Modules\Insurance\Product\Domain\Models\ProductClass;
use App\Modules\Insurance\Rating\Domain\Definition\PlanDefinitionInvalid;
use App\Modules\Insurance\Rating\Domain\Definition\RateRow;
use App\Modules\Insurance\Rating\Domain\Definition\RateTableDefinition;
use App\Modules\Insurance\Rating\Domain\Definition\RatingStepDefinition;
use App\Modules\Insurance\Rating\Domain\Enums\RatingPlanSource;
use App\Modules\Insurance\Rating\Domain\Enums\RatingPlanStatus;
use App\Modules\Insurance\Rating\Domain\Expressions\RatingExpressions;
use App\Modules\Insurance\Rating\Domain\Models\RatingPlan;
use App\Modules\Insurance\Rating\Domain\Models\RateTable;
use App\Modules\Insurance\Rating\Domain\Models\RateTableRow;
use App\Modules\Insurance\Rating\Domain\Models\RatingStep;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Authorization\SodGuard;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Rating plan lifecycle (Phase 3 design §1 and §6 "draft/approve/activate", DECISION D-21):
 * - `rating.manage_plans` drafts a plan (header, rate tables and rows, steps), edits it while it is a draft, deletes a draft, or copies any plan into
 *   a new draft version (version + 1);
 * - `rating.approve_plans` approves a valid draft — never someone who drafted or edited it (maker ≠ checker: service check, SoD rule on the plan's
 *   audit trail, database CHECK) — activates an approved plan and retires approved or active plans.
 * - INVARIANT one active plan per class per date: activating a plan whose dates overlap the active plan is refused (RATING_PLAN_OVERLAP) unless
 *   the caller asks to supersede it, which ends the current plan the day the new one starts (only when the current plan started earlier).
 *   The exclusion constraint `rating_plans_one_active_per_class` backs this under concurrency.
 * Every change is audited on subject `rating_plan`.
 */
final class RatingPlanService
{
    private const MANAGE = 'rating.manage_plans';

    private const APPROVE = 'rating.approve_plans';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
        private readonly SodGuard $sod,
        private readonly RatingExpressions $expressions,
        private readonly RatingPlanRepository $plans,
    ) {}

    /**
     * @param array<string, mixed> $header code, name, class_code, effective_from, effective_to?, source? (company), currency? (BDT), verify?, notes?
     *
     * @throws BusinessRuleViolation RATING_PLAN_INVALID, PRODUCT_CLASS_UNKNOWN, PRODUCT_CLASS_NOT_AVAILABLE
     */
    public function createDraft(array $header, string $actorUserId): RatingPlan
    {
        $this->permissions->authorize($actorUserId, self::MANAGE);

        return DB::transaction(function () use ($header, $actorUserId): RatingPlan {
            $fields = $this->header($header);
            $version = (int) RatingPlan::query()->where('code', $fields['code'])->lockForUpdate()->pluck('version')->max() + 1;
            $plan = RatingPlan::query()->create([...$fields, 'version' => $version, 'status' => RatingPlanStatus::Draft, 'created_by' => $actorUserId]);
            $this->record('rating_plan.drafted', $plan, null, ['code' => $plan->code, 'version' => $version, 'class_code' => $plan->class_code], $actorUserId);

            return $plan;
        });
    }

    /**
     * A draft plan with its tables and steps in one go (the shape of RatingPlanDefinition::toArray, used by seeders, fixtures and imports).
     *
     * @param array<string, mixed> $definition
     */
    public function createFromDefinition(array $definition, string $actorUserId): RatingPlan
    {
        return DB::transaction(function () use ($definition, $actorUserId): RatingPlan {
            $plan = $this->createDraft($definition, $actorUserId);
            foreach ((array) ($definition['tables'] ?? []) as $table) {
                $this->addTable($plan->id, is_array($table) ? $table : throw PlanDefinitionInvalid::because('Each table must be an object.'), $actorUserId);
            }
            foreach ((array) ($definition['steps'] ?? []) as $step) {
                $this->addStep($plan->id, is_array($step) ? $step : throw PlanDefinitionInvalid::because('Each step must be an object.'), $actorUserId);
            }

            return $plan;
        });
    }

    /** @param array<string, mixed> $changes name, effective_from, effective_to, source, currency, verify, notes */
    public function updateDraft(string $planId, array $changes, string $actorUserId): RatingPlan
    {
        $this->permissions->authorize($actorUserId, self::MANAGE);

        return DB::transaction(function () use ($planId, $changes, $actorUserId): RatingPlan {
            $plan = $this->draft($planId);
            $allowed = array_intersect_key($changes, array_flip(['name', 'effective_from', 'effective_to', 'source', 'currency', 'verify', 'notes']));
            $fields = array_intersect_key($this->header([...$this->headerOf($plan), ...$allowed]), $allowed);
            $before = array_intersect_key($this->headerOf($plan), $fields);
            $plan->forceFill($fields)->save();
            $this->record('rating_plan.updated', $plan, $before, $fields, $actorUserId);

            return $plan->refresh();
        });
    }

    /**
     * @param array<string, mixed> $table code, name, dimensions, value_type, rows?
     *
     * @throws BusinessRuleViolation RATING_PLAN_NOT_DRAFT, RATING_PLAN_INVALID
     */
    public function addTable(string $planId, array $table, string $actorUserId): RateTable
    {
        $this->permissions->authorize($actorUserId, self::MANAGE);
        $definition = RateTableDefinition::fromArray($table);

        return DB::transaction(function () use ($planId, $definition, $actorUserId): RateTable {
            $plan = $this->draft($planId);
            if (RateTable::query()->where('plan_id', $plan->id)->where('code', $definition->code)->exists()) {
                throw PlanDefinitionInvalid::because("The plan already has a rate table {$definition->code}.");
            }
            $row = RateTable::query()->create(['plan_id' => $plan->id, 'code' => $definition->code, 'name' => $definition->name, 'dimensions' => $definition->dimensions,
                'value_type' => $definition->valueType]);
            $this->insertRows($row, $definition->rows, 0);
            $this->record('rating_plan.table_added', $plan, null, ['table' => $definition->code, 'value_type' => $definition->valueType->value, 'rows' => count($definition->rows)], $actorUserId);

            return $row;
        });
    }

    /** @param list<array<string, mixed>> $rows */
    public function addRows(string $planId, string $tableCode, array $rows, string $actorUserId): int
    {
        $this->permissions->authorize($actorUserId, self::MANAGE);
        $parsed = array_map(fn (array $row): RateRow => RateRow::fromArray($row), $rows);

        return DB::transaction(function () use ($planId, $tableCode, $parsed, $actorUserId): int {
            $plan = $this->draft($planId);
            $table = RateTable::query()->where('plan_id', $plan->id)->where('code', $tableCode)->first()
                ?? throw new BusinessRuleViolation('RATE_TABLE_UNKNOWN', "The plan has no rate table {$tableCode}.");
            $this->insertRows($table, $parsed, (int) RateTableRow::query()->where('table_id', $table->id)->max('position') + 1);
            $this->record('rating_plan.rows_added', $plan, null, ['table' => $tableCode, 'rows' => count($parsed)], $actorUserId);

            return count($parsed);
        });
    }

    public function removeTable(string $planId, string $tableCode, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, self::MANAGE);
        DB::transaction(function () use ($planId, $tableCode, $actorUserId): void {
            $plan = $this->draft($planId);
            $table = RateTable::query()->where('plan_id', $plan->id)->where('code', $tableCode)->first()
                ?? throw new BusinessRuleViolation('RATE_TABLE_UNKNOWN', "The plan has no rate table {$tableCode}.");
            RateTableRow::query()->where('table_id', $table->id)->delete();
            $table->delete();
            $this->record('rating_plan.table_removed', $plan, ['table' => $tableCode], null, $actorUserId);
        });
    }

    /**
     * @param array<string, mixed> $step order_no, code, kind, expression, condition?, applies_to?, label_en, label_bn
     *
     * @throws BusinessRuleViolation RATING_PLAN_NOT_DRAFT, RATING_PLAN_INVALID, RATING_EXPRESSION_INVALID
     */
    public function addStep(string $planId, array $step, string $actorUserId): RatingStep
    {
        $this->permissions->authorize($actorUserId, self::MANAGE);
        $definition = RatingStepDefinition::fromArray($step);
        $this->expressions->check($definition->expression);
        if ($definition->condition !== null) {
            $this->expressions->check($definition->condition);
        }

        return DB::transaction(function () use ($planId, $definition, $actorUserId): RatingStep {
            $plan = $this->draft($planId);
            if (RatingStep::query()->where('plan_id', $plan->id)->where(fn ($q) => $q->where('code', $definition->code)->orWhere('order_no', $definition->orderNo))->exists()) {
                throw PlanDefinitionInvalid::because("The plan already has a step {$definition->code} or a step at position {$definition->orderNo}.");
            }
            $row = RatingStep::query()->create(['plan_id' => $plan->id, ...array_diff_key($definition->toArray(), ['kind' => true]), 'kind' => $definition->kind]);
            $this->record('rating_plan.step_added', $plan, null, $definition->toArray(), $actorUserId);

            return $row;
        });
    }

    public function removeStep(string $planId, string $stepCode, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, self::MANAGE);
        DB::transaction(function () use ($planId, $stepCode, $actorUserId): void {
            $plan = $this->draft($planId);
            $deleted = RatingStep::query()->where('plan_id', $plan->id)->where('code', $stepCode)->delete();
            if ($deleted === 0) {
                throw new BusinessRuleViolation('RATING_STEP_UNKNOWN', "The plan has no step {$stepCode}.");
            }
            $this->record('rating_plan.step_removed', $plan, ['step' => $stepCode], null, $actorUserId);
        });
    }

    public function deleteDraft(string $planId, string $actorUserId): void
    {
        $this->permissions->authorize($actorUserId, self::MANAGE);
        DB::transaction(function () use ($planId, $actorUserId): void {
            $plan = $this->draft($planId);
            $tableIds = RateTable::query()->where('plan_id', $plan->id)->pluck('id');
            RateTableRow::query()->whereIn('table_id', $tableIds)->delete();
            RateTable::query()->whereIn('id', $tableIds)->delete();
            RatingStep::query()->where('plan_id', $plan->id)->delete();
            $this->record('rating_plan.deleted', $plan, ['code' => $plan->code, 'version' => $plan->version], null, $actorUserId);
            $plan->delete();
        });
    }

    /**
     * A new draft version (version + 1) copying the plan's header, tables, rows and steps; dates may be given for the new version.
     *
     * @throws BusinessRuleViolation RATING_PLAN_INVALID
     */
    public function newVersion(string $planId, string $actorUserId, ?string $effectiveFrom = null, ?string $effectiveTo = null): RatingPlan
    {
        $this->permissions->authorize($actorUserId, self::MANAGE);

        return DB::transaction(function () use ($planId, $actorUserId, $effectiveFrom, $effectiveTo): RatingPlan {
            $source = RatingPlan::query()->whereKey($planId)->firstOrFail();
            $definition = $this->plans->definition($source)->toArray();
            $copy = $this->createFromDefinition([...$definition, 'effective_from' => $effectiveFrom ?? $definition['effective_from'],
                'effective_to' => $effectiveFrom === null ? $definition['effective_to'] : $effectiveTo, 'verify' => $source->verify, 'notes' => $source->notes], $actorUserId);
            $copy->forceFill(['copied_from_plan_id' => $source->id])->save();
            $this->record('rating_plan.versioned', $copy, null, ['from_plan' => $source->id, 'from_version' => $source->version, 'version' => $copy->version], $actorUserId);

            return $copy;
        });
    }

    /**
     * @throws BusinessRuleViolation RATING_PLAN_NOT_DRAFT, RATING_PLAN_INVALID (lists every problem), RATING_PLAN_SAME_APPROVER
     */
    public function approve(string $planId, string $actorUserId): RatingPlan
    {
        $this->permissions->authorize($actorUserId, self::APPROVE);

        return DB::transaction(function () use ($planId, $actorUserId): RatingPlan {
            $plan = $this->draft($planId);
            if ($plan->created_by === $actorUserId) {
                throw new BusinessRuleViolation('RATING_PLAN_SAME_APPROVER', 'A rating plan is approved by someone other than the person who drafted it.');
            }
            $this->sod->assert($actorUserId, self::APPROVE, AuditSubject::of('rating_plan', $plan->id));
            $problems = $this->plans->definition($plan)->problems($this->expressions);
            if ($problems !== []) {
                throw new BusinessRuleViolation('RATING_PLAN_INVALID', 'The plan cannot be approved: '.implode(' ', $problems));
            }
            $plan->forceFill(['status' => RatingPlanStatus::Approved, 'approved_by' => $actorUserId, 'approved_at' => now()])->save();
            $this->record('rating_plan.approved', $plan, ['status' => 'draft'], ['status' => 'approved'], $actorUserId, self::APPROVE);

            return $plan;
        });
    }

    /**
     * @param bool $supersede end the active plan that overlaps this one the day this one starts, instead of refusing
     *
     * @throws BusinessRuleViolation RATING_PLAN_NOT_APPROVED, RATING_PLAN_OVERLAP
     */
    public function activate(string $planId, string $actorUserId, bool $supersede = false): RatingPlan
    {
        $this->permissions->authorize($actorUserId, self::APPROVE);

        return DB::transaction(function () use ($planId, $actorUserId, $supersede): RatingPlan {
            $plan = RatingPlan::query()->whereKey($planId)->lockForUpdate()->firstOrFail();
            if ($plan->status !== RatingPlanStatus::Approved) {
                throw new BusinessRuleViolation('RATING_PLAN_NOT_APPROVED', "Rating plan {$plan->code} v{$plan->version} is {$plan->status->value}; only an approved plan is activated.");
            }
            $from = $plan->effective_from->toDateString();
            $to = $plan->effective_to?->toDateString();
            $overlapping = RatingPlan::query()->where('class_code', $plan->class_code)->where('status', RatingPlanStatus::Active->value)->whereKeyNot($plan->id)
                ->when($to !== null, fn ($q) => $q->where('effective_from', '<', $to))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $from))
                ->lockForUpdate()->get();
            foreach ($overlapping as $current) {
                $endsAfter = $to === null ? true : ($current->effective_to === null || $current->effective_to->toDateString() > $to);
                if (! $supersede || $current->effective_from->toDateString() >= $from || $endsAfter && $to !== null) {
                    throw new BusinessRuleViolation('RATING_PLAN_OVERLAP', "Rating plan {$current->code} v{$current->version} is already active for {$plan->class_code} from "
                        .$current->effective_from->toDateString().' to '.($current->effective_to?->toDateString() ?? 'open').'.');
                }
                $before = $current->effective_to?->toDateString();
                $current->forceFill(['effective_to' => $from])->save();
                $this->record('rating_plan.superseded', $current, ['effective_to' => $before], ['effective_to' => $from, 'superseded_by' => $plan->id], $actorUserId, self::APPROVE);
            }
            try {
                $plan->forceFill(['status' => RatingPlanStatus::Active, 'activated_by' => $actorUserId, 'activated_at' => now()])->save();
            } catch (QueryException $overlap) {
                if ($overlap->getCode() === '23P01') { // exclusion_violation: another active plan won the race
                    throw new BusinessRuleViolation('RATING_PLAN_OVERLAP', "Another rating plan is already active for {$plan->class_code} on these dates.");
                }
                throw $overlap;
            }
            $this->record('rating_plan.activated', $plan, ['status' => 'approved'], ['status' => 'active'], $actorUserId, self::APPROVE);

            return $plan;
        });
    }

    /** @throws BusinessRuleViolation RATING_PLAN_NOT_RETIRABLE */
    public function retire(string $planId, string $actorUserId): RatingPlan
    {
        $this->permissions->authorize($actorUserId, self::APPROVE);

        return DB::transaction(function () use ($planId, $actorUserId): RatingPlan {
            $plan = RatingPlan::query()->whereKey($planId)->lockForUpdate()->firstOrFail();
            if (! in_array($plan->status, [RatingPlanStatus::Approved, RatingPlanStatus::Active], true)) {
                throw new BusinessRuleViolation('RATING_PLAN_NOT_RETIRABLE', "Rating plan {$plan->code} v{$plan->version} is {$plan->status->value}; only approved or active plans are retired.");
            }
            $before = $plan->status->value;
            $plan->forceFill(['status' => RatingPlanStatus::Retired, 'retired_by' => $actorUserId, 'retired_at' => now()])->save();
            $this->record('rating_plan.retired', $plan, ['status' => $before], ['status' => 'retired'], $actorUserId, self::APPROVE);

            return $plan;
        });
    }

    /** @param list<RateRow> $rows */
    private function insertRows(RateTable $table, array $rows, int $firstPosition): void
    {
        foreach ($rows as $i => $row) {
            RateTableRow::query()->create(['table_id' => $table->id, 'position' => $firstPosition + $i, ...array_diff_key($row->toArray(), ['keys' => true]), 'keys' => $row->keys]);
        }
    }

    private function draft(string $planId): RatingPlan
    {
        $plan = RatingPlan::query()->whereKey($planId)->lockForUpdate()->firstOrFail();
        if ($plan->status !== RatingPlanStatus::Draft) {
            throw new BusinessRuleViolation('RATING_PLAN_NOT_DRAFT', "Rating plan {$plan->code} v{$plan->version} is {$plan->status->value}: only a draft can change. Create a new version instead.");
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $header
     * @return array{code: string, name: string, class_code: string, effective_from: string, effective_to: string|null, source: RatingPlanSource, currency: string, verify: bool, notes: string|null}
     */
    private function header(array $header): array
    {
        $code = $header['code'] ?? null;
        if (! is_string($code) || preg_match('/^[A-Z0-9][A-Z0-9_-]{0,63}$/', $code) !== 1) {
            throw PlanDefinitionInvalid::because('A rating plan code is upper-case letters, digits, - and _ (e.g. MOTOR-TARIFF).');
        }
        $name = $header['name'] ?? null;
        $classCode = $header['class_code'] ?? null;
        $from = $header['effective_from'] ?? null;
        $to = $header['effective_to'] ?? null;
        $source = is_string($header['source'] ?? null) ? RatingPlanSource::tryFrom($header['source']) : RatingPlanSource::Company;
        $currency = $header['currency'] ?? 'BDT';
        $notes = $header['notes'] ?? null;
        if (! is_string($name) || trim($name) === '' || ! is_string($classCode) || ! is_string($from) || ! $this->isDate($from) || ($to !== null && (! is_string($to) || ! $this->isDate($to) || $to <= $from))
            || $source === null || ! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || ! is_bool($header['verify'] ?? false)
            || ($notes !== null && ! is_string($notes))) {
            throw PlanDefinitionInvalid::because('A rating plan needs a name, a class, effective_from (Y-m-d) before effective_to, a source (idra_tariff or company) and a currency code.');
        }
        $class = ProductClass::query()->find($classCode) ?? throw new BusinessRuleViolation('PRODUCT_CLASS_UNKNOWN', "Product class {$classCode} does not exist.");
        if ($class->status !== 'active') {
            throw new BusinessRuleViolation('PRODUCT_CLASS_NOT_AVAILABLE', "Product class {$classCode} is not available yet.");
        }

        return ['code' => $code, 'name' => $name, 'class_code' => $classCode, 'effective_from' => $from, 'effective_to' => $to, 'source' => $source,
            'currency' => $currency, 'verify' => (bool) ($header['verify'] ?? false), 'notes' => $notes];
    }

    /** @return array<string, mixed> */
    private function headerOf(RatingPlan $plan): array
    {
        return ['code' => $plan->code, 'name' => $plan->name, 'class_code' => $plan->class_code, 'effective_from' => $plan->effective_from->toDateString(),
            'effective_to' => $plan->effective_to?->toDateString(), 'source' => $plan->source->value, 'currency' => $plan->currency, 'verify' => $plan->verify, 'notes' => $plan->notes];
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && CarbonImmutable::hasFormat($value, 'Y-m-d');
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function record(string $action, RatingPlan $plan, ?array $before, ?array $after, string $actorUserId, string $permission = self::MANAGE): void
    {
        $normalise = fn (?array $values): ?array => $values === null ? null : array_map(fn (mixed $v): mixed => $v instanceof \BackedEnum ? $v->value : $v, $values);
        $this->audit->record($action, AuditSubject::of('rating_plan', $plan->id), $normalise($before), $normalise($after), null, $permission, Actor::user($actorUserId));
    }
}
