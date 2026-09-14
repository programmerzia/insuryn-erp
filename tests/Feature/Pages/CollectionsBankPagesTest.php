<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Slice 1C.9 operations screens: receipts (with allocations, cheques and agent collections), suspense allocation, refunds (request and release by
 * different people), agent cash and deposits, dunning notices, and bank (accounts, statement import, auto and manual matching, explanations).
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->admin = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $this->world['admin']));
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    [$this->policyId, $this->installments] = asTenant($this->ctx['tenant_id'], function (): array {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 2), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return [$policy->id, DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->pluck('id')->map(fn ($id): string => (string) $id)->all()];
    });
});

it('opens collection and bank screens only to their areas', function (): void {
    $claims = ($this->userWith)(['claim.register']);
    $cashier = ($this->userWith)(['receipt.create']);
    $treasury = ($this->userWith)(['bank.match']);

    foreach (['/receipts', '/receipts/create', '/suspense', '/refunds', '/agent-cash', '/cheques', '/dunning'] as $page) {
        actingAs($claims)->get($page, $this->headers)->assertForbidden();
        actingAs($cashier)->get($page, $this->headers)->assertOk();
    }
    actingAs($cashier)->get('/bank', $this->headers)->assertForbidden();
    actingAs($treasury)->get('/bank', $this->headers)->assertOk();
});

it('records receipts with allocations, parks the rest in suspense, allocates it and bounces a cheque', function (): void {
    actingAs($this->admin)->get('/receipts/create', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Create')
        ->has('installments', 2)->where('installments.0.outstanding', '60,000.00')->has('channels', 5));

    actingAs($this->admin)->post('/receipts', ['branch_id' => $this->ctx['branch_id'], 'channel' => 'cheque', 'amount' => '70,000.00', 'value_date' => '2026-09-10',
        'reference' => 'CHQ 1', 'cheque_no' => '100200', 'cheque_bank' => 'Sonali Bank', 'cheque_date' => '2026-09-09',
        'allocations' => [['installment_id' => $this->installments[0], 'amount' => '60,000.00']]], $this->headers)->assertSessionHasNoErrors();
    $receiptId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('receipts')->value('id'));
    $itemId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('suspense_items')->value('id'));

    actingAs($this->admin)->get("/receipts/{$receiptId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Show')
        ->where('receipt.amount', '70,000.00')->where('receipt.status', 'partially_allocated')->has('allocations', 1)->where('suspense.open', '10,000.00')->where('actions.bounce', true));
    actingAs($this->admin)->get('/suspense?as_of=2026-09-30', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('suspense/Index')
        ->has('ageing.items', 1)->where('ageing.total', '10,000.00'));

    actingAs($this->admin)->post("/suspense/{$itemId}/allocate", ['installment_id' => $this->installments[1], 'amount' => '10,000.00', 'on' => '2026-09-12'], $this->headers)->assertSessionHasNoErrors();
    actingAs($this->admin)->post("/suspense/{$itemId}/allocate", ['installment_id' => $this->installments[1], 'amount' => '1.00', 'on' => '2026-09-12'], $this->headers)->assertSessionHasErrors('form');

    actingAs($this->admin)->post("/receipts/{$receiptId}/bounce", ['bounced_on' => '2026-09-15', 'reason' => 'Insufficient funds'], $this->headers)->assertSessionHasNoErrors();
    actingAs($this->admin)->get('/cheques?from=2026-09-01&to=2026-09-30', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Cheques')
        ->has('register.rows', 1)->where('register.rows.0.state', 'bounced'));
    actingAs($this->admin)->get('/receipts', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Index')->where('receipts.data.0.status', 'bounced'));
});

it('requests a refund and releases it only by someone else', function (): void {
    $requester = ($this->userWith)(['receipt.refund_request', 'receipt.refund_release']);
    $releaser = ($this->userWith)(['receipt.refund_release']);
    actingAs($this->admin)->post('/receipts', ['branch_id' => $this->ctx['branch_id'], 'channel' => 'bank_transfer', 'amount' => '120,000.00', 'value_date' => '2026-09-05',
        'allocations' => [['installment_id' => $this->installments[0], 'amount' => '60,000.00'], ['installment_id' => $this->installments[1], 'amount' => '60,000.00']]], $this->headers);
    asTenant($this->ctx['tenant_id'], fn () => app(PolicyLifecycle::class)->cancel($this->policyId, CarbonImmutable::parse('2026-12-01'), 'sold', $this->world['admin']));

    actingAs($requester)->get('/refunds', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('refunds/Index')->where('refundableCount', 1)->missing('refundable'));
    actingAs($requester)->getJson('/lookup/refundable?q=', $this->headers)->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.id', $this->policyId); // GA-40
    actingAs($requester)->post('/refunds', ['policy_id' => $this->policyId, 'amount' => '1,000.00', 'reason' => 'cancellation'], $this->headers)->assertSessionHasNoErrors();
    $refundId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('refunds')->value('id'));

    actingAs($requester)->post("/refunds/{$refundId}/release", ['paid_on' => '2026-12-05'], $this->headers)->assertSessionHasErrors('form');
    actingAs($releaser)->post("/refunds/{$refundId}/release", ['paid_on' => '2026-12-05'], $this->headers)->assertSessionHasNoErrors();
    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('refunds')->value('status')))->toBe('released');
});

it('shows agent cash and records a deposit, and lists dunning notices', function (): void {
    actingAs($this->admin)->post('/receipts', ['branch_id' => $this->ctx['branch_id'], 'channel' => 'cash', 'amount' => '60,000.00', 'value_date' => '2026-09-05',
        'collected_by_agent_id' => $this->world['agent_id'], 'allocations' => [['installment_id' => $this->installments[0], 'amount' => '60,000.00']]], $this->headers)->assertSessionHasNoErrors();
    actingAs($this->admin)->post('/agent-cash/deposits', ['agent_id' => $this->world['agent_id'], 'amount' => '50,000.00', 'deposited_on' => '2026-09-06', 'reference' => 'slip 7'], $this->headers)->assertSessionHasNoErrors();
    actingAs($this->admin)->get('/agent-cash?as_of=2026-09-30', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('agentCash/Index')
        ->where('position.rows.0.undeposited', '10,000.00')->where('position.rows.0.difference', '0.00'));

    asTenant($this->ctx['tenant_id'], fn () => app(App\Modules\Insurance\Policy\Application\Dunning\DunningRun::class)->run($this->ctx['entity_id'], CarbonImmutable::parse('2026-10-09')));
    actingAs($this->admin)->get('/dunning?from=2026-10-01&to=2026-10-31', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Dunning')->has('notices', 1));
});

it('manages bank accounts, imports a statement, matches automatically and by hand, and explains a charge', function (): void {
    $gl = asTenant($this->ctx['tenant_id'], function (): string {
        $id = (string) Str::uuid7();
        DB::table('accounts')->insert(['id' => $id, 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => '1011', 'name' => 'Bank - City',
            'type' => 'asset', 'normal_side' => 'debit', 'is_postable' => true, 'is_control' => false, 'status' => 'active']);

        return $id;
    });
    actingAs($this->admin)->post('/bank', ['gl_account_id' => $gl, 'bank_name' => 'City Bank', 'account_no_masked' => '****4471', 'currency' => 'BDT'], $this->headers)->assertSessionHasNoErrors();
    $bankAccountId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('bank_accounts')->value('id'));
    foreach ([['TRX-1', '30,000.00', 0], ['TRX-2', '20,000.00', 1]] as [$ref, $amount, $installment]) {
        actingAs($this->admin)->post('/receipts', ['branch_id' => $this->ctx['branch_id'], 'channel' => 'bank_transfer', 'amount' => $amount, 'value_date' => '2026-09-10', 'reference' => $ref,
            'bank_account_id' => $bankAccountId, 'allocations' => [['installment_id' => $this->installments[$installment], 'amount' => $amount]]], $this->headers)->assertSessionHasNoErrors();
    }

    $csv = UploadedFile::fake()->createWithContent('sept.csv', "date,description,reference,amount\n2026-09-11,Transfer,TRX-1,30000.00\n2026-09-12,Deposit,unknown,20000.00\n2026-09-30,Charges,,-150.00\n");
    actingAs($this->admin)->post("/bank/{$bankAccountId}/statements", ['file' => $csv], $this->headers)->assertSessionHasNoErrors()->assertSessionHas('status');
    actingAs($this->admin)->post("/bank/{$bankAccountId}/auto-match", [], $this->headers)->assertSessionHas('status', '1 statement line matched automatically.');

    $page = actingAs($this->admin)->get("/bank/{$bankAccountId}?as_of=2026-09-30", $this->headers);
    $page->assertInertia(fn (AssertableInertia $p) => $p->component('bank/Show')->has('unmatched.statement_lines', 2)->has('unmatched.journal_lines', 1));
    $lines = asTenant($this->ctx['tenant_id'], fn (): array => [
        (string) DB::table('bank_statement_lines')->where('amount_minor', 2_000_000)->value('id'),
        (string) DB::table('bank_statement_lines')->where('amount_minor', -15_000)->value('id'),
        (string) DB::table('journal_lines')->where('account_id', $gl)->whereNotIn('id', DB::table('bank_matches')->select('journal_line_id'))->value('id'),
    ]);

    actingAs($this->admin)->post("/bank/lines/{$lines[0]}/match", ['journal_line_ids' => [$lines[2]]], $this->headers)->assertSessionHasNoErrors();
    actingAs($this->admin)->post("/bank/lines/{$lines[1]}/explain", ['reason' => 'Monthly fee'], $this->headers)->assertSessionHasNoErrors();
    actingAs($this->admin)->get("/bank/{$bankAccountId}?as_of=2026-09-30", $this->headers)->assertInertia(fn (AssertableInertia $p) => $p->has('unmatched.statement_lines', 0)->has('unmatched.journal_lines', 0));
});
