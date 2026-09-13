<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\Close\PeriodCloseService;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * UX brief §6 key screens (slice U7), the server side each needs: allocation workbench (one commit for many installments), bank matching with
 * suggested matches and confidence, month-end close with the lock's readiness, trial balance with period comparison, and journal
 * dimensions shown as readable labels.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->admin = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $this->world['admin']));
    [$this->policyId, $this->installments, $this->receiptId, $this->itemId] = asTenant($this->ctx['tenant_id'], function (): array {
        $lifecycle = app(PolicyLifecycle::class);
        $policy = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            $this->world['agent_id'], CarbonImmutable::parse('2026-08-01'), 12_000_000, 'BDT', 4), $this->world['admin']);
        $lifecycle->issue($policy->id, CarbonImmutable::parse('2026-08-01'), $this->world['admin']);
        $receipt = app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['policyholder_id'], 'bank_transfer',
            7_000_000, 'BDT', CarbonImmutable::parse('2026-09-02'), null, 'TT 4471', []), $this->world['admin']);

        return [$policy->id, DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            $receipt->id, (string) DB::table('suspense_items')->where('receipt_id', $receipt->id)->value('id')];
    });
});

it('opens the allocation workbench with the receipt, its open amount and candidate installments', function (): void {
    actingAs($this->admin)->get("/receipts/{$this->receiptId}/allocate", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('receipts/Allocate')->where('receipt.number', fn (string $n): bool => str_starts_with($n, 'RCT-'))->where('receipt.open', '70,000.00')
        ->where('suspenseItemId', $this->itemId)->has('candidates', 4)->where('candidates.0.outstanding', '30,000.00')->where('candidates.0.payer_matches', true));
});

it('allocates several installments in one commit, or none when one fails', function (): void {
    actingAs($this->admin)->post("/suspense/{$this->itemId}/allocations", ['on' => '2026-09-03', 'lines' => [
        ['installment_id' => $this->installments[0], 'amount' => '30,000.00'], ['installment_id' => $this->installments[1], 'amount' => '50,000.00'],
    ]], $this->headers)->assertSessionHasErrors('form');
    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('receipt_allocations')->count()))->toBe(0);

    actingAs($this->admin)->post("/suspense/{$this->itemId}/allocations", ['on' => '2026-09-03', 'lines' => [
        ['installment_id' => $this->installments[0], 'amount' => '30,000.00'], ['installment_id' => $this->installments[1], 'amount' => '30,000.00'],
    ]], $this->headers)->assertSessionHasNoErrors()->assertSessionHas('status', 'Allocated 60,000.00 to 2 installments; 10,000.00 is still unallocated.');
    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('receipt_allocations')->count()))->toBe(2);
});

it('suggests bank matches with a confidence', function (): void {
    $bankAccountId = asTenant($this->ctx['tenant_id'], function (): string {
        DB::table('bank_accounts')->insert(['id' => $id = (string) Illuminate\Support\Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
            'gl_account_id' => $this->ctx['accounts']['bank_main'], 'bank_name' => 'City Bank', 'account_no_masked' => '****4471', 'currency' => 'BDT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    });
    $csv = UploadedFile::fake()->createWithContent('s.csv', "date,description,reference,amount\n2026-09-03,Transfer,TT 4471,70000.00\n2026-09-04,Deposit,,70000.00\n2026-09-20,Charges,,-10.00\n");
    actingAs($this->admin)->post("/bank/{$bankAccountId}/statements", ['file' => $csv], $this->headers)->assertSessionHasNoErrors();

    actingAs($this->admin)->get("/bank/{$bankAccountId}?as_of=2026-09-30", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('bank/Show')
        ->has('suggestions', 2)->where('suggestions.0.confidence', 100)->where('suggestions.0.why', 'Same amount, 1 day apart, reference TT 4471')
        ->where('suggestions.1.confidence', 60)->where('suggestions.1.why', 'Same amount, 2 days apart'));
});

it('says why the period cannot be locked until the close is clean', function (): void {
    $september = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('fiscal_periods')->where('starts', '2026-09-01')->value('id'));
    $runId = asTenant($this->ctx['tenant_id'], fn (): string => app(PeriodCloseService::class)->start($september, $this->world['admin']));

    actingAs($this->admin)->get("/close/runs/{$runId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('close/Run')
        ->where('lock.ready', false)->where('lock.reason', 'Finish or skip 10 open tasks before locking.')
        ->where('tasks.7.code', 'trial_balance')->where('tasks.7.blocked_by', fn ($names): bool => in_array('Premium earning', (array) json_decode((string) json_encode($names), true), true)));
});

it('compares the trial balance with the previous month end', function (): void {
    actingAs($this->admin)->get('/accounting/trial-balance?as_of=2026-09-30', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('compare.asOf', '2026-08-31')->where('compare.balances', fn ($balances): bool => count((array) json_decode((string) json_encode($balances), true)) > 0));
});

it('labels journal dimensions for people', function (): void {
    $journalId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('journals')->where('source_type', 'policy_transaction')->value('id'));

    actingAs($this->admin)->get("/accounting/journals/{$journalId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('dimensions.1', fn ($dims): bool => in_array(['name' => 'Branch', 'value' => 'HO'], (array) json_decode((string) json_encode($dims), true), true))
        ->where('sourceLink', "/policies/{$this->policyId}"));
});
