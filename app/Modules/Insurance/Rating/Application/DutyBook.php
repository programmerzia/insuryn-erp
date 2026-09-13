<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Application;

use App\Modules\Insurance\Product\Domain\Models\ProductClass;
use App\Modules\Insurance\Rating\Domain\Definition\DutyDefinition;
use App\Modules\Insurance\Rating\Domain\Models\Duty;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use App\Modules\Platform\Authorization\PermissionChecker;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Duties on premium (Phase 3 design §1 duties, OPEN 1): effective-dated VAT, stamp duty and levy rows per product class. A value is never edited:
 * end the row and record its successor. Two rows of the same duty never cover the same class on the same day (DUTY_OVERLAP). Values not yet
 * confirmed against current NBR/IDRA rules carry `verify = true`. Recorded under `rating.manage_plans` (ASSUMPTION A-69), audited.
 */
final class DutyBook
{
    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly Audit $audit,
    ) {}

    /**
     * @param array<string, mixed> $duty code, basis, rate_bp | amount_minor | bands, class_codes, effective_from, effective_to?, label_en, label_bn, verify? (true), source?
     *
     * @throws BusinessRuleViolation RATING_PLAN_INVALID, PRODUCT_CLASS_UNKNOWN, DUTY_OVERLAP
     */
    public function record(array $duty, string $actorUserId): Duty
    {
        $this->permissions->authorize($actorUserId, 'rating.manage_plans');
        $definition = DutyDefinition::fromArray($duty);
        $source = $duty['source'] ?? null;

        return DB::transaction(function () use ($definition, $source, $actorUserId): Duty {
            $unknown = array_diff($definition->classCodes, ProductClass::query()->pluck('code')->map(fn ($c): string => (string) $c)->all());
            if ($unknown !== []) {
                throw new BusinessRuleViolation('PRODUCT_CLASS_UNKNOWN', 'Unknown product class: '.implode(', ', $unknown).'.');
            }
            $this->assertNoOverlap($definition, null);
            $row = Duty::query()->create([
                'code' => $definition->code, 'basis' => $definition->basis, 'rate_bp' => $definition->rateBp, 'amount_minor' => $definition->amountMinor,
                'bands' => $definition->bands === [] ? null : $definition->bands, 'class_codes' => $definition->classCodes, 'effective_from' => $definition->effectiveFrom,
                'effective_to' => $definition->effectiveTo, 'label_en' => $definition->labelEn, 'label_bn' => $definition->labelBn, 'verify' => $definition->verify,
                'source' => is_string($source) ? $source : null, 'created_by' => $actorUserId,
            ]);
            $this->audit->record('duty.recorded', AuditSubject::of('duty', $row->id), null, $definition->toArray(), null, 'rating.manage_plans', Actor::user($actorUserId));

            return $row;
        });
    }

    /** Ends a duty row so a successor can start on $effectiveTo. */
    public function end(string $dutyId, string $effectiveTo, string $actorUserId): Duty
    {
        $this->permissions->authorize($actorUserId, 'rating.manage_plans');

        return DB::transaction(function () use ($dutyId, $effectiveTo, $actorUserId): Duty {
            $duty = Duty::query()->whereKey($dutyId)->lockForUpdate()->firstOrFail();
            $before = $duty->effective_to?->toDateString();
            if ($effectiveTo <= $duty->effective_from->toDateString() || ($before !== null && $effectiveTo > $before)) {
                throw new BusinessRuleViolation('DUTY_RANGE_INVALID', 'A duty can only be ended after it starts, and never extended.');
            }
            $duty->forceFill(['effective_to' => $effectiveTo])->save();
            $this->audit->record('duty.ended', AuditSubject::of('duty', $duty->id), ['effective_to' => $before], ['effective_to' => $effectiveTo], null, 'rating.manage_plans', Actor::user($actorUserId));

            return $duty;
        });
    }

    /** @return list<DutyDefinition> duties in force for $classCode on $day, in the order stamp, levy, vat */
    public function inForce(string $classCode, CarbonImmutable $day): array
    {
        $date = $day->toDateString();
        $order = ['stamp' => 1, 'levy' => 2, 'vat' => 3];
        $duties = Duty::query()->where('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->whereJsonContains('class_codes', $classCode)->get()
            ->map(fn (Duty $duty): DutyDefinition => $this->definition($duty))->all();
        usort($duties, fn (DutyDefinition $a, DutyDefinition $b): int => $order[$a->code] <=> $order[$b->code]);

        return $duties;
    }

    public function definition(Duty $duty): DutyDefinition
    {
        return new DutyDefinition($duty->code, $duty->basis, $duty->rate_bp, $duty->amount_minor, $duty->bands ?? [], $duty->class_codes,
            $duty->effective_from->toDateString(), $duty->effective_to?->toDateString(), $duty->label_en, $duty->label_bn, $duty->verify);
    }

    private function assertNoOverlap(DutyDefinition $duty, ?string $exceptId): void
    {
        $candidates = Duty::query()->where('code', $duty->code)->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->when($duty->effectiveTo !== null, fn ($q) => $q->where('effective_from', '<', $duty->effectiveTo))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $duty->effectiveFrom))
            ->get();
        foreach ($candidates as $other) {
            $shared = array_intersect($duty->classCodes, $other->class_codes);
            if ($shared !== []) {
                throw new BusinessRuleViolation('DUTY_OVERLAP', "Duty {$duty->code} is already in force for ".implode(', ', $shared).' from '.$other->effective_from->toDateString()
                    .' to '.($other->effective_to?->toDateString() ?? 'open').'; end it first.');
            }
        }
    }
}
