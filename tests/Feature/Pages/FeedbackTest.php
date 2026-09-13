<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * UX brief §4 feedback (slice U9): a reversible action offers undo (a bank match can be undone while its month is open), and refusals say what
 * happened and what to do, in amounts people read — never ids or minor units ("Amount exceeds installment balance by 1,200.00").
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->admin = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $this->world['admin']));
});

it('undoes a bank match while the month is open, and says why not once it is locked', function (): void {
    $bankAccountId = asTenant($this->ctx['tenant_id'], function (): string {
        DB::table('bank_accounts')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
            'gl_account_id' => $this->ctx['accounts']['bank_main'], 'bank_name' => 'City Bank', 'account_no_masked' => '****1', 'currency' => 'BDT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 1_000_000, 'BDT',
            CarbonImmutable::parse('2026-09-02'), $id, 'TT 9', []), $this->world['admin']);

        return $id;
    });
    actingAs($this->admin)->post("/bank/{$bankAccountId}/statements", ['file' => UploadedFile::fake()->createWithContent('s.csv', "date,description,reference,amount\n2026-09-03,Transfer,TT 9,10000.00\n")], $this->headers);
    [$lineId, $journalLineId] = asTenant($this->ctx['tenant_id'], fn (): array => [(string) DB::table('bank_statement_lines')->value('id'),
        (string) DB::table('journal_lines')->where('account_id', $this->ctx['accounts']['bank_main'])->value('id')]);

    actingAs($this->admin)->post("/bank/lines/{$lineId}/match", ['journal_line_ids' => [$journalLineId]], $this->headers)
        ->assertSessionHas('status', 'Statement line matched.')->assertSessionHas('undo', ['label' => 'Undo', 'url' => "/bank/lines/{$lineId}/unmatch"]);

    actingAs($this->admin)->post("/bank/lines/{$lineId}/unmatch", [], $this->headers)->assertSessionHasNoErrors()->assertSessionHas('status', 'Match undone.');
    expect(asTenant($this->ctx['tenant_id'], fn () => [DB::table('bank_statement_lines')->value('match_status'), DB::table('bank_matches')->count()]))->toBe(['unmatched', 0]);
    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('audit_events')->where('action', 'bank_line.unmatched')->count()))->toBe(1);

    actingAs($this->admin)->post("/bank/lines/{$lineId}/match", ['journal_line_ids' => [$journalLineId]], $this->headers);
    asTenant($this->ctx['tenant_id'], fn () => DB::table('fiscal_periods')->where('starts', '2026-09-01')->update(['status' => 'locked']));
    actingAs($this->admin)->post("/bank/lines/{$lineId}/unmatch", [], $this->headers)
        ->assertSessionHasErrors(['form' => 'September 2026 is locked, so its bank matches cannot change. Ask for the period to be reopened first.']);
});

it('explains refusals with the amounts people see', function (): void {
    [$installmentId, $receiptId] = asTenant($this->ctx['tenant_id'], function (): array {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            null, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 2), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);
        $receipt = app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 2_000_000, 'BDT',
            CarbonImmutable::parse('2026-09-02'), null, 'TT', []), $this->world['admin']);

        return [(string) DB::table('installments')->orderBy('no')->value('id'), $receipt->id];
    });
    $itemId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('suspense_items')->where('receipt_id', $receiptId)->value('id'));

    actingAs($this->admin)->post('/receipts', ['branch_id' => $this->ctx['branch_id'], 'channel' => 'bank_transfer', 'amount' => '61,200.00', 'value_date' => '2026-09-05',
        'allocations' => [['installment_id' => $installmentId, 'amount' => '61,200.00']]], $this->headers)
        ->assertSessionHasErrors(['form' => 'The amount exceeds the installment balance of 60,000.00 by 1,200.00.']);

    actingAs($this->admin)->post("/suspense/{$itemId}/allocate", ['installment_id' => $installmentId, 'amount' => '25,000.00', 'on' => '2026-09-05'], $this->headers)
        ->assertSessionHasErrors(['form' => 'The amount exceeds what is left in suspense (20,000.00) by 5,000.00.']);

    actingAs($this->admin)->postJson('/api/insurance/receipts', ['entity_id' => $this->ctx['entity_id'], 'branch_id' => $this->ctx['branch_id'], 'channel' => 'bank_transfer',
        'amount_minor' => 6_120_000, 'currency' => 'BDT', 'value_date' => '2026-09-05', 'allocations' => [['installment_id' => $installmentId, 'amount_minor' => 6_120_000]]], $this->headers)
        ->assertStatus(422)->assertJsonPath('reason', 'ALLOCATION_EXCEEDS_OUTSTANDING')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, '6000000 outstanding')); // the API keeps its machine-oriented message
});
