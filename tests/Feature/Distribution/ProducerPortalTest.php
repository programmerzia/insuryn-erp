<?php

declare(strict_types=1);

use App\Http\Portal\OpenApi\PortalOpenApi;
use App\Modules\Distribution\Application\CreateProducer;
use App\Modules\Distribution\Application\Licences\LicenceService;
use App\Modules\Distribution\Application\Licences\RecordLicence;
use App\Modules\Distribution\Application\Portal\ProducerPortalAccess;
use App\Modules\Distribution\Application\ProducerService;
use App\Modules\Distribution\Application\Targets\TargetService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

/**
 * Distribution design note §5 producer portal, MVP (slice D9): REST for producers — my customers and policies, renewals due, collections to deposit,
 * my statements, licence status, targets vs achievement — read-only except recording a collection. Sanctum tokens for portal users only
 * (a portal user is linked to one producer and never signs in to the staff web app); tokens are tenant-scoped. OpenAPI is generated from the code.
 */
beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2026-09-20 09:00'));
    Notification::fake();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->agent = $this->world['agent_id'];
    $this->other = ($this->in)(function (): string {
        DB::table('parties')->insert(['id' => $party = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'kind' => 'individual', 'display_name' => 'Other Agent', 'status' => 'active']);
        $id = app(ProducerService::class)->create(new CreateProducer($party, 'AG-002', 'agent', $this->ctx['branch_id'], joinedOn: CarbonImmutable::parse('2026-01-01')), $this->world['admin'])->id;
        app(LicenceService::class)->record(new RecordLicence($id, 'IDRA-OTHER', 'both', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-12-31')), $this->world['admin']);

        return $id;
    });
    $this->sell = fn (string $producer, string $inception, int $installments = 2): string => ($this->in)(function () use ($producer, $inception, $installments): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            $producer, CarbonImmutable::parse($inception), 12_000_000, 'BDT', $installments), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse($inception), $this->world['admin']);

        return $policy->id;
    });
    $this->mine = ($this->sell)($this->agent, '2026-01-01');
    $this->theirs = ($this->sell)($this->other, '2026-09-01');
    $this->portalUser = ($this->in)(function (): string {
        $userId = app(ProducerPortalAccess::class)->grant($this->agent, 'jamal@agents.test', $this->world['admin']);
        DB::table('users')->where('id', $userId)->update(['password' => Hash::make('Portal-pass-1')]);

        return $userId;
    });
    $this->token = fn (array $abilities = ['portal:read', 'portal:collect']): string => (string) postJson('/api/portal/tokens',
        ['email' => 'jamal@agents.test', 'password' => 'Portal-pass-1', 'device_name' => 'phone', 'abilities' => $abilities], $this->headers)->assertCreated()->json('data.token');
});

it('gives tokens to portal users only, never lets them into the staff web app, and scopes tokens to the tenant', function (): void {
    postJson('/api/portal/tokens', ['email' => 'jamal@agents.test', 'password' => 'wrong', 'device_name' => 'phone'], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('email');
    $staff = ($this->in)(fn () => DB::table('users')->where('id', $this->world['admin'])->value('email'));
    ($this->in)(fn () => DB::table('users')->where('id', $this->world['admin'])->update(['password' => Hash::make('Staff-pass-1')]));
    postJson('/api/portal/tokens', ['email' => $staff, 'password' => 'Staff-pass-1', 'device_name' => 'phone'], $this->headers)->assertUnprocessable();

    post('/login', ['email' => 'jamal@agents.test', 'password' => 'Portal-pass-1'], ['X-Tenant' => $this->ctx['tenant_id']])->assertSessionHasErrors('email');

    $token = ($this->token)();
    getJson('/api/portal/me', [...$this->headers, 'Authorization' => "Bearer {$token}"])->assertOk()->assertJsonPath('data.code', 'AG-001');
    $otherTenant = seedDemoTenant('elsewhere');
    getJson('/api/portal/me', ['X-Tenant' => $otherTenant['tenant_id'], 'Accept' => 'application/json', 'Authorization' => "Bearer {$token}"])->assertUnauthorized();
    getJson('/api/portal/me', $this->headers)->assertUnauthorized();
});

it('shows a producer its own customers, policies, renewals, licence, statements and targets only', function (): void {
    $auth = [...$this->headers, 'Authorization' => 'Bearer '.($this->token)()];
    ($this->in)(fn () => app(TargetService::class)->set('producer', $this->agent, 'monthly', CarbonImmutable::parse('2026-09-01'), 'premium', 10_000_000, $this->world['admin']));

    getJson('/api/portal/policies', $auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->mine)->assertJsonPath('data.0.policyholder', 'Rahima Akter');
    getJson("/api/portal/policies/{$this->mine}", $auth)->assertOk()->assertJsonCount(2, 'data.installments')->assertJsonPath('data.installments.0.outstanding_minor', 6_000_000);
    getJson("/api/portal/policies/{$this->theirs}", $auth)->assertNotFound();
    getJson('/api/portal/customers', $auth)->assertOk()->assertJsonPath('data.0.name', 'Rahima Akter')->assertJsonPath('data.0.policies', 1);
    getJson('/api/portal/renewals-due?within_days=120', $auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.expiry', '2026-12-31');
    getJson('/api/portal/licence', $auth)->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.licences.0.licence_no', 'IDRA-TEST-001');
    getJson('/api/portal/statements', $auth)->assertOk()->assertJsonCount(0, 'data');
    getJson('/api/portal/targets?period_type=monthly&period_start=2026-09-01', $auth)->assertOk()->assertJsonPath('data.0.metric', 'premium')
        ->assertJsonPath('data.0.target', 10_000_000)->assertJsonPath('data.0.actual', 0);
    getJson('/api/portal/collections-to-deposit', $auth)->assertOk()->assertJsonPath('data.undeposited_minor', 0);
});

it('records a cash collection for its own policy, and nothing else', function (): void {
    $auth = [...$this->headers, 'Authorization' => 'Bearer '.($this->token)()];
    $mine = ($this->in)(fn (): string => (string) DB::table('installments')->where('policy_id', $this->mine)->orderBy('no')->value('id'));
    $theirs = ($this->in)(fn (): string => (string) DB::table('installments')->where('policy_id', $this->theirs)->orderBy('no')->value('id'));

    postJson('/api/portal/collections', ['installment_id' => $mine, 'amount_minor' => 2_000_000, 'value_date' => '2026-09-19', 'reference' => 'Field slip 17'], $auth)
        ->assertCreated()->assertJsonPath('data.amount_minor', 2_000_000)->assertJsonPath('data.number', fn (string $n): bool => str_starts_with($n, 'RCT-'));
    expect(($this->in)(fn () => DB::table('receipts')->where('collected_by_agent_id', $this->agent)->where('channel', 'cash')->count()))->toBe(1)
        ->and(($this->in)(fn () => (int) DB::table('installments')->where('id', $mine)->value('paid_minor')))->toBe(2_000_000);
    getJson('/api/portal/collections-to-deposit', $auth)->assertJsonPath('data.undeposited_minor', 2_000_000);

    postJson('/api/portal/collections', ['installment_id' => $theirs, 'amount_minor' => 100_000, 'value_date' => '2026-09-19'], $auth)->assertNotFound();
    postJson('/api/portal/collections', ['installment_id' => $mine, 'amount_minor' => 99_000_000, 'value_date' => '2026-09-19'], $auth)
        ->assertUnprocessable()->assertJsonPath('reason', 'ALLOCATION_EXCEEDS_OUTSTANDING');
    $readOnly = ($this->token)(['portal:read']);
    app('auth')->forgetGuards(); // each HTTP request authenticates afresh; in one test the guard would keep the previous token
    postJson('/api/portal/collections', ['installment_id' => $mine, 'amount_minor' => 100_000, 'value_date' => '2026-09-19'],
        [...$this->headers, 'Authorization' => "Bearer {$readOnly}"])->assertForbidden();
});

it('refuses portal access once the producer is suspended, and revokes a token on sign-out', function (): void {
    $token = ($this->token)();
    $auth = [...$this->headers, 'Authorization' => "Bearer {$token}"];
    Pest\Laravel\deleteJson('/api/portal/tokens/current', [], $auth)->assertNoContent();
    app('auth')->forgetGuards();
    getJson('/api/portal/me', $auth)->assertUnauthorized();

    $fresh = [...$this->headers, 'Authorization' => 'Bearer '.($this->token)()];
    ($this->in)(fn () => DB::table('producers')->where('id', $this->agent)->update(['status' => 'suspended']));
    getJson('/api/portal/me', $fresh)->assertForbidden()->assertJsonPath('reason', 'PRODUCER_NOT_ACTIVE');
});

it('documents every portal route in the generated OpenAPI file, which is up to date', function (): void {
    $document = app(PortalOpenApi::class)->document();
    $documented = [];
    foreach ($document['paths'] as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            $documented[] = strtoupper($method).' '.$path;
        }
    }
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r): bool => str_starts_with($r->uri(), 'api/portal'))
        ->flatMap(fn ($r) => collect($r->methods())->reject(fn (string $m): bool => $m === 'HEAD')->map(fn (string $m): string => $m.' /'.preg_replace('/\{(\w+)\}/', '{$1}', $r->uri())))
        ->sort()->values()->all();

    expect($document['openapi'])->toBe('3.1.0')
        ->and(array_values(array_diff($routes, $documented)))->toBe([])
        ->and(json_decode((string) file_get_contents(base_path('docs/api/producer-portal.openapi.json')), true))->toEqual($document);
});
