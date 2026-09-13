<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Application;

use App\Modules\Insurance\Rating\Domain\Definition\RateRow;
use App\Modules\Insurance\Rating\Domain\Definition\RateTableDefinition;
use App\Modules\Insurance\Rating\Domain\Definition\RatingPlanDefinition;
use App\Modules\Insurance\Rating\Domain\Definition\RatingStepDefinition;
use App\Modules\Insurance\Rating\Domain\Enums\RatingPlanStatus;
use App\Modules\Insurance\Rating\Domain\Models\RatingPlan;
use App\Modules\Insurance\Rating\Domain\Models\RateTable;
use App\Modules\Insurance\Rating\Domain\Models\RateTableRow;
use App\Modules\Insurance\Rating\Domain\Models\RatingStep;
use Carbon\CarbonImmutable;

/** Reads rating plans (read-only): the plan in force for a class on a date, and a plan as a RatingPlanDefinition for the calculator. */
final class RatingPlanRepository
{
    /** The active plan for $classCode in force on $day, if any (at most one: INVARIANT, exclusion constraint). */
    public function activeFor(string $classCode, CarbonImmutable $day): ?RatingPlan
    {
        $date = $day->toDateString();

        return RatingPlan::query()->where('class_code', $classCode)->where('status', RatingPlanStatus::Active->value)
            ->where('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->first();
    }

    public function definition(RatingPlan $plan): RatingPlanDefinition
    {
        $tables = RateTable::query()->where('plan_id', $plan->id)->orderBy('code')->get();
        $rows = RateTableRow::query()->whereIn('table_id', $tables->pluck('id'))->orderBy('position')->get()->groupBy('table_id');

        return new RatingPlanDefinition(
            $plan->id, $plan->code, $plan->name, $plan->class_code, $plan->version, $plan->currency, $plan->effective_from->toDateString(),
            $plan->effective_to?->toDateString(), $plan->source,
            array_values($tables->map(fn (RateTable $table): RateTableDefinition => new RateTableDefinition($table->code, $table->name, $table->dimensions, $table->value_type,
                array_values(($rows->get($table->id) ?? collect())->map(fn (RateTableRow $row): RateRow => new RateRow($this->keys($row->keys), $row->value_minor, $row->value_bp,
                    $row->band_from, $row->band_to, $row->band_label, $row->effective_from?->toDateString(), $row->effective_to?->toDateString()))->all())))->all()),
            array_values(RatingStep::query()->where('plan_id', $plan->id)->orderBy('order_no')->get()
                ->map(fn (RatingStep $step): RatingStepDefinition => new RatingStepDefinition($step->order_no, $step->code, $step->kind, $step->expression, $step->condition,
                    $step->applies_to, $step->label_en, $step->label_bn))->all()),
        );
    }

    /**
     * @param array<string, string> $keys
     * @return array<string, string>
     */
    private function keys(array $keys): array
    {
        ksort($keys);

        return $keys;
    }
}
