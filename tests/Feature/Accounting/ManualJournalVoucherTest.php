<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\ManualJournals\ProvisionalReference;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * GA-31: the accountant's manual journal — accounts found by typing their code or name, the scanned voucher attached with the journal (and later on its page),
 * and a provisional reference MJ-… that the maker and the approver can quote while the journal waits (the JV number still comes only when it posts, A-197).
 */
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('documents');
    $this->ctx = seedDemoTenant();
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    $this->accountant = ($this->userWith)(['accounting.view_journals', 'accounting.create_manual_journal']);
    $this->approver = ($this->userWith)(['accounting.view_journals', 'accounting.approve_journal']);
    $this->auditor = ($this->userWith)(['accounting.view_journals', 'reports.financial']);
    $this->journal = fn (array $extra = []): array => ['transaction_date' => '2026-09-25', 'description' => 'Office rent for September', 'kind' => 'manual', 'reason' => 'Rent invoice 9/26',
        'lines' => [['account_id' => $this->ctx['accounts']['salary_expense'], 'side' => 'debit', 'amount' => '85,000.00'], ['account_id' => $this->ctx['accounts']['bank_main'], 'side' => 'credit', 'amount' => '85,000.00']], ...$extra];
});

it('finds postable accounts by code or name for the journal line, for people who make journals', function (): void {
    actingAs($this->accountant)->getJson('/lookup/account?q=bank', $this->headers)->assertOk()
        ->assertJson(fn ($json) => $json->where('results', fn (\Illuminate\Support\Collection $rows): bool => $rows->pluck('label')->contains('1010 Bank - Main') && ! $rows->pluck('label')->contains('5300 Salaries'))->etc());
    actingAs($this->accountant)->getJson('/lookup/account?q=1100', $this->headers)->assertOk()
        ->assertJsonPath('results.0.label', '1100 Premium Receivable')->assertJsonPath('results.0.detail', 'Asset · control account (adjustments only)');
    actingAs($this->auditor)->getJson('/lookup/account?q=bank', $this->headers)->assertForbidden();
});

it('attaches the voucher with the journal, shows the provisional reference while it waits, and lets the approver read the voucher', function (): void {
    actingAs($this->accountant)->post('/accounting/journals', ($this->journal)(['voucher' => UploadedFile::fake()->createWithContent('rent-invoice.pdf', "%PDF-1.4\nRent\n")]), $this->headers)
        ->assertSessionHasNoErrors();
    $journal = asTenant($this->ctx['tenant_id'], fn () => DB::table('journals')->where('description', 'Office rent for September')->first(['id', 'number', 'status']));
    $reference = ProvisionalReference::for((string) $journal?->id);
    expect($journal?->number)->toBeNull()->and($reference)->toMatch('/^MJ-[0-9A-F]{6}$/');

    actingAs($this->approver)->get("/accounting/journals/{$journal?->id}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('journal.provisionalReference', $reference)->has('documents', 1)->where('documents.0.name', 'rent-invoice.pdf')->where('documents.0.description', 'Supporting voucher')
        ->where('documentUpload', null));
    actingAs($this->accountant)->get('/accounting/journals', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('journals.data', fn (\Illuminate\Support\Collection $rows): bool => $rows->firstWhere('id', $journal?->id)['provisionalReference'] === $reference));
    $url = (string) asTenant($this->ctx['tenant_id'], fn () => "/accounting/journals/{$journal?->id}/documents/".DB::table('stored_documents')->where('object_type', 'journal')->value('id'));
    actingAs($this->approver)->get($url, $this->headers)->assertOk();

    // More support can be attached on the journal page by whoever makes journals, not by the approver.
    actingAs($this->accountant)->get("/accounting/journals/{$journal?->id}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('documentUpload', "/accounting/journals/{$journal?->id}/documents"));
    $file = fn () => ['file' => UploadedFile::fake()->createWithContent('agreement.pdf', "%PDF-1.4\nLease\n")];
    actingAs($this->approver)->post("/accounting/journals/{$journal?->id}/documents", $file(), $this->headers)->assertSessionHasErrors('form');
    actingAs($this->accountant)->post("/accounting/journals/{$journal?->id}/documents", $file(), $this->headers)->assertSessionHasNoErrors();
    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('stored_documents')->where('object_type', 'journal')->where('object_id', $journal?->id)->count()))->toBe(2);

    // A voucher of a type that is not allowed is refused with the journal.
    actingAs($this->accountant)->post('/accounting/journals', ($this->journal)(['description' => 'Bad voucher', 'voucher' => UploadedFile::fake()->createWithContent('run.exe', 'MZ')]), $this->headers)
        ->assertSessionHasErrors('voucher');
    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('journals')->where('description', 'Bad voucher')->exists()))->toBeFalse();
});

it('keeps system postings free of attachments', function (): void {
    $journalId = asTenant($this->ctx['tenant_id'], function (): string {
        DB::table('journals')->insert(['id' => $id = (string) Illuminate\Support\Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'],
            'book_id' => DB::table('books')->value('id'), 'kind' => 'system', 'status' => 'draft', 'transaction_date' => '2026-09-25', 'posting_date' => '2026-09-25', 'effective_date' => '2026-09-25',
            'period_id' => DB::table('fiscal_periods')->where('starts', '2026-09-01')->value('id'), 'currency' => 'BDT', 'description' => 'POLICY_ISSUED', 'source_type' => 'policy',
            'source_id' => (string) Illuminate\Support\Str::uuid7(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    });
    actingAs($this->accountant)->post("/accounting/journals/{$journalId}/documents", ['file' => UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n")], $this->headers)->assertNotFound();
});
