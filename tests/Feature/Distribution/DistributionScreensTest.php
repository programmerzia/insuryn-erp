<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Distribution\Application\Compensation\CompensationRuleRequest;
use App\Modules\Distribution\Application\Compensation\CompensationSchemeService;
use App\Modules\Distribution\Application\CreateProducer;
use App\Modules\Distribution\Application\Hierarchy\HierarchyService;
use App\Modules\Distribution\Application\Licences\LicenceService;
use App\Modules\Distribution\Application\Licences\RecordLicence;
use App\Modules\Distribution\Application\ProducerService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Distribution design note §6 screens (slice D8) on the UX brief's components: producers queue (licence expiring, advance outstanding, pending
 * statements), producer page (Overview · Hierarchy · Compensation · Production · Statements · Documents · Audit), hierarchy tree with dated
 * transfers, scheme and rule editor validated against the compliance profile, statement run workbench (prepare → approve → pay), targets grid.
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-10-05 10:00'));
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->admin = ($this->in)(fn (): User => User::query()->findOrFail($this->world['admin']));
    $admin = $this->world['admin'];
    $day = fn (string $d): CarbonImmutable => CarbonImmutable::parse($d);

    [$this->scheme, $this->product, $this->um, $this->fa] = ($this->in)(function () use ($admin, $day): array {
        $schemes = app(CompensationSchemeService::class);
        $scheme = $schemes->createScheme('LIFE-AGENCY', 'Life agency', 'commission', $day('2026-01-01'), null,
            ['allowed_producer_types' => ['agent'], 'caps' => [['policy_year_from' => 1, 'policy_year_to' => 1, 'max_total_bp' => 3500]]], $admin);
        app(HierarchyService::class)->defineLevels($scheme, [['code' => 'FA', 'rank' => 1, 'label' => 'Financial associate'], ['code' => 'UM', 'rank' => 2, 'label' => 'Unit manager']], $admin);
        $product = app(ProductCatalogue::class)->createProduct('LIFE-END', 'Endowment', 'life', $admin);
        $schemes->addRule($scheme, CompensationRuleRequest::fromArray(['basis' => 'premium_received', 'policy_year_from' => 1, 'policy_year_to' => 1, 'effective_from' => '2026-01-01',
            'product_id' => $product->id, 'producer_type' => 'agent', 'level_code' => 'FA', 'rate_bp' => 2500]), $admin);
        app(ProductCatalogue::class)->addVersion($product->id, ['effective_from' => '2026-01-01', 'term_months' => 12, 'earning_method' => 'monthly', 'tax_profile' => ['inclusive' => true],
            'compensation_scheme_id' => $scheme], $admin);
        $producer = function (string $code, ?string $parent, string $level, string $expires) use ($admin, $day): string {
            DB::table('parties')->insert(['id' => $party = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'kind' => 'individual', 'display_name' => "{$code} Name", 'status' => 'active']);
            $id = app(ProducerService::class)->create(new CreateProducer($party, $code, 'agent', $this->ctx['branch_id'], joinedOn: $day('2026-01-01')), $admin)->id;
            app(HierarchyService::class)->place($id, $parent, $level, $day('2026-01-01'), $admin);
            app(LicenceService::class)->record(new RecordLicence($id, "IDRA-{$code}", 'life', $day('2026-01-01'), $day($expires)), $admin);

            return $id;
        };
        $um = $producer('UM-1', null, 'UM', '2027-12-31');
        $fa = $producer('FA-1', $um, 'FA', '2026-11-15');  // expires in 41 days

        return [$scheme, $product->id, $um, $fa];
    });
    ($this->in)(function (): void {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->product, $this->world['policyholder_id'], $this->fa,
            CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 12_000_000, 'BDT', CarbonImmutable::parse('2026-09-05'),
            null, 'ref', [new AllocationLine((string) DB::table('installments')->where('policy_id', $policy->id)->value('id'), 12_000_000)]), $this->world['admin']);
    });
});

it('lists producers with what needs attention, and creates producers of any type', function (): void {
    actingAs(($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['claim.register']))))->get('/distribution/producers', $this->headers)->assertForbidden();

    actingAs($this->admin)->get('/distribution/producers', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('distribution/producers/Index')
        ->where('producers', function ($rows): bool {
            $byCode = array_column(json_decode((string) json_encode($rows), true), null, 'code');

            return $byCode['FA-1']['licence_state'] === 'expiring' && $byCode['FA-1']['licence_expires_on'] === '2026-11-15' && $byCode['FA-1']['level'] === 'FA'
                && $byCode['FA-1']['parent_code'] === 'UM-1' && in_array('Licence expiring', $byCode['FA-1']['attention'], true) && $byCode['UM-1']['attention'] === [];
        })->has('channels')->has('branches'));

    $party = ($this->in)(function (): string {
        DB::table('parties')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'kind' => 'organization', 'display_name' => 'Delta Brokers', 'status' => 'active']);

        return $id;
    });
    actingAs($this->admin)->post('/distribution/producers', ['party_id' => $party, 'code' => 'BRK-9', 'type' => 'broker', 'branch_id' => $this->ctx['branch_id'], 'joined_on' => '2026-10-01'], $this->headers)
        ->assertSessionHasNoErrors()->assertRedirect()->assertSessionHas('status', 'Producer BRK-9 created.');
    expect(($this->in)(fn () => DB::table('producers')->where('code', 'BRK-9')->value('type')))->toBe('broker');
});

it('shows a producer page with its hierarchy, compensation, production, statements and audit', function (): void {
    actingAs($this->admin)->get("/distribution/producers/{$this->fa}?on=2026-09-30", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('distribution/producers/Show')->where('producer.code', 'FA-1')->where('producer.name', 'FA-1 Name')
        ->where('facts', fn ($facts): bool => in_array(['label' => 'Level', 'value' => 'FA'], json_decode((string) json_encode($facts), true), true))
        ->has('licences', 1)->has('hierarchy.history', 1)->where('hierarchy.chain', ['FA-1', 'UM-1'])
        ->where('compensation.entries.0.amount', '30,000.00')->where('compensation.entries.0.role', 'direct')
        ->where('production.month.premium', '120,000.00')->where('production.month.policies', 1)
        ->has('statements')->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload->where('audit.0.action', fn (string $a): bool => $a !== '')));
});

it('records a licence and a dated transfer from the producer page', function (): void {
    actingAs($this->admin)->post("/distribution/producers/{$this->fa}/licences", ['licence_no' => 'IDRA-FA-2027', 'class' => 'life', 'issued_on' => '2026-11-01', 'expires_on' => '2027-10-31'], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Licence IDRA-FA-2027 recorded.');

    $um2 = ($this->in)(function (): string {
        DB::table('parties')->insert(['id' => $party = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'kind' => 'individual', 'display_name' => 'UM-2 Name', 'status' => 'active']);
        $id = app(ProducerService::class)->create(new CreateProducer($party, 'UM-2', 'agent', $this->ctx['branch_id'], joinedOn: CarbonImmutable::parse('2026-01-01')), $this->world['admin'])->id;
        app(HierarchyService::class)->place($id, null, 'UM', CarbonImmutable::parse('2026-01-01'), $this->world['admin']);

        return $id;
    });
    actingAs($this->admin)->post('/distribution/hierarchy/moves', ['producer_id' => $this->fa, 'parent_id' => $um2, 'level_code' => 'FA', 'effective_from' => '2026-11-01'], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'FA-1 moves under UM-2 from 1 Nov 2026.');
    actingAs($this->admin)->post('/distribution/hierarchy/moves', ['producer_id' => $this->um, 'parent_id' => $this->fa, 'level_code' => 'UM', 'effective_from' => '2026-12-01'], $this->headers)
        ->assertSessionHasErrors('form');

    actingAs($this->admin)->get('/distribution/hierarchy?scheme='.$this->scheme.'&on=2026-11-15', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('distribution/hierarchy/Index')->where('on', '2026-11-15')->has('levels', 2)
        ->where('nodes', fn ($nodes): bool => array_column(json_decode((string) json_encode($nodes), true), 'parent_id', 'code')['FA-1'] === $um2));
});

it('edits a scheme: compliance profile, levels and rules, with the compliance refusals in place', function (): void {
    actingAs($this->admin)->get("/distribution/schemes/{$this->scheme}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('distribution/schemes/Show')->where('scheme.code', 'LIFE-AGENCY')->has('rules', 1)->where('rules.0.rate_percent', '25.00')->has('products'));

    actingAs($this->admin)->post("/distribution/schemes/{$this->scheme}/rules", ['product_id' => $this->product, 'level_code' => 'UM', 'basis' => 'premium_received',
        'policy_year_from' => 1, 'policy_year_to' => 1, 'override_rate_percent' => '12.50', 'effective_from' => '2026-01-01'], $this->headers)
        ->assertSessionHasErrors(['form' => 'In policy year 1 commission could reach 3750 basis points (direct and overrides), above the cap of 3500.']);
    actingAs($this->admin)->post("/distribution/schemes/{$this->scheme}/rules", ['product_id' => $this->product, 'level_code' => 'UM', 'basis' => 'premium_received',
        'policy_year_from' => 1, 'policy_year_to' => 1, 'override_rate_percent' => '5', 'effective_from' => '2026-01-01'], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Rule added.');
    actingAs($this->admin)->put("/distribution/schemes/{$this->scheme}/compliance-profile", ['allowed_producer_types' => ['agent', 'broker'], 'non_life_commission_allowed' => false,
        'caps' => [['policy_year_from' => 1, 'policy_year_to' => 1, 'max_total_percent' => '30']]], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Compliance profile saved.');
    actingAs($this->admin)->put("/distribution/schemes/{$this->scheme}/levels", ['levels' => [['code' => 'FA', 'rank' => 1, 'label' => 'FA'], ['code' => 'UM', 'rank' => 2, 'label' => 'UM'], ['code' => 'BM', 'rank' => 3, 'label' => 'BM']]], $this->headers)
        ->assertSessionHasNoErrors();
    expect(($this->in)(fn () => DB::table('hierarchy_levels')->where('scheme_id', $this->scheme)->count()))->toBe(3);
});

it('runs statements from the workbench: prepare, approve with the journal preview, pay by someone else', function (): void {
    $approver = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['commission.approve'])));
    $payer = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['commission.pay'])));

    actingAs($approver)->post('/distribution/statements/prepare', ['period_end' => '2026-09-30'], $this->headers)->assertSessionHasNoErrors()->assertSessionHas('status', '1 draft statement prepared for 30 Sep 2026.');
    actingAs($approver)->get('/distribution/statements?period_end=2026-09-30', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('distribution/statements/Index')->where('statements.0.producer_code', 'FA-1')->where('statements.0.net', '30,000.00')->where('statements.0.status', 'draft')
        ->where('can.approve', true)->where('can.pay', false));
    $id = ($this->in)(fn (): string => (string) DB::table('commission_statements')->value('id'));

    actingAs($approver)->post("/distribution/statements/{$id}/approve", ['on' => '2026-10-01'], $this->headers)->assertSessionHasNoErrors()->assertSessionHas('status', fn (string $s): bool => str_starts_with($s, 'Statement CST-'));
    actingAs($payer)->withHeaders([...$this->headers, 'X-Journal-Preview' => '1'])->postJson("/distribution/statements/{$id}/pay", ['paid_on' => '2026-10-02'])->assertOk()
        ->assertJsonPath('posts', true)->assertJsonPath('journals.0.lines.1.name', 'Accounts Payable');
    $this->flushHeaders();
    expect(($this->in)(fn () => DB::table('commission_statements')->value('status')))->toBe('approved'); // the preview posted nothing
    actingAs($payer)->post("/distribution/statements/{$id}/pay", ['paid_on' => '2026-10-02'], $this->headers)->assertSessionHasNoErrors()->assertSessionHas('status', fn (string $s): bool => str_contains($s, 'accounts payable'));
    expect(($this->in)(fn () => DB::table('commission_statements')->value('status')))->toBe('paid');
});

it('shows the targets grid with actuals and saves a target from a cell', function (): void {
    actingAs($this->admin)->put('/distribution/targets', ['subject_type' => 'producer', 'subject_id' => $this->fa, 'period_type' => 'monthly', 'period_start' => '2026-09-01', 'metric' => 'premium', 'value' => '100,000.00'], $this->headers)
        ->assertSessionHasNoErrors();

    actingAs($this->admin)->get('/distribution/targets?period_type=monthly&period_start=2026-09-01&metric=premium', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('distribution/targets/Index')->where('metric', 'premium')
        ->where('rows.producer', fn ($rows): bool => array_column(json_decode((string) json_encode($rows), true), null, 'code')['FA-1'] === [
            'id' => $this->fa, 'code' => 'FA-1', 'name' => 'FA-1 Name', 'target' => '100,000.00', 'actual' => '120,000.00', 'achievement_percent' => '120.00',
        ])
        ->where('rows.branch.0.actual', '120,000.00'));
});
