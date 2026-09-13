<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Documents\DocumentContents;
use App\Modules\Platform\Documents\DocumentStore;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Fix F2 (market cross-check Part A step 5, G2): documents attached to claims, receipts and policies — stored once on the private documents disk under
 * their SHA-256, listed on the object page's Documents tab, downloaded with their original name, and every attach and download audit-logged.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('documents');
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->admin = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $this->world['admin']));
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    [$this->policyId, $this->claimId, $this->receiptId] = asTenant($this->ctx['tenant_id'], function (): array {
        $admin = $this->world['admin'];
        DB::table('users')->where('id', $admin)->update(['name' => 'Rafiq Islam']);
        $lifecycle = app(PolicyLifecycle::class);
        $policy = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            null, CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT'), $admin);
        $lifecycle->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $admin);
        $receipt = app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 4_000_000, 'BDT',
            CarbonImmutable::parse('2026-09-05'), null, 'TT 1', [new AllocationLine((string) DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->value('id'), 4_000_000)]), $admin);
        $claim = app(ClaimService::class)->register($policy->id, CarbonImmutable::parse('2026-09-05'), 'Rear collision', $admin, CarbonImmutable::parse('2026-09-06'));

        return [$policy->id, $claim->id, $receipt->id];
    });
    $this->pdf = '%PDF-1.4 survey report for the rear collision';
    $this->attach = fn (User $user, string $base, string $name, string $contents, array $extra = []) => actingAs($user)
        ->post("{$base}/documents", ['file' => UploadedFile::fake()->createWithContent($name, $contents), ...$extra], $this->headers);
    $this->rows = fn (): array => asTenant($this->ctx['tenant_id'], fn (): array => DB::table('stored_documents')->orderBy('uploaded_at')->get()->map(fn (object $r): array => (array) $r)->all());
});

it('attaches a document to a claim: one row, the file under its hash, an audit row and a timeline sentence', function (): void {
    ($this->attach)($this->admin, "/claims/{$this->claimId}", 'survey-report.pdf', $this->pdf, ['description' => 'Surveyor visit 6 Sep'])
        ->assertSessionHasNoErrors()->assertRedirect("/claims/{$this->claimId}?tab=documents");

    $rows = ($this->rows)();
    expect($rows)->toHaveCount(1);
    $row = $rows[0];
    $hash = hash('sha256', $this->pdf);
    expect($row['object_type'])->toBe('claim')->and($row['object_id'])->toBe($this->claimId)
        ->and($row['original_name'])->toBe('survey-report.pdf')->and((int) $row['size_bytes'])->toBe(strlen($this->pdf))
        ->and($row['sha256'])->toBe($hash)->and($row['uploaded_by'])->toBe($this->world['admin'])->and($row['description'])->toBe('Surveyor visit 6 Sep')
        ->and($row['storage_path'])->toContain($hash);
    expect(Storage::disk('documents')->get($row['storage_path']))->toBe($this->pdf)
        ->and(hash('sha256', (string) Storage::disk('documents')->get($row['storage_path'])))->toBe($row['sha256']);

    $audit = asTenant($this->ctx['tenant_id'], fn (): object => DB::table('audit_events')->where('action', 'document.attached')->sole());
    expect($audit->object_type)->toBe('claim')->and($audit->object_id)->toBe($this->claimId)->and($audit->actor_user_id)->toBe($this->world['admin'])
        ->and(json_decode((string) $audit->after, true))->toMatchArray(['document_id' => $row['id'], 'name' => 'survey-report.pdf', 'sha256' => $hash]);

    actingAs($this->admin)->get("/claims/{$this->claimId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('timeline.0.sentence', 'Document survey-report.pdf attached by Rafiq Islam'));
});

it('lists a claim\'s documents on its page and downloads one with its original name', function (): void {
    ($this->attach)($this->admin, "/claims/{$this->claimId}", 'survey-report.pdf', $this->pdf)->assertSessionHasNoErrors();
    $id = ($this->rows)()[0]['id'];

    actingAs($this->admin)->get("/claims/{$this->claimId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('documentUpload', "/claims/{$this->claimId}/documents")
        ->missing('documents')
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload
            ->has('documents', 1)
            ->where('documents.0.id', $id)->where('documents.0.name', 'survey-report.pdf')->where('documents.0.size_bytes', strlen($this->pdf))
            ->where('documents.0.uploaded_by', 'Rafiq Islam')->where('documents.0.url', "/claims/{$this->claimId}/documents/{$id}")
            ->where('documents.0.uploaded_at', fn ($at): bool => is_string($at) && $at !== '')));

    $response = actingAs($this->admin)->get("/claims/{$this->claimId}/documents/{$id}", $this->headers)->assertOk();
    expect($response->streamedContent())->toBe($this->pdf)
        ->and((string) $response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('survey-report.pdf');

    $download = asTenant($this->ctx['tenant_id'], fn (): object => DB::table('audit_events')->where('action', 'document.downloaded')->sole()); // exactly one
    expect($download->object_id)->toBe($this->claimId)
        ->and(json_decode((string) $download->after, true))->toMatchArray(['document_id' => $id, 'name' => 'survey-report.pdf']);
});

it('refuses to attach without a write permission and to list or download without the claim area', function (): void {
    $reader = ($this->userWith)(['reports.financial']);
    ($this->attach)($reader, "/claims/{$this->claimId}", 'survey-report.pdf', $this->pdf)->assertSessionHasErrors(['reason' => 'PERMISSION_DENIED']);
    expect(($this->rows)())->toBe([]);

    actingAs($reader)->get("/claims/{$this->claimId}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('documentUpload', null));

    ($this->attach)($this->admin, "/claims/{$this->claimId}", 'survey-report.pdf', $this->pdf)->assertSessionHasNoErrors();
    $id = ($this->rows)()[0]['id'];
    $cashier = ($this->userWith)(['receipt.create']);
    actingAs($cashier)->get("/claims/{$this->claimId}/documents/{$id}", $this->headers)->assertForbidden();
    actingAs($reader)->get("/claims/{$this->claimId}/documents/{$id}", $this->headers)->assertOk();

    $officer = ($this->userWith)(['claim.register']);
    ($this->attach)($officer, "/claims/{$this->claimId}", 'photo.jpg', 'jpeg bytes')->assertSessionHasNoErrors();
    expect(($this->rows)())->toHaveCount(2);
});

it('validates the upload: allowed types and the size limit', function (): void {
    config(['erp.documents.max_upload_kb' => 1]);

    ($this->attach)($this->admin, "/claims/{$this->claimId}", 'too-big.pdf', str_repeat('x', 2048))->assertSessionHasErrors('file');
    ($this->attach)($this->admin, "/claims/{$this->claimId}", 'script.exe', 'MZ')->assertSessionHasErrors('file');
    actingAs($this->admin)->post("/claims/{$this->claimId}/documents", ['description' => 'no file'], $this->headers)->assertSessionHasErrors('file');
    expect(($this->rows)())->toBe([])->and(Storage::disk('documents')->allFiles())->toBe([]);

    // The service refuses on its own too, for callers that are not a validated form.
    $store = app(DocumentStore::class);
    $type = thrownBy(fn () => asTenant($this->ctx['tenant_id'], fn () => $store->attach('claim', $this->claimId, new DocumentContents('notes.html', '<p>x</p>'), $this->world['admin'])), BusinessRuleViolation::class);
    $size = thrownBy(fn () => asTenant($this->ctx['tenant_id'], fn () => $store->attach('claim', $this->claimId, new DocumentContents('big.pdf', str_repeat('x', 2048)), $this->world['admin'])), BusinessRuleViolation::class);
    $empty = thrownBy(fn () => asTenant($this->ctx['tenant_id'], fn () => $store->attach('claim', $this->claimId, new DocumentContents('empty.pdf', ''), $this->world['admin'])), BusinessRuleViolation::class);
    expect($type->reasonCode)->toBe('DOCUMENT_TYPE_NOT_ALLOWED')->and($size->reasonCode)->toBe('DOCUMENT_TOO_LARGE')->and($empty->reasonCode)->toBe('DOCUMENT_EMPTY')
        ->and(($this->rows)())->toBe([]);
});

it('keeps stored documents append-only in the database', function (): void {
    ($this->attach)($this->admin, "/claims/{$this->claimId}", 'survey-report.pdf', $this->pdf)->assertSessionHasNoErrors();
    $id = ($this->rows)()[0]['id'];

    $update = thrownBy(fn () => asTenant($this->ctx['tenant_id'], fn () => DB::table('stored_documents')->where('id', $id)->update(['original_name' => 'other.pdf'])), QueryException::class);
    $delete = thrownBy(fn () => asTenant($this->ctx['tenant_id'], fn () => DB::table('stored_documents')->where('id', $id)->delete()), QueryException::class);
    expect($update->getMessage())->toContain('DOCUMENT_APPEND_ONLY')->and($delete->getMessage())->toContain('DOCUMENT_APPEND_ONLY')
        ->and(($this->rows)()[0]['original_name'])->toBe('survey-report.pdf');

    $pathWithoutHash = thrownBy(fn () => asTenant($this->ctx['tenant_id'], fn () => DB::table('stored_documents')->insert(['id' => (string) Illuminate\Support\Str::uuid7(),
        'tenant_id' => $this->ctx['tenant_id'], 'object_type' => 'claim', 'object_id' => $this->claimId, 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1,
        'sha256' => hash('sha256', 'x'), 'disk' => 'documents', 'storage_path' => 'documents/x.pdf', 'uploaded_at' => now()])), QueryException::class);
    expect($pathWithoutHash->getMessage())->toContain('stored_documents_path_has_hash');
});

it('never shows or serves one tenant\'s documents to another', function (): void {
    ($this->attach)($this->admin, "/claims/{$this->claimId}", 'survey-report.pdf', $this->pdf)->assertSessionHasNoErrors();
    $id = ($this->rows)()[0]['id'];

    $other = seedDemoTenant('other');
    $otherWorld = seedInsuranceWorld($other, 'monthly');
    $otherAdmin = asTenant($other['tenant_id'], fn (): User => User::query()->findOrFail((string) $otherWorld['admin']));
    $otherHeaders = ['X-Tenant' => $other['tenant_id']];

    expect(asTenant($other['tenant_id'], fn (): array => app(DocumentStore::class)->list('claim', $this->claimId)))->toBe([])
        ->and(asTenant($other['tenant_id'], fn () => app(DocumentStore::class)->find($id, 'claim', $this->claimId)))->toBeNull();
    actingAs($otherAdmin)->get("/claims/{$this->claimId}/documents/{$id}", $otherHeaders)->assertNotFound();

    // Inside the tenant, a document is served only through the object it is attached to.
    actingAs($this->admin)->get("/receipts/{$this->receiptId}/documents/{$id}", $this->headers)->assertNotFound();
    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('audit_events')->where('action', 'document.downloaded')->count()))->toBe(0);
});

it('attaches, lists and downloads documents on receipts and policies', function (string $type, string $write): void {
    $id = $type === 'receipt' ? $this->receiptId : $this->policyId;
    $base = $type === 'receipt' ? "/receipts/{$id}" : "/policies/{$id}";
    $component = $type === 'receipt' ? 'receipts/Show' : 'policies/Show';
    $writer = ($this->userWith)([$write]);

    ($this->attach)($writer, $base, 'bank-slip.png', 'png bytes', ['description' => 'Deposit slip'])->assertSessionHasNoErrors()->assertRedirect("{$base}?tab=documents");
    $row = ($this->rows)()[0];
    expect($row['object_type'])->toBe($type)->and($row['object_id'])->toBe($id)->and($row['sha256'])->toBe(hash('sha256', 'png bytes'));

    actingAs($writer)->get($base, $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component($component)
        ->where('documentUpload', "{$base}/documents")
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload->where('documents.0.name', 'bank-slip.png')->where('documents.0.description', 'Deposit slip')
            ->where('documents.0.url', "{$base}/documents/{$row['id']}")));

    $response = actingAs($writer)->get("{$base}/documents/{$row['id']}", $this->headers)->assertOk();
    expect($response->streamedContent())->toBe('png bytes')->and((string) $response->headers->get('Content-Disposition'))->toContain('bank-slip.png');
    expect(asTenant($this->ctx['tenant_id'], fn () => DB::table('audit_events')->whereIn('action', ['document.attached', 'document.downloaded'])->where('object_id', $id)->count()))->toBe(2);

    $reader = ($this->userWith)(['reports.financial']);
    ($this->attach)($reader, $base, 'other.pdf', 'pdf')->assertSessionHasErrors(['reason' => 'PERMISSION_DENIED']);
    actingAs($reader)->get($base, $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('documentUpload', null));
})->with([
    'receipt by receipt.create' => ['receipt', 'receipt.create'],
    'receipt by receipt.allocate' => ['receipt', 'receipt.allocate'],
    'policy by policy.create' => ['policy', 'policy.create'],
    'policy by policy.endorse' => ['policy', 'policy.endorse'],
]);

it('stores a file once under its hash on the real disk and never overwrites it', function (): void {
    $root = storage_path('framework/testing/documents-real-'.Illuminate\Support\Str::random(8));
    config(['filesystems.disks.documents.root' => $root]);
    Storage::forgetDisk('documents');
    $store = app(DocumentStore::class);
    $actor = $this->world['admin'];

    try {
        [$first, $again, $other] = asTenant($this->ctx['tenant_id'], fn (): array => [
            $store->attach('claim', $this->claimId, new DocumentContents('survey-report.pdf', $this->pdf), $actor),
            $store->attach('policy', $this->policyId, new DocumentContents('copy-of-survey.pdf', $this->pdf), $actor),
            $store->attach('claim', $this->claimId, new DocumentContents('survey-report.pdf', $this->pdf.' revised'), $actor),
        ]);
        $file = $root.'/'.$first->storagePath;
        $writtenAt = filemtime($file);

        expect($first->storagePath)->toContain($first->sha256)->and($again->storagePath)->toBe($first->storagePath)
            ->and($other->storagePath)->not->toBe($first->storagePath)->and($other->storagePath)->toContain(hash('sha256', $this->pdf.' revised'))
            ->and(File::get($file))->toBe($this->pdf)->and(File::get($root.'/'.$other->storagePath))->toBe($this->pdf.' revised')
            ->and(File::allFiles($root))->toHaveCount(2)
            ->and(($this->rows)())->toHaveCount(3);

        // The same bytes attached again leave the stored file untouched.
        touch($file, $writtenAt - 100);
        clearstatcache();
        asTenant($this->ctx['tenant_id'], fn () => $store->attach('claim', $this->claimId, new DocumentContents('survey-report.pdf', $this->pdf), $actor));
        clearstatcache();
        expect(filemtime($file))->toBe($writtenAt - 100)->and(File::get($file))->toBe($this->pdf);
    } finally {
        File::deleteDirectory($root);
    }
});
