<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Platform\Administration\IntegrationAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->viewer = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['accounting.view_journals'])));
    $this->admin = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['platform.manage_users', 'accounting.view_journals'])));
});

it('opens the ledger API demo for finance readers', function (): void {
    actingAs($this->viewer)->get('/accounting/ledger-api', $this->headers)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('accounting/LedgerApiDemo')
            ->has('sample.event_type')->has('endpoints', 12)->has('implementationSteps', 6)
            ->where('integrationEmail', 'integration@nonlife.local')->where('can.manageIntegration', false));
});

it('previews lines and posts through the same payload shape as the HTTP API', function (): void {
    $body = json_encode([
        'event_type' => 'PREMIUM_RECEIVED',
        'idempotency_key' => 'PREMIUM_RECEIVED:DEMO-PAGE',
        'transaction_date' => '2026-09-15',
        'currency' => 'KES',
        'payload' => ['amount' => 5_000_000],
        'dimensions' => ['branch' => $this->ctx['branch_id'], 'product' => (string) Str::uuid7(), 'product_code' => 'MOTOR', 'lob' => 'motor', 'channel' => 'agent', 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7(), 'agent' => (string) Str::uuid7(), 'claim' => (string) Str::uuid7()],
        'source' => ['type' => 'receipt', 'id' => 'RCT-DEMO-PAGE', 'number' => 'RCT-DEMO'],
    ], JSON_THROW_ON_ERROR);

    actingAs($this->viewer)->post('/accounting/ledger-api/preview', ['body' => $body], $this->headers)
        ->assertRedirect('/accounting/ledger-api')
        ->assertSessionHas('ledger_demo_result.kind', 'preview');

    actingAs($this->viewer)->post('/accounting/ledger-api/post', ['body' => $body], $this->headers)
        ->assertRedirect('/accounting/ledger-api')
        ->assertSessionHas('ledger_demo_result.kind', 'posted');

    expect(asTenant($this->ctx['tenant_id'], fn (): int => DB::table('external_event_intakes')->where('idempotency_key', 'PREMIUM_RECEIVED:DEMO-PAGE')->count()))->toBe(1);
});

it('lets tenant admins create integration users from the demo page', function (): void {
    actingAs($this->admin)->post('/accounting/ledger-api/integration-users', [
        'name' => 'Customer API',
        'email' => 'ledger-demo@example.test',
        'password' => 'Ledger-pass-12',
    ], $this->headers)->assertRedirect('/accounting/ledger-api')->assertSessionHas('status');

    expect(asTenant($this->ctx['tenant_id'], fn (): ?string => DB::table('users')->where('email', 'ledger-demo@example.test')->value('kind')))->toBe('integration');
});
