<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Distribution\Application\Compensation\CompensationRuleRequest;
use App\Modules\Distribution\Application\Compensation\CompensationSchemeService;
use App\Modules\Distribution\Application\Hierarchy\HierarchyService;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * Distribution design note §0, §1 (slice D4): the pay mode is a per-product compensation scheme (commission | salary_incentive | hybrid | none).
 * Regulatory caps and the non-life zero-commission rule are compliance rules on the scheme, enforced when rules are created — never hard-coded.
 * OPEN 1 → ASSUMPTION A-18: commission on non-life products is disabled until a scheme's compliance profile allows it.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->service = app(CompensationSchemeService::class);
    $this->admin = $this->world['admin'];
    $this->lifeProduct = ($this->in)(fn (): string => app(ProductCatalogue::class)->createProduct('LIFE-END', 'Endowment', 'life', $this->admin)->id);
    $this->scheme = fn (string $code, string $mode, array $profile = []): string => ($this->in)(fn (): string => $this->service
        ->createScheme($code, "{$code} scheme", $mode, CarbonImmutable::parse('2026-01-01'), null, $profile, $this->admin));
    $this->rule = fn (string $scheme, array $fields): string => ($this->in)(fn (): string => $this->service->addRule($scheme, CompensationRuleRequest::fromArray([
        'basis' => 'premium_received', 'policy_year_from' => 1, 'policy_year_to' => 1, 'effective_from' => '2026-01-01', ...$fields]), $this->admin));
    $this->refusal = fn (callable $change): string => thrownBy($change, BusinessRuleViolation::class)->reasonCode;
});

it('creates schemes in each mode with a validated compliance profile', function (): void {
    foreach (['commission', 'salary_incentive', 'hybrid', 'none'] as $mode) {
        ($this->scheme)(strtoupper($mode), $mode);
    }
    $life = ($this->scheme)('LIFE-AGENCY', 'commission', ['allowed_producer_types' => ['agent', 'agency_org'], 'caps' => [['policy_year_from' => 1, 'policy_year_to' => 1, 'max_total_bp' => 3500]]]);

    expect(($this->in)(fn () => json_decode((string) DB::table('compensation_schemes')->where('id', $life)->value('compliance_profile'), true)))->toEqual([ // jsonb keeps its own key order
        'allowed_producer_types' => ['agent', 'agency_org'], 'non_life_commission_allowed' => false,
        'caps' => [['product_id' => null, 'policy_year_from' => 1, 'policy_year_to' => 1, 'max_total_bp' => 3500]],
    ])
        ->and(($this->refusal)(fn () => ($this->scheme)('BAD-MODE', 'barter')))->toBe('SCHEME_MODE_INVALID')
        ->and(($this->refusal)(fn () => ($this->scheme)('BAD-TYPE', 'commission', ['allowed_producer_types' => ['salesman']])))->toBe('COMPLIANCE_PROFILE_INVALID')
        ->and(($this->refusal)(fn () => ($this->scheme)('BAD-CAP', 'commission', ['caps' => [['policy_year_from' => 2, 'policy_year_to' => 1, 'max_total_bp' => 100]]])))->toBe('COMPLIANCE_PROFILE_INVALID')
        ->and(($this->refusal)(fn () => ($this->scheme)('LIFE-AGENCY', 'commission')))->toBe('SCHEME_CODE_TAKEN');
});

it('validates rules against the scheme mode, producer types, levels and caps', function (): void {
    $life = ($this->scheme)('LIFE-AGENCY', 'commission', ['allowed_producer_types' => ['agent'], 'caps' => [['policy_year_from' => 1, 'policy_year_to' => 1, 'max_total_bp' => 3500]]]);
    ($this->in)(fn () => app(HierarchyService::class)->defineLevels($life, [['code' => 'FA', 'rank' => 1, 'label' => 'FA'], ['code' => 'UM', 'rank' => 2, 'label' => 'UM'], ['code' => 'BM', 'rank' => 3, 'label' => 'BM']], $this->admin));
    $salaried = ($this->scheme)('NL-BDO', 'salary_incentive');

    ($this->rule)($life, ['product_id' => $this->lifeProduct, 'producer_type' => 'agent', 'level_code' => 'FA', 'rate_bp' => 2500]);
    ($this->rule)($life, ['product_id' => $this->lifeProduct, 'level_code' => 'UM', 'override_rate_bp' => 500]);

    expect(($this->refusal)(fn () => ($this->rule)($life, ['product_id' => $this->lifeProduct, 'level_code' => 'BM', 'override_rate_bp' => 600])))->toBe('COMPLIANCE_CAP_EXCEEDED') // 2500 + 500 + 600 > 3500
        ->and(($this->refusal)(fn () => ($this->rule)($life, ['product_id' => $this->lifeProduct, 'producer_type' => 'broker', 'rate_bp' => 1000])))->toBe('PRODUCER_TYPE_NOT_ALLOWED')
        ->and(($this->refusal)(fn () => ($this->rule)($life, ['product_id' => $this->lifeProduct, 'level_code' => 'RM', 'override_rate_bp' => 100])))->toBe('HIERARCHY_LEVEL_UNKNOWN')
        ->and(($this->refusal)(fn () => ($this->rule)($life, ['product_id' => $this->lifeProduct, 'override_rate_bp' => 100])))->toBe('OVERRIDE_NEEDS_LEVEL')
        ->and(($this->refusal)(fn () => ($this->rule)($life, ['product_id' => $this->lifeProduct, 'producer_type' => 'agent', 'rate_bp' => 900, 'cap_bp' => 800])))->toBe('RULE_RATE_ABOVE_RULE_CAP')
        ->and(($this->refusal)(fn () => ($this->rule)($life, ['product_id' => $this->lifeProduct, 'producer_type' => 'agent', 'rate_bp' => 100, 'policy_year_from' => 3, 'policy_year_to' => 2])))->toBe('RULE_INVALID')
        ->and(($this->refusal)(fn () => ($this->rule)($salaried, ['producer_type' => 'bdo', 'rate_bp' => 100])))->toBe('COMMISSION_NOT_ALLOWED_BY_MODE');

    // renewal years are outside the first-year cap
    ($this->rule)($life, ['product_id' => $this->lifeProduct, 'producer_type' => 'agent', 'level_code' => 'FA', 'rate_bp' => 500, 'policy_year_from' => 2, 'policy_year_to' => 99]);
    expect(($this->in)(fn () => DB::table('compensation_rules')->where('scheme_id', $life)->count()))->toBe(3)
        ->and(($this->in)(fn () => DB::table('compensation_rules')->where('scheme_id', $life)->where('policy_year_from', 2)->first(['renewal_requires_valid_licence', 'pays_after_termination'])))
        ->toEqual((object) ['renewal_requires_valid_licence' => true, 'pays_after_termination' => false]);
});

it('keeps commission off non-life products until the compliance profile allows it', function (): void {
    $motor = $this->world['product_id'];
    $closed = ($this->scheme)('NL-DEFAULT', 'commission');
    $opened = ($this->scheme)('NL-BROKER', 'hybrid', ['allowed_producer_types' => ['broker'], 'non_life_commission_allowed' => true, 'caps' => [['product_id' => $motor, 'policy_year_from' => 1, 'policy_year_to' => 99, 'max_total_bp' => 1000]]]);

    expect(($this->refusal)(fn () => ($this->rule)($closed, ['product_id' => $motor, 'producer_type' => 'agent', 'rate_bp' => 1000])))->toBe('NON_LIFE_COMMISSION_DISABLED');
    ($this->rule)($opened, ['product_id' => $motor, 'producer_type' => 'broker', 'rate_bp' => 1000]);
    expect(($this->refusal)(fn () => ($this->rule)($opened, ['product_id' => $motor, 'producer_type' => 'broker', 'rate_bp' => 1100, 'policy_year_from' => 2, 'policy_year_to' => 2])))->toBe('COMPLIANCE_CAP_EXCEEDED');
});

it('refuses a compliance profile change that existing rules would break', function (): void {
    $life = ($this->scheme)('LIFE-AGENCY', 'commission');
    ($this->rule)($life, ['product_id' => $this->lifeProduct, 'producer_type' => 'agent', 'rate_bp' => 3000]);

    expect(($this->refusal)(fn () => ($this->in)(fn () => $this->service->updateComplianceProfile($life, ['caps' => [['policy_year_from' => 1, 'policy_year_to' => 1, 'max_total_bp' => 2500]]], $this->admin))))
        ->toBe('COMPLIANCE_CAP_EXCEEDED')
        ->and(($this->refusal)(fn () => ($this->in)(fn () => $this->service->updateComplianceProfile($life, ['allowed_producer_types' => ['bdo']], $this->admin))))->toBe('PRODUCER_TYPE_NOT_ALLOWED');
    ($this->in)(fn () => $this->service->updateComplianceProfile($life, ['caps' => [['policy_year_from' => 1, 'policy_year_to' => 1, 'max_total_bp' => 3000]]], $this->admin));
    expect(($this->in)(fn () => DB::table('audit_events')->where('action', 'compensation_scheme.profile_changed')->count()))->toBe(1);
});

it('links product versions to a scheme, and hierarchy levels to a real scheme', function (): void {
    $life = ($this->scheme)('LIFE-AGENCY', 'commission');
    $catalogue = app(ProductCatalogue::class);
    $terms = ['effective_from' => '2026-01-01', 'term_months' => 120, 'earning_method' => 'monthly', 'tax_profile' => ['inclusive' => true]];

    $version = ($this->in)(fn () => $catalogue->addVersion($this->lifeProduct, [...$terms, 'compensation_scheme_id' => $life], $this->admin));
    expect($version->compensation_scheme_id)->toBe($life)
        ->and(($this->refusal)(fn () => ($this->in)(fn () => $catalogue->addVersion($this->lifeProduct, [...$terms, 'effective_from' => '2027-01-01', 'compensation_scheme_id' => (string) Str::uuid7()], $this->admin))))
        ->toBe('COMPENSATION_SCHEME_UNKNOWN')
        ->and(($this->refusal)(fn () => ($this->in)(fn () => app(HierarchyService::class)->defineLevels((string) Str::uuid7(), [['code' => 'FA', 'rank' => 1, 'label' => 'FA']], $this->admin))))
        ->toBe('COMPENSATION_SCHEME_UNKNOWN');
});

it('offers schemes and rules over the API to people who manage plans', function (): void {
    $planner = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['commission.manage_plans'])));
    $clerk = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['policy.create'])));

    actingAs($clerk)->postJson('/api/distribution/schemes', ['code' => 'X', 'name' => 'X', 'mode' => 'none', 'effective_from' => '2026-01-01'], $this->headers)->assertForbidden();
    $id = actingAs($planner)->postJson('/api/distribution/schemes', ['code' => 'LIFE', 'name' => 'Life agency', 'mode' => 'commission', 'effective_from' => '2026-01-01',
        'compliance_profile' => ['caps' => [['policy_year_from' => 1, 'policy_year_to' => 1, 'max_total_bp' => 4000]]]], $this->headers)->assertCreated()->json('data.id');
    actingAs($planner)->putJson("/api/distribution/schemes/{$id}/levels", ['levels' => [['code' => 'FA', 'rank' => 1, 'label' => 'Financial associate']]], $this->headers)->assertOk();
    actingAs($planner)->postJson("/api/distribution/schemes/{$id}/rules", ['product_id' => $this->lifeProduct, 'producer_type' => 'agent', 'level_code' => 'FA', 'basis' => 'premium_received',
        'policy_year_from' => 1, 'policy_year_to' => 1, 'rate_bp' => 4500, 'effective_from' => '2026-01-01'], $this->headers)
        ->assertStatus(422)->assertJsonPath('reason', 'COMPLIANCE_CAP_EXCEEDED');
    actingAs($planner)->getJson("/api/distribution/schemes/{$id}", $this->headers)->assertOk()->assertJsonPath('data.mode', 'commission')
        ->assertJsonPath('data.levels.0.code', 'FA')->assertJsonCount(0, 'data.rules');
});
