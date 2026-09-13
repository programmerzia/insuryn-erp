<?php

declare(strict_types=1);

namespace App\Modules\Insurance\Rating\Application;

use App\Modules\Insurance\Rating\Domain\Enums\RatingPlanStatus;
use App\Modules\Insurance\Rating\Domain\Models\Duty;
use App\Modules\Insurance\Rating\Domain\Models\RatingPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the tariff editor (Phase 3 design §6, slice R10a): the plans queue, one plan with the people who drafted, approved, activated and retired it,
 * the other versions of its code and the one to compare with by default, who has edited it (maker ≠ checker, the SoD rule on the plan's audit trail),
 * the active plans its dates overlap, and the duties of its class. Reads only; values stay in their stored units (D-20).
 */
final class RatingPlanDirectory
{
    public function __construct(private readonly RatingPlanRepository $plans) {}

    /** @return list<array{id: string, code: string, name: string, class_code: string, version: int, effective_from: string, effective_to: string|null, status: string, source: string, verify: bool}> */
    public function list(): array
    {
        return array_values(RatingPlan::query()->orderBy('code')->orderByDesc('version')->get()->map(fn (RatingPlan $p): array => [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'class_code' => $p->class_code, 'version' => $p->version, 'effective_from' => $p->effective_from->toDateString(),
            'effective_to' => $p->effective_to?->toDateString(), 'status' => $p->status->value, 'source' => $p->source->value, 'verify' => $p->verify,
        ])->all());
    }

    /** @return array<string, mixed> the plan's header with the names of the people on its lifecycle */
    public function header(RatingPlan $plan): array
    {
        $names = DB::table('users')->whereIn('id', array_filter([$plan->created_by, $plan->approved_by, $plan->activated_by, $plan->retired_by]))->pluck('name', 'id');
        $name = fn (?string $id): ?string => $id === null ? null : (string) ($names[$id] ?? 'someone who has left');
        $at = fn (string $column): ?string => $plan->getAttribute($column) === null ? null : CarbonImmutable::parse($plan->getAttribute($column))->toIso8601String();
        $copied = $plan->copied_from_plan_id === null ? null : RatingPlan::query()->whereKey($plan->copied_from_plan_id)->first(['id', 'version']);

        return [
            'id' => $plan->id, 'code' => $plan->code, 'name' => $plan->name, 'class_code' => $plan->class_code,
            'class_name' => (string) (DB::table('product_classes')->where('code', $plan->class_code)->value('name_en') ?? $plan->class_code),
            'version' => $plan->version, 'currency' => $plan->currency, 'effective_from' => $plan->effective_from->toDateString(), 'effective_to' => $plan->effective_to?->toDateString(),
            'status' => $plan->status->value, 'source' => $plan->source->value, 'verify' => $plan->verify, 'notes' => $plan->notes,
            'copied_from' => $copied === null ? null : ['id' => $copied->id, 'version' => $copied->version],
            'created_by_name' => $name($plan->created_by), 'created_at' => $at('created_at'),
            'approved_by_name' => $name($plan->approved_by), 'approved_at' => $at('approved_at'),
            'activated_by_name' => $name($plan->activated_by), 'activated_at' => $at('activated_at'),
            'retired_by_name' => $name($plan->retired_by), 'retired_at' => $at('retired_at'),
        ];
    }

    /** @return list<array{id: string, version: int, status: string, effective_from: string, effective_to: string|null}> every version of the plan's code, newest first */
    public function versions(RatingPlan $plan): array
    {
        return array_values(RatingPlan::query()->where('code', $plan->code)->orderByDesc('version')->get()->map(fn (RatingPlan $p): array => [
            'id' => $p->id, 'version' => $p->version, 'status' => $p->status->value, 'effective_from' => $p->effective_from->toDateString(), 'effective_to' => $p->effective_to?->toDateString(),
        ])->all());
    }

    /**
     * The version to compare the plan with: $wanted when it is another version of the same code; otherwise the active version, the version it was copied
     * from, the latest earlier version, or the latest later one. Null when the code has one version. ASSUMPTION: A-112.
     */
    public function comparison(RatingPlan $plan, ?string $wanted): ?RatingPlan
    {
        $others = RatingPlan::query()->where('code', $plan->code)->whereKeyNot($plan->id)->orderByDesc('version')->get();
        if ($wanted !== null && ($chosen = $others->firstWhere('id', $wanted)) !== null) {
            return $chosen;
        }

        return $others->firstWhere('status', RatingPlanStatus::Active)
            ?? $others->firstWhere('id', $plan->copied_from_plan_id)
            ?? $others->first(fn (RatingPlan $p): bool => $p->version < $plan->version)
            ?? $others->first();
    }

    /** @return array<string, mixed> RatingPlanDiff from $other to $plan */
    public function diff(RatingPlan $other, RatingPlan $plan): array
    {
        return (new RatingPlanDiff())->compare($this->plans->definition($other), $this->plans->definition($plan));
    }

    /** @return list<string> ids of the people who drafted or edited the plan (exercised rating.manage_plans on it): they do not approve it */
    public function editors(RatingPlan $plan): array
    {
        $ids = DB::table('audit_events')->where('object_type', 'rating_plan')->where('object_id', $plan->id)->where('permission', 'rating.manage_plans')
            ->whereNotNull('actor_user_id')->distinct()->pluck('actor_user_id')->map(fn (mixed $id): string => (string) $id)->all();

        return array_values(array_unique([$plan->created_by, ...$ids]));
    }

    /** @return list<array{id: string, code: string, version: int, effective_from: string, effective_to: string|null}> active plans of the class whose dates overlap the plan's */
    public function overlappingActive(RatingPlan $plan): array
    {
        $from = $plan->effective_from->toDateString();
        $to = $plan->effective_to?->toDateString();

        return array_values(RatingPlan::query()->where('class_code', $plan->class_code)->where('status', RatingPlanStatus::Active->value)->whereKeyNot($plan->id)
            ->when($to !== null, fn ($q) => $q->where('effective_from', '<', $to))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $from))
            ->orderBy('effective_from')->get()->map(fn (RatingPlan $p): array => ['id' => $p->id, 'code' => $p->code, 'version' => $p->version,
                'effective_from' => $p->effective_from->toDateString(), 'effective_to' => $p->effective_to?->toDateString()])->all());
    }

    /**
     * Duties of the class that are in force on $day or start later, by code and start date (ASSUMPTION: A-113 — ended duties are not listed).
     *
     * @return list<array{id: string, code: string, basis: string, rate_bp: int|null, amount_minor: int|null, bands: list<array{from: int, to: int|null, amount_minor: int}>, class_codes: list<string>,
     *     effective_from: string, effective_to: string|null, label_en: string, label_bn: string, verify: bool, source: string|null, in_force: bool}>
     */
    public function duties(string $classCode, CarbonImmutable $day): array
    {
        $date = $day->toDateString();

        return array_values(Duty::query()->whereJsonContains('class_codes', $classCode)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->orderBy('code')->orderBy('effective_from')->get()->map(fn (Duty $d): array => [
                'id' => $d->id, 'code' => $d->code, 'basis' => $d->basis->value, 'rate_bp' => $d->rate_bp, 'amount_minor' => $d->amount_minor, 'bands' => $d->bands ?? [],
                'class_codes' => $d->class_codes, 'effective_from' => $d->effective_from->toDateString(), 'effective_to' => $d->effective_to?->toDateString(),
                'label_en' => $d->label_en, 'label_bn' => $d->label_bn, 'verify' => $d->verify, 'source' => $d->source,
                'in_force' => $d->effective_from->toDateString() <= $date,
            ])->all());
    }
}
