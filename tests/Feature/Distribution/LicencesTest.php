<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Distribution\Application\Licences\LicenceExpiryAlerts;
use App\Modules\Distribution\Application\Licences\LicenceService;
use App\Modules\Distribution\Application\Licences\RecordLicence;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * Distribution design note §3 (slice D2): no new business for a producer without a valid licence of the right class (blocking); renewals are
 * not new business; licence-expiry alerts at 60/30/7 days; the IDRA agent register export. seedInsuranceWorld gives agent AG-001 a licence
 * for both classes (2020–2030), so tests written before D2 keep issuing business through a licensed agent.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->admin = ($this->in)(fn (): User => User::query()->findOrFail($this->world['admin']));
    $this->agent = $this->world['agent_id'];
    ($this->in)(fn () => DB::table('producer_licences')->where('producer_id', $this->agent)->delete());
    $this->licence = fn (string $class, string $issued, string $expires, string $number = 'IDRA-A-1'): string => ($this->in)(fn (): string => app(LicenceService::class)
        ->record(new RecordLicence($this->agent, $number, $class, CarbonImmutable::parse($issued), CarbonImmutable::parse($expires)), $this->world['admin']));
    $this->quoteAndIssue = function (?string $producerId, string $on, ?string $productId = null): mixed {
        return ($this->in)(function () use ($producerId, $on, $productId) {
            $lifecycle = app(PolicyLifecycle::class);
            $quote = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $productId ?? $this->world['product_id'], $this->world['policyholder_id'],
                $producerId, CarbonImmutable::parse($on), 12_000_000, 'BDT'), $this->world['admin']);

            return $lifecycle->issue($quote->id, CarbonImmutable::parse($on), $this->world['admin']);
        });
    };
});

it('classes products as life or non-life from their line of business unless told', function (): void {
    $catalogue = app(ProductCatalogue::class);
    [$life, $motor, $explicit] = ($this->in)(fn (): array => [
        $catalogue->createProduct('TERM-1', 'Term life', 'life', $this->world['admin']),
        $catalogue->createProduct('MOT-2', 'Motor', 'motor', $this->world['admin']),
        $catalogue->createProduct('HEALTH-1', 'Health rider', 'health', $this->world['admin'], 'life'),
    ]);

    expect([$life->insurance_class, $motor->insurance_class, $explicit->insurance_class])->toBe(['life', 'non_life', 'life']);
});

it('records licences and refuses duplicates and impossible dates', function (): void {
    actingAs($this->admin)->postJson("/api/distribution/producers/{$this->agent}/licences", ['licence_no' => 'IDRA-77', 'class' => 'non_life', 'issued_on' => '2026-01-01', 'expires_on' => '2026-12-31'], $this->headers)
        ->assertCreated()->assertJsonPath('data.authority', 'IDRA')->assertJsonPath('data.status', 'active');
    actingAs($this->admin)->postJson("/api/distribution/producers/{$this->agent}/licences", ['licence_no' => 'IDRA-77', 'class' => 'life', 'issued_on' => '2026-01-01', 'expires_on' => '2026-12-31'], $this->headers)
        ->assertUnprocessable()->assertJsonValidationErrors('licence_no');
    actingAs($this->admin)->postJson("/api/distribution/producers/{$this->agent}/licences", ['licence_no' => 'IDRA-78', 'class' => 'life', 'issued_on' => '2026-06-01', 'expires_on' => '2026-01-31'], $this->headers)
        ->assertUnprocessable()->assertJsonValidationErrors('expires_on');
    actingAs($this->admin)->getJson("/api/distribution/producers/{$this->agent}/licences", $this->headers)->assertOk()->assertJsonCount(1, 'data');
});

it('blocks new business without a valid licence of the product class', function (): void {
    $refusal = fn (callable $issue): string => thrownBy($issue, BusinessRuleViolation::class)->reasonCode;

    expect($refusal(fn () => ($this->quoteAndIssue)($this->agent, '2026-09-01')))->toBe('LICENCE_REQUIRED');

    ($this->licence)('life', '2026-01-01', '2026-12-31', 'IDRA-LIFE');
    expect($refusal(fn () => ($this->quoteAndIssue)($this->agent, '2026-09-01')))->toBe('LICENCE_REQUIRED'); // motor is non-life

    $nonLife = ($this->licence)('non_life', '2025-01-01', '2026-08-31', 'IDRA-NL-OLD');
    expect($refusal(fn () => ($this->quoteAndIssue)($this->agent, '2026-09-01')))->toBe('LICENCE_REQUIRED'); // expired the day before
    expect(($this->quoteAndIssue)($this->agent, '2026-08-31')->status->value)->toBe('issued');                 // still valid on its last day

    $both = ($this->licence)('both', '2026-09-01', '2027-08-31', 'IDRA-BOTH');
    expect(($this->quoteAndIssue)($this->agent, '2026-09-01')->status->value)->toBe('issued');

    ($this->in)(fn () => app(LicenceService::class)->suspend($both, 'Under investigation', $this->world['admin']));
    expect($refusal(fn () => ($this->quoteAndIssue)($this->agent, '2026-09-02')))->toBe('LICENCE_REQUIRED')
        ->and(($this->quoteAndIssue)(null, '2026-09-02')->status->value)->toBe('issued');  // direct business needs no licence
});

it('says which licence is missing, and refuses producers who may not sell', function (): void {
    $violation = thrownBy(fn () => ($this->quoteAndIssue)($this->agent, '2026-09-01'), BusinessRuleViolation::class);
    expect($violation->getMessage())->toBe('AG-001 has no valid non-life licence on 2026-09-01, so it cannot write new business.');

    ($this->licence)('both', '2020-01-01', '2030-12-31');
    ($this->in)(fn () => DB::table('producers')->where('id', $this->agent)->update(['status' => 'suspended']));
    expect(thrownBy(fn () => ($this->quoteAndIssue)($this->agent, '2026-09-01'), BusinessRuleViolation::class)->reasonCode)->toBe('PRODUCER_NOT_ACTIVE');
});

it('lets a renewal through when the licence has lapsed: it is not new business', function (): void {
    ($this->licence)('both', '2026-01-01', '2026-12-31');
    $policy = ($this->quoteAndIssue)($this->agent, '2026-01-15');
    ($this->in)(fn () => DB::table('producer_licences')->update(['expires_on' => '2026-06-30']));
    ($this->in)(fn () => DB::table('policies')->where('id', $policy->id)->update(['status' => 'expired']));

    $renewal = ($this->in)(fn () => app(PolicyLifecycle::class)->renew($policy->id, $this->world['admin']));
    expect(($this->in)(fn () => app(PolicyLifecycle::class)->issue($renewal->id, CarbonImmutable::parse('2026-12-20'), $this->world['admin']))->status->value)->toBe('issued');
});

it('raises each crossed threshold once and skips licences already renewed', function (): void {
    $expiring = ($this->licence)('non_life', '2026-01-01', '2026-12-31', 'IDRA-EXP');
    $alerts = app(LicenceExpiryAlerts::class);
    $run = fn (string $day): int => ($this->in)(fn (): int => $alerts->run(CarbonImmutable::parse($day)));
    $raised = fn (): array => ($this->in)(fn (): array => DB::table('producer_licence_alerts')->where('licence_id', $expiring)->orderByDesc('days_before')->pluck('days_before')->map(fn ($d): int => (int) $d)->all());

    expect($run('2026-10-01'))->toBe(0)       // 91 days left
        ->and($run('2026-11-01'))->toBe(1)    // 60 days left
        ->and($run('2026-11-02'))->toBe(0)    // rerun: nothing new
        ->and($run('2026-12-26'))->toBe(2)    // 5 days left, the job did not run at 30 days: 30 and 7 are both raised now
        ->and($raised())->toBe([60, 30, 7])
        ->and(($this->in)(fn () => DB::table('outbox')->where('message_type', 'ProducerLicenceExpiring')->count()))->toBe(3);

    ($this->licence)('non_life', '2026-12-15', '2027-12-31', 'IDRA-RENEWED');
    ($this->in)(fn () => DB::table('producer_licence_alerts')->delete());
    expect($run('2026-12-26'))->toBe(0);
});

it('exports the IDRA agent register as CSV for people with regulatory reports', function (): void {
    ($this->licence)('both', '2020-01-01', '2026-06-30', 'IDRA-0001');
    $reader = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['reports.regulatory'])));

    actingAs(($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['policy.create']))))
        ->get('/api/distribution/licences/register?as_of=2026-09-30', $this->headers)->assertForbidden();
    $csv = actingAs($reader)->get('/api/distribution/licences/register?as_of=2026-09-30', $this->headers)->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();

    expect(explode("\n", trim($csv)))->toBe([
        'licence_no,authority,producer_code,producer_name,producer_type,class,issued_on,expires_on,status,branch_code',
        'IDRA-0001,IDRA,AG-001,"Jamal Agent",agent,both,2020-01-01,2026-06-30,expired,HO',
    ]);
});
