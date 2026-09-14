<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Flow fix X5 (Part A step 3): a recorded receipt offers "Print receipt"; the receipt page prints it from its header and offers the download, and offers
 * "Allocate" while part of it waits in suspense — each only to people who may do it.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('documents');
    fakePdfRenderer();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    seedDocumentTemplates($this->ctx['tenant_id']);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->user = fn (array $permissions): User => ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    $this->admin = ($this->in)(fn (): User => User::query()->findOrFail($this->world['admin']));
    $this->installment = ($this->in)(function (): string {
        $lifecycle = app(PolicyLifecycle::class);
        $policy = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        $lifecycle->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return (string) DB::table('installments')->where('policy_id', $policy->id)->value('id');
    });
    $this->record = fn (User $user, string $amount, string $allocated) => actingAs($user)->post('/receipts', ['branch_id' => $this->ctx['branch_id'], 'channel' => 'bank_transfer',
        'amount' => $amount, 'value_date' => '2026-09-05', 'reference' => 'TT 1', 'allocations' => [['installment_id' => $this->installment, 'amount' => $allocated]]], $this->headers);
});

it('offers to print the receipt right after recording it, to someone who may print it', function (): void {
    $response = ($this->record)($this->admin, '120,000.00', '120,000.00');
    $receipt = ($this->in)(fn () => DB::table('receipts')->sole());
    $response->assertSessionHasNoErrors()->assertRedirect("/receipts/{$receipt->id}")->assertSessionHas('status', "Receipt {$receipt->number} recorded.")
        ->assertSessionHas('next', ['label' => 'Print receipt', 'url' => "/receipts/{$receipt->id}/generated-documents", 'method' => 'post']);

    actingAs(($this->user)(['receipt.create']))->post('/receipts', ['branch_id' => $this->ctx['branch_id'], 'channel' => 'cash', 'amount' => '1,000.00', 'value_date' => '2026-09-05'], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionMissing('next');
});

it('prints from the receipt page header and offers the download of what was printed', function (): void {
    ($this->record)($this->admin, '120,000.00', '120,000.00');
    $receiptId = ($this->in)(fn (): string => (string) DB::table('receipts')->value('id'));
    actingAs($this->admin)->get("/receipts/{$receiptId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Show')
        ->where('actions.print', true)->where('actions.allocate', false));
    actingAs(($this->user)(['receipt.create']))->get("/receipts/{$receiptId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('actions.print', false));

    $response = actingAs($this->admin)->post("/receipts/{$receiptId}/generated-documents", ['locale' => 'en'], $this->headers);
    $stored = ($this->in)(fn (): string => (string) DB::table('generated_documents')->value('stored_document_id'));
    $response->assertSessionHasNoErrors()->assertRedirect("/receipts/{$receiptId}?tab=documents")->assertSessionHas('status', 'Receipt version 1 generated.')
        ->assertSessionHas('next', ['label' => 'Download', 'url' => "/receipts/{$receiptId}/documents/{$stored}", 'method' => 'download']);
    actingAs($this->admin)->get("/receipts/{$receiptId}/documents/{$stored}", $this->headers)->assertOk();
});

it('offers Allocate while part of the receipt is in suspense, to someone who may allocate', function (): void {
    ($this->record)($this->admin, '130,000.00', '120,000.00');
    $receiptId = ($this->in)(fn (): string => (string) DB::table('receipts')->value('id'));
    actingAs($this->admin)->get("/receipts/{$receiptId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('suspense.open', '10,000.00')->where('actions.allocate', true));
    actingAs(($this->user)(['receipt.create']))->get("/receipts/{$receiptId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('actions.allocate', false));
    actingAs(($this->user)(['receipt.allocate']))->get("/receipts/{$receiptId}/allocate", $this->headers)->assertOk();
});
