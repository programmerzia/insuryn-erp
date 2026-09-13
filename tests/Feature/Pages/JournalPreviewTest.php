<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * UX brief §1.6 and §4 (slice U5): before money moves, the form shows the journal it will post. A money-moving form submitted with the
 * `X-Journal-Preview` header runs the real action and the real posting engine inside a transaction, answers with the journal lines, and
 * rolls everything back — no receipt, event, journal, number or flash message survives.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->admin = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $this->world['admin']));
    $this->preview = [...$this->headers, 'X-Journal-Preview' => '1', 'Accept' => 'application/json'];
    $this->policyId = asTenant($this->ctx['tenant_id'], fn (): string => app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'],
        $this->world['product_id'], $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT'), $this->world['admin'])->id);
    $this->counts = fn (): array => asTenant($this->ctx['tenant_id'], fn (): array => [
        'events' => DB::table('accounting_events')->count(), 'journals' => DB::table('journals')->count(), 'receipts' => DB::table('receipts')->count(),
        'numbers' => (int) DB::table('number_sequences')->sum('next_no'), 'policy_status' => DB::table('policies')->where('id', $this->policyId)->value('status'),
    ]);
});

it('previews the journal a policy issue posts, and changes nothing', function (): void {
    $before = ($this->counts)();

    actingAs($this->admin)->postJson("/policies/{$this->policyId}/issue", ['on' => '2026-09-01'], $this->preview)->assertOk()
        ->assertJsonPath('journals.0.event', 'POLICY_ISSUED')
        ->assertJsonPath('journals.0.lines.0', ['account' => '1100', 'name' => 'Premium Receivable', 'debit' => '120,000.00', 'credit' => null])
        ->assertJsonPath('journals.0.totals', ['debit' => '120,000.00', 'credit' => '120,000.00'])
        ->assertJsonPath('failures', []);

    expect(($this->counts)())->toBe($before);
    actingAs($this->admin)->get("/policies/{$this->policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('status', null));
});

it('previews a receipt split between an installment and suspense', function (): void {
    asTenant($this->ctx['tenant_id'], fn () => app(PolicyLifecycle::class)->issue($this->policyId, CarbonImmutable::parse('2026-09-01'), $this->world['admin']));
    $installment = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('installments')->value('id'));
    $before = ($this->counts)();

    $response = actingAs($this->admin)->postJson('/receipts', ['branch_id' => $this->ctx['branch_id'], 'channel' => 'bank_transfer', 'amount' => '130,000.00', 'value_date' => '2026-09-10',
        'allocations' => [['installment_id' => $installment, 'amount' => '120,000.00']]], $this->preview)->assertOk();

    $events = array_column((array) $response->json('journals'), 'event');
    sort($events);
    expect($events)->toBe(['PREMIUM_RECEIVED', 'RECEIPT_RECORDED']);
    expect(($this->counts)())->toBe($before);
});

it('answers a refused action with the reason, and leaves no flash behind', function (): void {
    actingAs($this->admin)->postJson("/policies/{$this->policyId}/cancel", ['cancel_date' => '2026-10-01', 'reason' => 'x'], $this->preview)
        ->assertUnprocessable()->assertJsonPath('errors.form', fn (string $message): bool => $message !== '')->assertJsonPath('journals', []);
    actingAs($this->admin)->postJson('/receipts', ['channel' => 'bank_transfer'], $this->preview)->assertUnprocessable()->assertJsonValidationErrors(['amount', 'value_date']);

    actingAs($this->admin)->get("/policies/{$this->policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('status', null)->where('errors', []));
});

it('only previews routes that move money, and still posts normally without the header', function (): void {
    actingAs($this->admin)->postJson('/parties', ['kind' => 'individual', 'display_name' => 'Nobody', 'roles' => ['customer']], $this->preview)
        ->assertStatus(400)->assertJsonPath('message', 'This action does not post a journal.');

    actingAs($this->admin)->post("/policies/{$this->policyId}/issue", ['on' => '2026-09-01'], $this->headers)->assertRedirect();
    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('journals')->count()))->toBe(1);
});
