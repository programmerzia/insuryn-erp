<?php

declare(strict_types=1);

use App\Http\Ledger\LedgerTokenController;
use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Infrastructure\Jobs\PostAccountingEventJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/** Sanctum reuses the authenticated user within one Pest example unless the guard is cleared. */
function forgetLedgerAuthGuards(): void
{
    app('auth')->forgetGuards();
}

/**
 * Insuryn Ledger API slice 1 (docs/plan/api-accounting-v1.md): integration tokens, event ingest, reads.
 * Kernel posting rules and insurance web flows stay unchanged.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id'], 'Accept' => 'application/json'];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->integrationUser = ($this->in)(function (): string {
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'tenant_id' => $this->ctx['tenant_id'], 'email' => 'integration@example.test', 'name' => 'Integration', 'password' => Hash::make('Ledger-pass-1'), 'status' => 'active', 'kind' => 'integration']);

        return $id;
    });
    $this->token = fn (array $abilities = LedgerTokenController::ABILITIES): string => (string) postJson('/api/v1/tokens',
        ['email' => 'integration@example.test', 'password' => 'Ledger-pass-1', 'device_name' => 'ci', 'abilities' => $abilities], $this->headers)->assertCreated()->json('data.token');
    $this->auth = fn (array $abilities = LedgerTokenController::ABILITIES): array => [...$this->headers, 'Authorization' => 'Bearer '.($this->token)($abilities)];
    $this->eventBody = fn (string $key = 'PREMIUM_RECEIVED:API-1', array $overrides = []): array => array_replace([
        'event_type' => 'PREMIUM_RECEIVED',
        'idempotency_key' => $key,
        'transaction_date' => '2026-09-15',
        'currency' => 'KES',
        'payload' => ['amount' => 5_000_000],
        'dimensions' => ['branch' => $this->ctx['branch_id'], 'product' => (string) Str::uuid7(), 'product_code' => 'MOTOR', 'lob' => 'motor', 'channel' => 'agent', 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7(), 'agent' => (string) Str::uuid7(), 'claim' => (string) Str::uuid7()],
        'source' => ['type' => 'receipt', 'id' => 'RCT-API-1', 'number' => 'RCT-HO-2026-000099'],
    ], $overrides);
});

it('issues tokens to integration users only', function (): void {
    postJson('/api/v1/tokens', ['email' => 'integration@example.test', 'password' => 'wrong', 'device_name' => 'ci'], $this->headers)->assertUnprocessable();
    getJson('/api/v1/event-types', $this->headers)->assertUnauthorized();

    ($this->in)(function (): void {
        $staffId = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $staffId, 'tenant_id' => $this->ctx['tenant_id'], 'email' => 'staff@example.test', 'name' => 'Staff', 'password' => Hash::make('Staff-pass-1'), 'status' => 'active', 'kind' => 'staff']);
        postJson('/api/v1/tokens', ['email' => 'staff@example.test', 'password' => 'Staff-pass-1', 'device_name' => 'ci'], $this->headers)->assertUnprocessable();
    });

    $token = ($this->token)();
    forgetLedgerAuthGuards();
    $types = getJson('/api/v1/event-types', [...$this->headers, 'Authorization' => "Bearer {$token}"])->assertOk()
        ->assertJsonStructure(['data' => [['event_type', 'rule_code', 'version', 'effective_from']]])
        ->json('data');
    expect(collect($types)->contains(fn (array $row): bool => $row['event_type'] === 'PREMIUM_RECEIVED'))->toBeTrue();
});

it('refuses producer portal tokens on ledger routes', function (): void {
    ($this->in)(function (): void {
        $portalId = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $portalId, 'tenant_id' => $this->ctx['tenant_id'], 'email' => 'portal@example.test', 'name' => 'Portal', 'password' => Hash::make('Portal-pass-1'), 'status' => 'active', 'kind' => 'portal']);
        $portalToken = User::query()->findOrFail($portalId)->createToken('phone', ['portal:read'])->plainTextToken;
        getJson('/api/v1/event-types', [...$this->headers, 'Authorization' => "Bearer {$portalToken}"])->assertForbidden();
    });
});

it('scopes read and write abilities separately', function (): void {
    $readOnly = ($this->auth)(['integration:events:read']);
    forgetLedgerAuthGuards();
    $writeOnly = ($this->auth)(['integration:events:write']);
    forgetLedgerAuthGuards();

    getJson('/api/v1/event-types', $readOnly)->assertOk();
    forgetLedgerAuthGuards();
    postJson('/api/v1/events', ($this->eventBody)('PREMIUM_RECEIVED:SCOPE-READ'), $readOnly)->assertForbidden();

    forgetLedgerAuthGuards();
    postJson('/api/v1/events', ($this->eventBody)('PREMIUM_RECEIVED:SCOPE-WRITE'), $writeOnly)->assertAccepted();
    forgetLedgerAuthGuards();
    getJson('/api/v1/event-types', $writeOnly)->assertForbidden();
});

it('revokes the current token and refuses cross-tenant reuse', function (): void {
    $auth = ($this->auth)();
    forgetLedgerAuthGuards();
    getJson('/api/v1/event-types', $auth)->assertOk();
    deleteJson('/api/v1/tokens/current', [], $auth)->assertNoContent();
    forgetLedgerAuthGuards();
    getJson('/api/v1/event-types', $auth)->assertUnauthorized();

    $other = seedDemoTenant('ledger-other');
    forgetLedgerAuthGuards();
    getJson('/api/v1/event-types', ['X-Tenant' => $other['tenant_id'], 'Accept' => 'application/json', 'Authorization' => $auth['Authorization']])->assertUnauthorized();
});

it('validates the event payload before accepting anything', function (): void {
    $auth = ($this->auth)();
    postJson('/api/v1/events', [], $auth)->assertUnprocessable()->assertJsonValidationErrors(['event_type', 'idempotency_key', 'transaction_date', 'currency', 'payload', 'dimensions', 'source']);
    postJson('/api/v1/events', ($this->eventBody)('PREMIUM_RECEIVED:VALIDATE', ['source' => ['type' => 'receipt']]), $auth)->assertUnprocessable()->assertJsonValidationErrors(['source.id']);
});

it('queues posting asynchronously and keeps idempotency', function (): void {
    Queue::fake();
    $auth = ($this->auth)();

    $first = postJson('/api/v1/events', ($this->eventBody)(), $auth)->assertAccepted()
        ->assertJsonPath('data.status', 'queued')->assertJsonPath('data.created', true);
    $eventId = (string) $first->json('data.event_id');
    expect(($this->in)(fn (): int => DB::table('external_event_intakes')->where('accounting_event_id', $eventId)->count()))->toBe(1);

    Queue::assertPushedOn('posting', PostAccountingEventJob::class, fn (PostAccountingEventJob $job): bool => $job->eventId === $eventId && $job->tenantId === $this->ctx['tenant_id']);

    postJson('/api/v1/events', ($this->eventBody)(), $auth)->assertOk()
        ->assertJsonPath('data.event_id', $eventId)->assertJsonPath('data.created', false);
    Queue::assertPushed(PostAccountingEventJob::class, 1);
});

it('posts synchronously with balanced journal lines matching the golden fixture', function (): void {
    Queue::fake(); // keep the background worker from claiming the event before ?sync=1 posts it
    $auth = ($this->auth)();
    forgetLedgerAuthGuards();
    $fx = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/golden/02_premium_received.json'), true, 512, JSON_THROW_ON_ERROR);
    $body = ($this->eventBody)('PREMIUM_RECEIVED:API-SYNC', [
        'payload' => $fx['payload'],
        'dimensions' => array_merge($fx['dimensions'], ['branch' => $this->ctx['branch_id'], 'product' => (string) Str::uuid7(), 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7(), 'agent' => (string) Str::uuid7(), 'claim' => (string) Str::uuid7()]),
    ]);

    $response = postJson('/api/v1/events?sync=1', $body, $auth)->assertCreated()
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.journals.0.number', fn (?string $n): bool => str_starts_with((string) $n, 'JV-2026-'));

    ($this->in)(function () use ($response, $fx): void {
        $journal = Journal::query()->with('lines')->findOrFail((string) $response->json('data.journals.0.id'));
        expect($journal->lines->map(fn ($l) => [$l->role_code, $l->side->value, $l->amount_minor])->all())->toEqual($fx['expected']);
    });
});

it('reads event status and returns not found for unknown ids', function (): void {
    $auth = ($this->auth)();
    Queue::fake();

    $queued = postJson('/api/v1/events', ($this->eventBody)('PREMIUM_RECEIVED:API-READ'), $auth)->assertAccepted();
    getJson('/api/v1/events/'.$queued->json('data.event_id'), $auth)->assertOk()
        ->assertJsonPath('data.status', 'queued')->assertJsonPath('data.source.id', 'RCT-API-1')->assertJsonPath('data.journals', []);

    getJson('/api/v1/events/'.(string) Str::uuid7(), $auth)->assertNotFound();
});

it('stores effective_date separately and marks unknown event types failed on sync post', function (): void {
    Queue::fake();
    $auth = ($this->auth)();
    forgetLedgerAuthGuards();

    postJson('/api/v1/events?sync=1', ($this->eventBody)('PREMIUM_RECEIVED:EFFECTIVE', [
        'transaction_date' => '2026-09-10',
        'effective_date' => '2026-09-12',
    ]), $auth)->assertCreated();

    expect(($this->in)(fn (): string => (string) DB::table('accounting_events')->where('idempotency_key', 'PREMIUM_RECEIVED:EFFECTIVE')->value('effective_date')))->toBe('2026-09-12');

    postJson('/api/v1/events?sync=1', ($this->eventBody)('UNKNOWN:EVENT', ['event_type' => 'NOT_A_REAL_EVENT']), $auth)->assertAccepted()
        ->assertJsonPath('data.status', 'failed')->assertJsonMissingPath('data.journals.0');
});

it('reads a posted journal with line totals', function (): void {
    Queue::fake();
    $auth = ($this->auth)();
    forgetLedgerAuthGuards();
    $fx = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/golden/02_premium_received.json'), true, 512, JSON_THROW_ON_ERROR);
    $body = ($this->eventBody)('PREMIUM_RECEIVED:API-JOURNAL', [
        'payload' => $fx['payload'],
        'dimensions' => array_merge($fx['dimensions'], ['branch' => $this->ctx['branch_id'], 'product' => (string) Str::uuid7(), 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7(), 'agent' => (string) Str::uuid7(), 'claim' => (string) Str::uuid7()]),
    ]);
    $posted = postJson('/api/v1/events?sync=1', $body, $auth)->assertCreated();
    $journalId = (string) $posted->json('data.journals.0.id');

    forgetLedgerAuthGuards();
    getJson("/api/v1/journals/{$journalId}", $auth)->assertOk()
        ->assertJsonPath('data.totals.balanced', true)
        ->assertJsonPath('data.totals.debit_minor', 5_000_000)
        ->assertJsonPath('data.event.event_type', 'PREMIUM_RECEIVED');
});

it('returns profit and loss and balance sheet reports', function (): void {
    Queue::fake();
    $auth = ($this->auth)();
    forgetLedgerAuthGuards();
    postJson('/api/v1/events?sync=1', ($this->eventBody)('PREMIUM_RECEIVED:API-PNL'), $auth)->assertCreated();

    forgetLedgerAuthGuards();
    getJson('/api/v1/reports/profit-and-loss?from=2026-07-01&to=2026-09-30', $auth)->assertOk()
        ->assertJsonStructure(['data' => ['income', 'expense', 'total_income_minor', 'total_expense_minor', 'net_profit_minor']]);

    forgetLedgerAuthGuards();
    getJson('/api/v1/reports/balance-sheet?as_of=2026-09-15', $auth)->assertOk()
        ->assertJsonStructure(['data' => ['assets', 'liabilities', 'equity', 'total_assets_minor', 'total_liabilities_minor', 'total_equity_minor']]);
});

it('posts bank charges through the ledger API', function (): void {
    Queue::fake();
    $auth = ($this->auth)();
    forgetLedgerAuthGuards();
    $posted = postJson('/api/v1/events?sync=1', ($this->eventBody)('BANK_CHARGE:API-1', [
        'event_type' => 'BANK_CHARGE',
        'payload' => ['amount' => 2_500],
        'source' => ['type' => 'bank_fee', 'id' => 'FEE-1', 'number' => 'MPESA-SEP-001'],
    ]), $auth)->assertCreated()
        ->assertJsonPath('data.event_type', 'BANK_CHARGE');
    $journalId = (string) $posted->json('data.journals.0.id');

    forgetLedgerAuthGuards();
    getJson("/api/v1/journals/{$journalId}", $auth)->assertOk()
        ->assertJsonPath('data.totals.balanced', true)
        ->assertJsonPath('data.totals.debit_minor', 2_500)
        ->assertJsonPath('data.event.event_type', 'BANK_CHARGE');
});

it('returns trial balance and account balance reads', function (): void {
    Queue::fake();
    $auth = ($this->auth)();
    forgetLedgerAuthGuards();
    postJson('/api/v1/events?sync=1', ($this->eventBody)('PREMIUM_RECEIVED:API-TB'), $auth)->assertCreated();

    forgetLedgerAuthGuards();
    getJson('/api/v1/trial-balance?as_of=2026-09-15', $auth)->assertOk()
        ->assertJsonPath('data.totals.balanced', true)
        ->assertJsonStructure(['data' => ['rows', 'totals' => ['debit_minor', 'credit_minor', 'balanced']]]);

    forgetLedgerAuthGuards();
    getJson('/api/v1/balances?account=1010&as_of=2026-09-15', $auth)->assertOk()
        ->assertJsonStructure(['data' => ['account_code', 'balance_minor', 'currency']]);
});

it('previews and replays event lines without writing', function (): void {
    Queue::fake();
    $auth = ($this->auth)();
    forgetLedgerAuthGuards();
    $fx = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/golden/02_premium_received.json'), true, 512, JSON_THROW_ON_ERROR);
    $previewBody = [
        'event_type' => 'PREMIUM_RECEIVED',
        'transaction_date' => '2026-09-15',
        'currency' => 'KES',
        'payload' => $fx['payload'],
        'dimensions' => array_merge($fx['dimensions'], ['branch' => $this->ctx['branch_id'], 'product' => (string) Str::uuid7(), 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7(), 'agent' => (string) Str::uuid7(), 'claim' => (string) Str::uuid7()]),
    ];

    postJson('/api/v1/events/preview', $previewBody, $auth)->assertOk()
        ->assertJsonPath('data.posts', true)->assertJsonPath('data.balanced', true)->assertJsonCount(2, 'data.lines');

    $posted = postJson('/api/v1/events?sync=1', ($this->eventBody)('PREMIUM_RECEIVED:API-REPLAY', [
        'payload' => $fx['payload'],
        'dimensions' => $previewBody['dimensions'],
    ]), $auth)->assertCreated();
    $eventId = (string) $posted->json('data.event_id');

    forgetLedgerAuthGuards();
    postJson("/api/v1/events/{$eventId}/replay", [], $auth)->assertOk()
        ->assertJsonPath('data.matches', true)->assertJsonPath('data.preview.posts', true);
});

it('documents every ledger route in the generated OpenAPI file', function (): void {
    $document = app(\App\Http\Ledger\OpenApi\LedgerOpenApi::class)->document();
    $documented = [];
    foreach ($document['paths'] as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            $documented[] = strtoupper($method).' '.$path;
        }
    }
    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->filter(fn ($r): bool => str_starts_with($r->uri(), 'api/v1'))
        ->flatMap(fn ($r) => collect($r->methods())->reject(fn (string $m): bool => $m === 'HEAD')->map(fn (string $m): string => $m.' /'.preg_replace('/\{(\w+)\}/', '{$1}', $r->uri())))
        ->sort()->values()->all();

    expect($document['openapi'])->toBe('3.1.0')
        ->and(array_values(array_diff($routes, $documented)))->toBe([])
        ->and(json_decode((string) file_get_contents(base_path('docs/api/ledger-v1.openapi.json')), true))->toEqual($document);
});

it('does not change kernel golden posting behaviour', function (): void {
    Queue::fake();
    $ctx = $this->ctx;
    $fx = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/golden/02_premium_received.json'), true, 512, JSON_THROW_ON_ERROR);
    $dims = $fx['dimensions'];
    $dims['branch'] = $ctx['branch_id'];
    foreach (['product', 'policy', 'customer', 'agent', 'claim'] as $d) {
        $dims[$d] = (string) Str::uuid7();
    }

    ($this->in)(function () use ($ctx, $fx, $dims): void {
        $event = DB::transaction(fn () => app(\App\Modules\Accounting\Application\SubmitAccountingEvent::class)(
            $ctx['entity_id'], $fx['event_type'], 'fixture', (string) Str::uuid7(), 'kernel-golden-check',
            \Carbon\CarbonImmutable::parse('2026-09-15'), \Carbon\CarbonImmutable::parse('2026-09-15'), 'KES', $fx['payload'], $dims));
        $journals = app(PostingEngine::class)->post($event->id);
        expect($journals)->toHaveCount(1);
    });
});
