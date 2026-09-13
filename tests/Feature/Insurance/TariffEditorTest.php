<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Rating\Application\RatingPlanService;
use App\Modules\Insurance\Rating\Domain\Enums\RatingPlanStatus;
use App\Modules\Insurance\Rating\Domain\Models\RatingPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Phase 3 design §6 "Tariff editor (plan → tables grid with effective dates, draft/approve/activate, diff view)" (slice R10a): Tariffs queue of rating
 * plans, a plan page with Overview · Tables · Steps · Duties · Diff · Timeline · Audit; a draft is edited in place (rows, steps, header), approved by
 * someone who neither drafted nor edited it, activated under the one-active-plan-per-class INVARIANT (supersede on request) and retired. Refusals come
 * back as form errors with the reason.
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-14 10:00'));
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->user = fn (array $permissions): User => ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    $this->manager = ($this->user)(['rating.manage_plans']);
    $this->approver = ($this->user)(['rating.approve_plans']);
    $this->draft = fn (array $overrides = [], ?User $by = null): string => ($this->in)(fn (): string => app(RatingPlanService::class)
        ->createFromDefinition(tariffPlan($overrides), ($by ?? $this->manager)->id)->id);
    $this->plan = fn (string $id): RatingPlan => ($this->in)(fn (): RatingPlan => RatingPlan::query()->whereKey($id)->firstOrFail());
});

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function tariffPlan(array $overrides = []): array
{
    return [
        'code' => 'FIRE-TARIFF', 'name' => 'Fire tariff', 'class_code' => 'fire', 'effective_from' => '2026-01-01', 'source' => 'idra_tariff', 'verify' => true,
        'tables' => [['code' => 'fire_rate', 'name' => 'Rate by occupancy', 'dimensions' => ['occupancy'], 'value_type' => 'rate_pm', 'rows' => [
            ['keys' => ['occupancy' => 'dwelling'], 'value_bp' => 80], ['keys' => ['occupancy' => 'factory'], 'value_bp' => 250],
        ]]],
        'steps' => [
            ['order_no' => 10, 'code' => 'base', 'kind' => 'base', 'expression' => "per_mille(sum_insured, lookup('fire_rate', risk.occupancy))", 'label_en' => 'Fire premium', 'label_bn' => 'অগ্নি প্রিমিয়াম'],
            ['order_no' => 90, 'code' => 'rounding', 'kind' => 'rounding', 'expression' => 'round_to(running.premium, 100)', 'label_en' => 'Rounding', 'label_bn' => 'পূর্ণসংখ্যা'],
        ],
        ...$overrides,
    ];
}

/** @return array<array-key, mixed> props as plain arrays */
function plainProps(mixed $value): array
{
    $decoded = json_decode((string) json_encode($value), true);

    return is_array($decoded) ? $decoded : [];
}

it('opens the tariffs queue for plan managers and approvers only, with what each may do', function (): void {
    ($this->draft)();
    $nobody = ($this->user)(['reports.financial']);

    actingAs($nobody)->get('/rating/plans', $this->headers)->assertForbidden();
    actingAs($this->manager)->get('/rating/plans', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('rating/plans/Index')
        ->has('plans', 1)->where('plans.0.code', 'FIRE-TARIFF')->where('plans.0.version', 1)->where('plans.0.status', 'draft')->where('plans.0.class_code', 'fire')
        ->where('plans.0.source', 'idra_tariff')->where('plans.0.verify', true)->where('plans.0.effective_from', '2026-01-01')->where('plans.0.effective_to', null)
        ->where('can.manage', true)->has('classes'));
    actingAs($this->approver)->get('/rating/plans', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('can.manage', false));
    actingAs($this->manager)->post('/rating/plans', ['code' => 'MISC-TARIFF', 'name' => 'Misc tariff', 'class_code' => 'misc', 'effective_from' => '2026-10-01', 'source' => 'company'], $this->headers)
        ->assertSessionHasNoErrors()->assertRedirect();
    actingAs($this->approver)->post('/rating/plans', ['code' => 'NOPE', 'name' => 'Nope', 'class_code' => 'misc', 'effective_from' => '2026-10-01', 'source' => 'company'], $this->headers)
        ->assertSessionHasErrors(['reason' => 'PERMISSION_DENIED']);

    expect(($this->in)(fn () => RatingPlan::query()->where('code', 'MISC-TARIFF')->value('status')))->toBe(RatingPlanStatus::Draft)
        ->and(($this->in)(fn () => RatingPlan::query()->where('code', 'NOPE')->exists()))->toBeFalse();
});

it('shows a plan with its header, tables, steps, duties and what stops approval, and permissions per person', function (): void {
    $id = ($this->draft)(['steps' => []]);
    ($this->in)(fn () => app(App\Modules\Insurance\Rating\Application\DutyBook::class)->record(['code' => 'vat', 'basis' => 'pct_of_premium', 'rate_bp' => 1500, 'class_codes' => ['fire'],
        'effective_from' => '2026-01-01', 'label_en' => 'VAT', 'label_bn' => 'মূসক', 'source' => 'placeholder_verify'], $this->manager->id));
    $nobody = ($this->user)(['policy.create']);

    actingAs($nobody)->get("/rating/plans/{$id}", $this->headers)->assertForbidden();
    actingAs($this->manager)->get("/rating/plans/{$id}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('rating/plans/Show')
        ->where('plan.code', 'FIRE-TARIFF')->where('plan.status', 'draft')->where('plan.verify', true)->where('plan.class_name', 'Fire')->where('plan.created_by_name', 'Test user')
        ->has('tables', 1)->where('tables.0.value_type', 'rate_pm')->where('tables.0.dimensions', ['occupancy'])->where('tables.0.rows.1.value_bp', 250)
        ->where('tables.0.rows.1.keys', ['occupancy' => 'factory'])->has('steps', 0)
        ->where('problems', fn ($problems): bool => in_array('The plan needs at least one base step.', plainProps($problems), true))
        ->has('duties', 1)->where('duties.0.code', 'vat')->where('duties.0.rate_bp', 1500)->where('duties.0.verify', true)->where('duties.0.in_force', true)
        ->where('can.edit', true)->where('can.approve', false)->where('can.record_duties', true)
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload->where('audit', fn ($rows): bool => count(plainProps($rows)) === 2)
            ->where('timeline', fn ($rows): bool => str_contains((string) (plainProps($rows)[0]['sentence'] ?? ''), 'Test user'))));
    actingAs($this->approver)->get("/rating/plans/{$id}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('can.edit', false)->where('can.approve', true)->where('can.approve_blocked', null)->where('can.record_duties', false));
});

it('edits a draft in place: header, rows with their dates, tables and steps, with expression errors on the field', function (): void {
    $id = ($this->draft)();
    $as = actingAs($this->manager);

    $as->put("/rating/plans/{$id}", ['name' => 'Fire tariff 2026', 'effective_from' => '2026-02-01', 'effective_to' => '2027-02-01', 'source' => 'company', 'verify' => false, 'notes' => 'Board paper 12'], $this->headers)
        ->assertSessionHasNoErrors();
    $as->put("/rating/plans/{$id}/tables/fire_rate/rows", ['rows' => [
        ['keys' => ['occupancy' => 'dwelling'], 'value_bp' => 90],
        ['keys' => ['occupancy' => 'shop'], 'value_bp' => 150, 'effective_from' => '2026-06-01', 'effective_to' => null],
    ]], $this->headers)->assertSessionHasNoErrors();
    $as->post("/rating/plans/{$id}/tables", ['code' => 'construction', 'name' => 'Construction loading', 'dimensions' => ['construction_class'], 'value_type' => 'rate_pct'], $this->headers)
        ->assertSessionHasNoErrors();
    $as->put("/rating/plans/{$id}/tables/construction/rows", ['rows' => [['keys' => ['construction_class' => '3'], 'value_bp' => 2500]]], $this->headers)->assertSessionHasNoErrors();
    $as->post("/rating/plans/{$id}/steps", ['order_no' => 20, 'code' => 'construction', 'kind' => 'loading', 'expression' => 'running.premium / 4', 'label_en' => 'Construction', 'label_bn' => 'নির্মাণ'], $this->headers)
        ->assertSessionHasErrors('expression');
    $as->post("/rating/plans/{$id}/steps", ['order_no' => 20, 'code' => 'construction', 'kind' => 'loading', 'expression' => "pct(running.premium, lookup('construction', risk.construction_class))",
        'condition' => 'risk.construction_class ==', 'label_en' => 'Construction', 'label_bn' => 'নির্মাণ'], $this->headers)->assertSessionHasErrors('condition');
    $as->post("/rating/plans/{$id}/steps", ['order_no' => 20, 'code' => 'construction', 'kind' => 'loading', 'expression' => "pct(running.premium, lookup('construction', risk.construction_class))",
        'condition' => "risk.construction_class == '3'", 'label_en' => 'Construction', 'label_bn' => 'নির্মাণ'], $this->headers)->assertSessionHasNoErrors();
    $as->put("/rating/plans/{$id}/steps/rounding", ['order_no' => 95, 'code' => 'rounding', 'kind' => 'rounding', 'expression' => 'round_to(running.premium, 1000)', 'label_en' => 'Rounding to 10', 'label_bn' => 'পূর্ণসংখ্যা'], $this->headers)
        ->assertSessionHasNoErrors();
    $as->put("/rating/plans/{$id}/steps/rounding", ['order_no' => 20, 'code' => 'rounding', 'kind' => 'rounding', 'expression' => 'round_to(running.premium, 1000)', 'label_en' => 'Clash', 'label_bn' => 'x'], $this->headers)
        ->assertSessionHasErrors(['reason' => 'RATING_PLAN_INVALID']);
    $as->put("/rating/plans/{$id}/steps/rounding", ['order_no' => 96, 'code' => 'rounding', 'kind' => 'rounding', 'expression' => 'round_to(running.premium, 1.5)', 'label_en' => 'Bad', 'label_bn' => 'x'], $this->headers)
        ->assertSessionHasErrors('expression');
    $as->put("/rating/plans/{$id}/tables/fire_rate/rows", ['rows' => [['keys' => ['occupancy' => 'dwelling'], 'value_bp' => '2.5']]], $this->headers)->assertSessionHasErrors('rows.0.value_bp');

    $definition = ($this->in)(fn () => app(App\Modules\Insurance\Rating\Application\RatingPlanRepository::class)->definition(RatingPlan::query()->whereKey($id)->firstOrFail()));
    expect($definition->name)->toBe('Fire tariff 2026')
        ->and($definition->effectiveTo)->toBe('2027-02-01')
        ->and(array_map(fn ($r) => [$r->keys, $r->valueBp, $r->effectiveFrom], $definition->table('fire_rate')->rows ?? []))
        ->toBe([[['occupancy' => 'dwelling'], 90, null], [['occupancy' => 'shop'], 150, '2026-06-01']])
        ->and($definition->table('construction')?->rows[0]->valueBp)->toBe(2500)
        ->and(array_map(fn ($s) => [$s->code, $s->orderNo, $s->labelEn], $definition->steps))->toBe([['base', 10, 'Fire premium'], ['construction', 20, 'Construction'], ['rounding', 95, 'Rounding to 10']])
        ->and(($this->plan)($id)->verify)->toBeFalse()
        ->and(($this->in)(fn () => DB::table('audit_events')->where('object_id', $id)->whereIn('action', ['rating_plan.rows_replaced', 'rating_plan.step_updated'])->count()))->toBe(3);

    $as->delete("/rating/plans/{$id}/steps/construction", [], $this->headers)->assertSessionHasNoErrors();
    $as->delete("/rating/plans/{$id}/tables/construction", [], $this->headers)->assertSessionHasNoErrors();
    expect(($this->in)(fn () => DB::table('rate_tables')->where('plan_id', $id)->count()))->toBe(1);
});

it('refuses editing a plan that is no longer a draft, over HTTP, and leaves it unchanged', function (): void {
    $id = ($this->draft)();
    actingAs($this->approver)->post("/rating/plans/{$id}/approve", [], $this->headers)->assertSessionHasNoErrors();
    $as = actingAs($this->manager);

    foreach ([
        fn () => $as->put("/rating/plans/{$id}/tables/fire_rate/rows", ['rows' => [['keys' => ['occupancy' => 'dwelling'], 'value_bp' => 1]]], $this->headers),
        fn () => $as->put("/rating/plans/{$id}", ['name' => 'Changed'], $this->headers),
        fn () => $as->post("/rating/plans/{$id}/steps", ['order_no' => 50, 'code' => 'late', 'kind' => 'loading', 'expression' => '1', 'label_en' => 'Late', 'label_bn' => 'দেরি'], $this->headers),
        fn () => $as->put("/rating/plans/{$id}/steps/base", ['order_no' => 10, 'code' => 'base', 'kind' => 'base', 'expression' => 'sum_insured', 'label_en' => 'Base', 'label_bn' => 'মূল'], $this->headers),
        fn () => $as->delete("/rating/plans/{$id}/steps/rounding", [], $this->headers),
        fn () => $as->delete("/rating/plans/{$id}", [], $this->headers),
    ] as $change) {
        $change()->assertSessionHasErrors(['reason' => 'RATING_PLAN_NOT_DRAFT']);
    }
    actingAs($this->manager)->get("/rating/plans/{$id}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('plan.status', 'approved')->where('can.edit', false)
        ->where('plan.approved_by_name', 'Test user')->where('tables.0.rows.0.value_bp', 80)->has('steps', 2));
});

it('does not let the person who drafted or edited a plan approve it, and says why on the page', function (): void {
    $both = ($this->user)(['rating.manage_plans', 'rating.approve_plans']);
    $own = ($this->draft)(['code' => 'OWN'], $both);
    $edited = ($this->draft)(['code' => 'EDITED']);
    actingAs($both)->put("/rating/plans/{$edited}/tables/fire_rate/rows", ['rows' => [['keys' => ['occupancy' => 'dwelling'], 'value_bp' => 85]]], $this->headers)->assertSessionHasNoErrors();

    actingAs($both)->get("/rating/plans/{$own}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('can.approve', true)
        ->where('can.approve_blocked', 'You drafted or edited this plan, so someone else approves it.'));
    actingAs($both)->get("/rating/plans/{$edited}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('can.approve_blocked', 'You drafted or edited this plan, so someone else approves it.'));
    actingAs($both)->post("/rating/plans/{$own}/approve", [], $this->headers)->assertSessionHasErrors(['reason' => 'RATING_PLAN_SAME_APPROVER']);
    actingAs($both)->post("/rating/plans/{$edited}/approve", [], $this->headers)->assertSessionHasErrors(['reason' => 'SOD_CONFLICT']);

    expect(($this->plan)($own)->status)->toBe(RatingPlanStatus::Draft)->and(($this->plan)($edited)->status)->toBe(RatingPlanStatus::Draft);
});

it('refuses activating over the active plan of the class with the reason, and supersedes it when asked', function (): void {
    $current = activeRatingPlan($this->ctx['tenant_id'], tariffPlan(['code' => 'FIRE-2026']));
    $next = ($this->draft)(['code' => 'FIRE-2027', 'effective_from' => '2027-01-01']);
    actingAs($this->approver)->post("/rating/plans/{$next}/approve", [], $this->headers)->assertSessionHasNoErrors();

    actingAs($this->approver)->get("/rating/plans/{$next}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('plan.status', 'approved')
        ->has('overlaps', 1)->where('overlaps.0.code', 'FIRE-2026')->where('overlaps.0.effective_from', '2026-01-01')->where('overlaps.0.effective_to', null)
        ->where('can.activate', true)->where('can.retire', true));
    actingAs($this->approver)->post("/rating/plans/{$next}/activate", [], $this->headers)
        ->assertSessionHasErrors(['reason' => 'RATING_PLAN_OVERLAP', 'form' => 'Rating plan FIRE-2026 v1 is already active for fire from 2026-01-01 to open.']);
    expect(($this->plan)($next)->status)->toBe(RatingPlanStatus::Approved);

    actingAs($this->approver)->post("/rating/plans/{$next}/activate", ['supersede' => true], $this->headers)->assertSessionHasNoErrors();
    expect(($this->plan)($next)->status)->toBe(RatingPlanStatus::Active)
        ->and(($this->plan)($current)->effective_to?->toDateString())->toBe('2027-01-01');

    actingAs($this->manager)->post("/rating/plans/{$current}/retire", [], $this->headers)->assertSessionHasErrors(['reason' => 'PERMISSION_DENIED']);
    actingAs($this->approver)->post("/rating/plans/{$current}/retire", [], $this->headers)->assertSessionHasNoErrors();
    expect(($this->plan)($current)->status)->toBe(RatingPlanStatus::Retired);
});

it('copies a plan into a new draft version and shows the diff against the active version', function (): void {
    $v1 = activeRatingPlan($this->ctx['tenant_id'], tariffPlan());

    $response = actingAs($this->manager)->post("/rating/plans/{$v1}/versions", ['effective_from' => '2027-01-01'], $this->headers)->assertSessionHasNoErrors();
    $v2 = ($this->in)(fn (): string => (string) RatingPlan::query()->where('code', 'FIRE-TARIFF')->where('version', 2)->value('id'));
    $response->assertRedirect("/rating/plans/{$v2}");
    expect(($this->plan)($v2)->status)->toBe(RatingPlanStatus::Draft)->and(($this->plan)($v2)->copied_from_plan_id)->toBe($v1);

    actingAs($this->manager)->put("/rating/plans/{$v2}/tables/fire_rate/rows", ['rows' => [
        ['keys' => ['occupancy' => 'dwelling'], 'value_bp' => 95], ['keys' => ['occupancy' => 'warehouse'], 'value_bp' => 200],
    ]], $this->headers)->assertSessionHasNoErrors();
    actingAs($this->manager)->put("/rating/plans/{$v2}/steps/rounding", ['order_no' => 90, 'code' => 'rounding', 'kind' => 'rounding', 'expression' => 'round_to(running.premium, 1000)',
        'label_en' => 'Rounding', 'label_bn' => 'পূর্ণসংখ্যা'], $this->headers)->assertSessionHasNoErrors();

    actingAs($this->manager)->get("/rating/plans/{$v2}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('plan.version', 2)->where('plan.copied_from.version', 1)->has('versions', 2)->where('compare_id', $v1)
        ->where('diff.header', [['field' => 'effective_from', 'before' => '2026-01-01', 'after' => '2027-01-01']])
        ->where('diff.tables.0.code', 'fire_rate')
        ->where('diff.tables.0.rows.added.0.keys', ['occupancy' => 'warehouse'])
        ->where('diff.tables.0.rows.removed.0.keys', ['occupancy' => 'factory'])
        ->where('diff.tables.0.rows.changed.0.after.value_bp', 95)
        ->where('diff.steps.changed.0.code', 'rounding'));
    actingAs($this->manager)->get("/rating/plans/{$v1}?compare={$v2}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('compare_id', $v2)
        ->where('diff.tables.0.rows.added.0.keys', ['occupancy' => 'factory']));
    actingAs($this->manager)->get("/rating/plans/{$v1}?compare=".activeRatingPlan($this->ctx['tenant_id'], tariffPlan(['code' => 'MOTOR', 'class_code' => 'motor'])), $this->headers)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('compare_id', $v2));
});

it('records a duty for the class and ends it from a date, for plan managers only', function (): void {
    $id = ($this->draft)();

    actingAs($this->approver)->post('/rating/duties', ['code' => 'stamp', 'basis' => 'flat_per_policy', 'amount_minor' => 20_000, 'class_codes' => ['fire'], 'effective_from' => '2026-01-01',
        'label_en' => 'Stamp duty', 'label_bn' => 'স্ট্যাম্প শুল্ক'], $this->headers)->assertSessionHasErrors(['reason' => 'PERMISSION_DENIED']);
    actingAs($this->manager)->post('/rating/duties', ['code' => 'stamp', 'basis' => 'per_sum_insured_band', 'bands' => [['from' => 0, 'to' => 1_000_000_000, 'amount_minor' => 20_000],
        ['from' => 1_000_000_000, 'to' => null, 'amount_minor' => 50_000]], 'class_codes' => ['fire'], 'effective_from' => '2026-01-01', 'label_en' => 'Stamp duty', 'label_bn' => 'স্ট্যাম্প শুল্ক', 'verify' => true], $this->headers)
        ->assertSessionHasNoErrors();
    actingAs($this->manager)->post('/rating/duties', ['code' => 'stamp', 'basis' => 'flat_per_policy', 'amount_minor' => 30_000, 'class_codes' => ['fire'], 'effective_from' => '2026-06-01',
        'label_en' => 'Stamp duty', 'label_bn' => 'স্ট্যাম্প শুল্ক'], $this->headers)->assertSessionHasErrors(['reason' => 'DUTY_OVERLAP']);
    $duty = ($this->in)(fn (): string => (string) DB::table('duties')->where('code', 'stamp')->value('id'));
    actingAs($this->manager)->post("/rating/duties/{$duty}/end", ['effective_to' => '2026-12-01'], $this->headers)->assertSessionHasNoErrors();

    actingAs($this->manager)->get("/rating/plans/{$id}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->has('duties', 1)
        ->where('duties.0.basis', 'per_sum_insured_band')->where('duties.0.bands.1.amount_minor', 50_000)->where('duties.0.effective_to', '2026-12-01')->where('duties.0.verify', true));
});

it('deletes a draft and lists nothing more for it', function (): void {
    $id = ($this->draft)();
    actingAs($this->approver)->delete("/rating/plans/{$id}", [], $this->headers)->assertSessionHasErrors(['reason' => 'PERMISSION_DENIED']);
    actingAs($this->manager)->delete("/rating/plans/{$id}", [], $this->headers)->assertSessionHasNoErrors()->assertRedirect('/rating/plans');
    expect(($this->in)(fn () => RatingPlan::query()->whereKey($id)->exists()))->toBeFalse();
});
