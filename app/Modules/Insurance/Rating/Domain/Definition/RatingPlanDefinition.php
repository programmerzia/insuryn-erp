<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Domain\Definition;

use App\Modules\Insurance\Rating\Domain\Enums\RatingPlanSource;
use App\Modules\Insurance\Rating\Domain\Enums\RateValueType;
use App\Modules\Insurance\Rating\Domain\Enums\RatingStepKind;
use App\Modules\Insurance\Rating\Domain\Expressions\RatingExpressions;
use App\Modules\Insurance\Rating\Domain\RatingFailed;

/**
 * A rating plan as data (Phase 3 design §1): header, rate tables and ordered steps. Built from the database by the application layer or from a
 * fixture; `problems()` lists what stops it being approved.
 */
final readonly class RatingPlanDefinition
{
    /**
     * @param list<RateTableDefinition> $tables
     * @param list<RatingStepDefinition> $steps in order_no
     */
    public function __construct(
        public ?string $id,
        public string $code,
        public string $name,
        public string $classCode,
        public int $version,
        public string $currency,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public RatingPlanSource $source,
        public array $tables,
        public array $steps,
    ) {}

    public function table(string $code): ?RateTableDefinition
    {
        foreach ($this->tables as $table) {
            if ($table->code === $code) {
                return $table;
            }
        }

        return null;
    }

    /** @return array<string, RateTableDefinition> */
    public function tablesByCode(): array
    {
        $byCode = [];
        foreach ($this->tables as $table) {
            $byCode[$table->code] = $table;
        }

        return $byCode;
    }

    /** @return list<string> what stops the plan being approved; empty when it can be */
    public function problems(RatingExpressions $expressions): array
    {
        $problems = [];
        if (! in_array(RatingStepKind::Base, array_map(fn (RatingStepDefinition $s): RatingStepKind => $s->kind, $this->steps), true)) {
            $problems[] = 'The plan needs at least one base step.';
        }
        $codes = array_map(fn (RateTableDefinition $t): string => $t->code, $this->tables);
        if (count(array_unique($codes)) !== count($codes)) {
            $problems[] = 'Two rate tables share a code.';
        }
        foreach ($this->tables as $table) {
            $problems = [...$problems, ...$table->problems()];
        }
        $stepCodes = array_map(fn (RatingStepDefinition $s): string => $s->code, $this->steps);
        $orderNos = array_map(fn (RatingStepDefinition $s): int => $s->orderNo, $this->steps);
        if (count(array_unique($stepCodes)) !== count($stepCodes) || count(array_unique($orderNos)) !== count($orderNos)) {
            $problems[] = 'Step codes and order numbers must be unique.';
        }
        $phase = 1;
        foreach ($this->steps as $step) {
            if ($step->kind->phase() < $phase) {
                $problems[] = "Step {$step->code} ({$step->kind->value}) comes after a later kind of step: premium steps, then rounding, then duties and taxes.";
            }
            $phase = max($phase, $step->kind->phase());
            if ($step->kind === RatingStepKind::Coverage && $step->appliesTo === null) {
                $problems[] = "Coverage step {$step->code} must name the coverage it applies to.";
            }
            foreach (array_filter([$step->expression, $step->condition]) as $expression) {
                try {
                    foreach ($expressions->check($expression) as $reference) {
                        $table = $this->table($reference['table']);
                        $isBand = $table?->valueType === RateValueType::Band;
                        if ($table === null) {
                            $problems[] = "Step {$step->code} uses rate table {$reference['table']}, which the plan does not have.";
                        } elseif ($isBand !== ($reference['function'] !== 'lookup')) {
                            $problems[] = "Step {$step->code} uses {$reference['function']}() on table {$table->code} ({$table->valueType->value}).";
                        }
                    }
                } catch (RatingFailed $invalid) {
                    $problems[] = "Step {$step->code}: {$invalid->getMessage()}";
                }
            }
        }

        return $problems;
    }

    /** @param array<mixed> $plan */
    public static function fromArray(array $plan): self
    {
        $source = is_string($plan['source'] ?? null) ? RatingPlanSource::tryFrom($plan['source']) : RatingPlanSource::Company;
        foreach (['code', 'name', 'class_code', 'effective_from'] as $key) {
            if (! is_string($plan[$key] ?? null) || $plan[$key] === '') {
                throw PlanDefinitionInvalid::because("A rating plan needs {$key}.");
            }
        }
        $to = $plan['effective_to'] ?? null;
        $currency = $plan['currency'] ?? 'BDT';
        if ($source === null || ($to !== null && (! is_string($to) || $to <= $plan['effective_from'])) || ! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1
            || ! is_int($plan['version'] ?? 1) || ! is_array($plan['tables'] ?? []) || ! is_array($plan['steps'] ?? [])) {
            throw PlanDefinitionInvalid::because('A rating plan needs a source (idra_tariff or company), a currency code, effective_to after effective_from, and lists of tables and steps.');
        }
        $tables = array_values(array_map(fn (mixed $t): RateTableDefinition => is_array($t) ? RateTableDefinition::fromArray($t) : throw PlanDefinitionInvalid::because('Each table must be an object.'),
            (array) ($plan['tables'] ?? [])));
        $steps = array_values(array_map(fn (mixed $s): RatingStepDefinition => is_array($s) ? RatingStepDefinition::fromArray($s) : throw PlanDefinitionInvalid::because('Each step must be an object.'),
            (array) ($plan['steps'] ?? [])));
        usort($steps, fn (RatingStepDefinition $a, RatingStepDefinition $b): int => $a->orderNo <=> $b->orderNo);
        $id = $plan['id'] ?? null;

        return new self(is_string($id) ? $id : null, $plan['code'], $plan['name'], $plan['class_code'], (int) ($plan['version'] ?? 1), $currency, $plan['effective_from'],
            $to, $source, $tables, $steps);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'class_code' => $this->classCode, 'version' => $this->version, 'currency' => $this->currency,
            'effective_from' => $this->effectiveFrom, 'effective_to' => $this->effectiveTo, 'source' => $this->source->value,
            'tables' => array_map(fn (RateTableDefinition $t): array => $t->toArray(), $this->tables),
            'steps' => array_map(fn (RatingStepDefinition $s): array => $s->toArray(), $this->steps)];
    }
}
