<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Documents\Generation\DocumentGenerator;
use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use App\Modules\Platform\Documents\Templates\DocumentTemplates;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Phase 3 slice R8 (design §3): documents generated for business objects — the policy schedule, an endorsement and a receipt — rendered from the
 * active template, stored immutably through the DocumentStore on the object's page with their hash, versioned (regenerating never overwrites),
 * audited, permissioned and isolated per tenant. Chromium is replaced by a fake renderer here (ChromePdfRendererTest renders for real).
 */
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('documents');
    $this->pages = fakePdfRenderer();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    seedDocumentTemplates($this->ctx['tenant_id']);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->generator = fn (): DocumentGenerator => app(DocumentGenerator::class);
    $this->officer = userWithPermissions($this->ctx['tenant_id'], ['policy.create', 'policy.issue', 'receipt.create', 'document.generate']);
    [$this->policyId, $this->receiptId, $this->endorsementId, $this->quoteId] = ($this->in)(function (): array {
        $admin = $this->world['admin'];
        DB::table('product_versions')->where('id', $this->world['product_version_id'])->update(['class_code' => 'motor']);
        $lifecycle = app(PolicyLifecycle::class);
        $policy = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 2), $admin);
        $lifecycle->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $admin);
        $lifecycle->endorse($policy->id, CarbonImmutable::parse('2026-10-01'), 230_000, 'Sum insured increased to 1,400,000', $admin);
        $installment = (string) DB::table('installments')->where('policy_id', $policy->id)->orderBy('no')->value('id');
        $receipt = app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'bank_transfer', 7_000_000, 'BDT',
            CarbonImmutable::parse('2026-09-05'), null, 'TT 88231', [new AllocationLine($installment, 6_000_000)]), $admin);
        $quote = $lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            null, CarbonImmutable::parse('2026-09-01'), 5_000_000, 'BDT'), $admin);

        return [$policy->id, $receipt->id, (string) DB::table('policy_transactions')->where('policy_id', $policy->id)->where('type', 'endorsement')->value('id'), $quote->id];
    });
    ($this->in)(fn () => DB::table('legal_entities')->where('id', $this->ctx['entity_id'])->update(['name' => 'Padma General Insurance PLC']));
});

it('generates the policy schedule: a generated_documents row, a stored document on the policy whose hash matches its bytes, and an audit row', function (): void {
    $generated = ($this->in)(fn () => ($this->generator)()->generate('policy_schedule', 'policy', $this->policyId, $this->officer));

    ($this->in)(function () use ($generated): void {
        $row = DB::table('generated_documents')->sole();
        $stored = DB::table('stored_documents')->where('id', $row->stored_document_id)->sole();
        $bytes = (string) Storage::disk('documents')->get($stored->storage_path);
        $policyNumber = (string) DB::table('policies')->where('id', $this->policyId)->value('number');
        $template = app(DocumentTemplates::class)->active(DocumentTemplateCode::PolicySchedule, 'motor', 'en');

        expect($row->id)->toBe($generated->id)->and($row->template_code)->toBe('policy_schedule')->and($row->template_id)->toBe($template?->id)
            ->and((int) $row->template_version)->toBe(1)->and($row->locale)->toBe('en')->and($row->object_type)->toBe('policy')->and($row->object_id)->toBe($this->policyId)
            ->and($row->number)->toBe($policyNumber)->and((int) $row->version)->toBe(1)->and($row->rendered_by)->toBe($this->officer)
            ->and($row->sha256)->toBe(hash('sha256', $bytes))->and($stored->sha256)->toBe($row->sha256)->and((int) $row->size_bytes)->toBe(strlen($bytes));
        expect($bytes)->toStartWith('%PDF-')
            ->and($stored->object_type)->toBe('policy')->and($stored->object_id)->toBe($this->policyId)->and($stored->mime)->toBe('application/pdf')
            ->and($stored->original_name)->toBe("policy-schedule-{$policyNumber}-v1-en.pdf")->and($stored->uploaded_by)->toBe($this->officer);

        // What was printed: the policy, its parties, premium, installments, the endorsement reason as a special term, and the reference of its content.
        $html = $this->pages[0];
        expect($html)->toContain('Policy schedule')->toContain($policyNumber)->toContain('Padma General Insurance PLC')->toContain('Rahima Akter')
            ->toContain('Jamal Agent (AG-001)')->toContain('Motor Comprehensive (MOTOR)')->toContain('1 Sep 2026')->toContain('122,300.00')
            ->toContain('Special terms')->toContain('Endorsement from 1 Oct 2026: Sum insured increased to 1,400,000')
            ->toContain('Reference '.substr((string) $row->content_sha256, 0, 12));

        $audit = DB::table('audit_events')->where('action', 'document.generated')->sole();
        expect($audit->object_type)->toBe('policy')->and($audit->object_id)->toBe($this->policyId)->and($audit->actor_user_id)->toBe($this->officer)
            ->and(json_decode((string) $audit->after, true))->toMatchArray(['document_id' => $stored->id, 'generated_document_id' => $row->id, 'template_code' => 'policy_schedule',
                'template_version' => 1, 'version' => 1, 'locale' => 'en', 'sha256' => $row->sha256]);
        expect(DB::table('audit_events')->where('action', 'document.attached')->count())->toBe(0);
    });
});

it('keeps version 1 when the schedule is generated again, and generated documents cannot be changed or deleted', function (): void {
    $first = ($this->in)(fn () => ($this->generator)()->generate(DocumentTemplateCode::PolicySchedule, 'policy', $this->policyId, $this->officer));
    // The template changes in between: version 2 prints with template version 2, version 1 keeps what it was printed with.
    ($this->in)(function (): void {
        $admin = userWithPermissions($this->ctx['tenant_id'], ['document.manage_templates']);
        $templates = app(DocumentTemplates::class);
        $v1 = $templates->active(DocumentTemplateCode::PolicySchedule, null, 'en');
        $templates->activate($templates->saveDraft((string) $v1?->id, $v1?->body.'<p>Printed with template two.</p>', null, $admin)->id, $admin);
    });
    $second = ($this->in)(fn () => ($this->generator)()->generate(DocumentTemplateCode::PolicySchedule, 'policy', $this->policyId, $this->officer, 'bn'));

    ($this->in)(function () use ($first, $second): void {
        expect($second->version)->toBe(2)->and($second->id)->not->toBe($first->id)->and($second->storedDocumentId)->not->toBe($first->storedDocumentId);
        $rows = DB::table('generated_documents')->orderBy('version')->get()->all();
        expect($rows)->toHaveCount(2)->and((int) $rows[0]->version)->toBe(1)->and($rows[0]->sha256)->toBe($first->sha256)
            ->and((int) $rows[0]->template_version)->toBe(1)->and($rows[0]->locale)->toBe('en');
        // The Bangla template was not edited, so the Bangla schedule uses Bangla template version 1.
        expect((int) $rows[1]->template_version)->toBe(1)->and($rows[1]->locale)->toBe('bn')->and($this->pages[1])->toContain('পলিসি তফসিল')->toContain('বিশেষ শর্তাবলি');
        expect(Storage::disk('documents')->exists((string) DB::table('stored_documents')->where('id', $first->storedDocumentId)->value('storage_path')))->toBeTrue();

        $third = ($this->generator)()->generate(DocumentTemplateCode::PolicySchedule, 'policy', $this->policyId, $this->officer, 'en');
        expect($third->version)->toBe(3)->and($third->templateVersion)->toBe(2)->and($this->pages[2])->toContain('Printed with template two.')
            ->and(($this->generator)()->history('policy', $this->policyId))->toHaveCount(3);

        expect(fn () => DB::table('generated_documents')->where('id', $first->id)->update(['sha256' => str_repeat('0', 64)]))->toThrow(QueryException::class, 'GENERATED_DOCUMENT_APPEND_ONLY');
        expect(fn () => DB::table('generated_documents')->where('id', $first->id)->delete())->toThrow(QueryException::class, 'GENERATED_DOCUMENT_APPEND_ONLY');
        expect(fn () => DB::table('stored_documents')->where('id', $first->storedDocumentId)->delete())->toThrow(QueryException::class, 'DOCUMENT_APPEND_ONLY');
    });
});

it('generates an endorsement onto the policy page and a receipt onto the receipt page', function (): void {
    ($this->in)(function (): void {
        $endorsement = ($this->generator)()->generate('endorsement', 'policy_transaction', $this->endorsementId, $this->officer);
        $number = (string) DB::table('policies')->where('id', $this->policyId)->value('number');
        expect($endorsement->number)->toBe("{$number}/E1")->and($endorsement->objectType)->toBe('policy_transaction')
            ->and(DB::table('stored_documents')->where('id', $endorsement->storedDocumentId)->value('object_id'))->toBe($this->policyId);
        expect($this->pages[0])->toContain('Endorsement')->toContain("{$number}/E1")->toContain('Sum insured increased to 1,400,000')->toContain('2,300.00')
            ->toContain('All other terms remain unchanged.');

        $receipt = ($this->generator)()->generate('receipt', 'receipt', $this->receiptId, $this->officer, 'bn');
        $receiptNumber = (string) DB::table('receipts')->where('id', $this->receiptId)->value('number');
        expect($receipt->number)->toBe($receiptNumber)->and(DB::table('stored_documents')->where('id', $receipt->storedDocumentId)->value('object_type'))->toBe('receipt');
        expect($this->pages[1])->toContain('প্রাপ্তি রসিদ')->toContain($receiptNumber)->toContain('70,000.00')->toContain('60,000.00')->toContain('10,000.00')
            ->toContain('ব্যাংক ট্রান্সফার')->toContain('TT 88231')->toContain('কিস্তি 1')->toContain($number);
    });
});

it('refuses what cannot be generated: a quote, a missing template, an unknown object or document type', function (): void {
    ($this->in)(function (): void {
        expect(thrownBy(fn () => ($this->generator)()->generate('policy_schedule', 'policy', $this->quoteId, $this->officer), BusinessRuleViolation::class)->reasonCode)->toBe('DOCUMENT_OBJECT_NOT_READY');
        expect(thrownBy(fn () => ($this->generator)()->generate('policy_schedule', 'policy', (string) Illuminate\Support\Str::uuid7(), $this->officer), BusinessRuleViolation::class)->reasonCode)->toBe('DOCUMENT_OBJECT_UNKNOWN');
        expect(thrownBy(fn () => ($this->generator)()->generate('quotation', 'policy', $this->policyId, $this->officer), BusinessRuleViolation::class)->reasonCode)->toBe('DOCUMENT_PROVIDER_MISSING');
        expect(thrownBy(fn () => ($this->generator)()->generate('invoice', 'policy', $this->policyId, $this->officer), BusinessRuleViolation::class)->reasonCode)->toBe('DOCUMENT_TEMPLATE_CODE_UNKNOWN');
        DB::table('document_templates')->where('code', 'receipt')->where('locale', 'en')->update(['status' => 'retired', 'retired_at' => now()]);
        expect(thrownBy(fn () => ($this->generator)()->generate('receipt', 'receipt', $this->receiptId, $this->officer), BusinessRuleViolation::class)->reasonCode)->toBe('DOCUMENT_TEMPLATE_MISSING');
        expect(DB::table('generated_documents')->count())->toBe(0)->and(DB::table('stored_documents')->count())->toBe(0);
    });
});

it('uses the product class template when there is one', function (): void {
    ($this->in)(function (): void {
        $admin = userWithPermissions($this->ctx['tenant_id'], ['document.manage_templates']);
        $templates = app(DocumentTemplates::class);
        $motor = $templates->create('policy_schedule', 'motor', 'en', '<h1>{{ $document[\'title\'] }}</h1><p>Motor schedule {{ $document[\'number\'] }}</p>', null, $admin);
        $templates->activate($motor->id, $admin);
        $generated = ($this->generator)()->generate('policy_schedule', 'policy', $this->policyId, $this->officer);
        expect($generated->templateId)->toBe($motor->id)->and($this->pages[0])->toContain('Motor schedule');
    });
});

it('needs document.generate in the object\'s branch', function (): void {
    $viewer = userWithPermissions($this->ctx['tenant_id'], ['policy.issue', 'receipt.create']);
    $otherBranch = ($this->in)(function (): string {
        $id = (string) Illuminate\Support\Str::uuid7();
        DB::table('branches')->insert(['id' => $id, 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => 'CTG', 'name' => 'Chattogram', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    });
    $elsewhere = userWithPermissions($this->ctx['tenant_id'], ['policy.issue', 'document.generate'], 'branch', $otherBranch);
    ($this->in)(function () use ($viewer, $elsewhere): void {
        expect(thrownBy(fn () => ($this->generator)()->generate('policy_schedule', 'policy', $this->policyId, $viewer), PermissionDenied::class)->permission)->toBe('document.generate');
        expect(fn () => ($this->generator)()->generate('receipt', 'receipt', $this->receiptId, $elsewhere))->toThrow(PermissionDenied::class);
        expect(DB::table('generated_documents')->count())->toBe(0)->and($this->pages)->toHaveCount(0);
    });
});

it('generates from the policy and receipt pages and lists the versions on the Documents tab', function (): void {
    $officer = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $this->officer));
    ($this->in)(fn () => DB::table('users')->where('id', $this->officer)->update(['name' => 'Rafiq Islam']));

    actingAs($officer)->get("/policies/{$this->policyId}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->missing('documentGeneration')
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload
            ->where('documentGeneration.url', "/policies/{$this->policyId}/generated-documents")
            ->where('documentGeneration.actions.0', ['label' => 'Generate schedule', 'template_code' => 'policy_schedule', 'object_id' => null])
            ->where('documentGeneration.actions.1', ['label' => 'Generate endorsement 1 (2026-10-01)', 'template_code' => 'endorsement', 'object_id' => $this->endorsementId])
            ->has('documentGeneration.history', 0)));

    actingAs($officer)->post("/policies/{$this->policyId}/generated-documents", ['template_code' => 'policy_schedule', 'locale' => 'en'], $this->headers)
        ->assertSessionHasNoErrors()->assertRedirect("/policies/{$this->policyId}?tab=documents")->assertSessionHas('status', 'Policy schedule version 1 generated.');
    actingAs($officer)->post("/policies/{$this->policyId}/generated-documents", ['template_code' => 'endorsement', 'object_id' => $this->endorsementId, 'locale' => 'bn'], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Endorsement version 1 generated.');
    actingAs($officer)->post("/policies/{$this->policyId}/generated-documents", ['template_code' => 'endorsement', 'object_id' => $this->receiptId, 'locale' => 'en'], $this->headers)->assertNotFound();
    actingAs($officer)->post("/receipts/{$this->receiptId}/generated-documents", ['locale' => 'en'], $this->headers)
        ->assertSessionHasNoErrors()->assertRedirect("/receipts/{$this->receiptId}?tab=documents");

    $schedule = ($this->in)(fn () => DB::table('generated_documents')->where('template_code', 'policy_schedule')->sole());
    actingAs($officer)->get("/policies/{$this->policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('timeline.0.sentence', fn (string $s): bool => str_contains($s, 'generated by Rafiq Islam'))
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload
            ->has('documentGeneration.history', 2)
            ->where('documentGeneration.history.1.title', 'Policy schedule')->where('documentGeneration.history.1.version', 1)
            ->where('documentGeneration.history.1.reference', substr((string) $schedule->content_sha256, 0, 12))
            ->where('documentGeneration.history.1.url', "/policies/{$this->policyId}/documents/{$schedule->stored_document_id}")
            ->where('documentGeneration.history.1.rendered_by', 'Rafiq Islam')
            ->has('documents', 2)));
    $download = actingAs($officer)->get("/policies/{$this->policyId}/documents/{$schedule->stored_document_id}", $this->headers)->assertOk();
    expect(hash('sha256', (string) $download->streamedContent()))->toBe($schedule->sha256);

    actingAs($officer)->get("/receipts/{$this->receiptId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload->has('documentGeneration.history', 1)->where('documentGeneration.actions.0.label', 'Generate receipt')));

    $reader = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['reports.financial'])));
    actingAs($reader)->post("/policies/{$this->policyId}/generated-documents", ['template_code' => 'policy_schedule', 'locale' => 'en'], $this->headers)
        ->assertSessionHasErrors(['reason' => 'PERMISSION_DENIED']);
    actingAs($reader)->get("/policies/{$this->policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload->where('documentGeneration.actions', [])->has('documentGeneration.history', 2)));
    $stranger = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['claim.register', 'document.generate'])));
    actingAs($stranger)->post("/receipts/{$this->receiptId}/generated-documents", ['locale' => 'en'], $this->headers)->assertSessionHasErrors(['reason' => 'PERMISSION_DENIED']);
});

it('keeps generated documents inside their tenant', function (): void {
    ($this->in)(fn () => ($this->generator)()->generate('policy_schedule', 'policy', $this->policyId, $this->officer));
    $other = seedDemoTenant('other');
    seedDocumentTemplates($other['tenant_id']);
    $outsider = userWithPermissions($other['tenant_id'], ['document.generate', 'policy.issue']);

    DB::statement('SET ROLE erp_app');
    try {
        asTenant($other['tenant_id'], function () use ($outsider): void {
            expect(DB::table('generated_documents')->count())->toBe(0)->and(DB::table('document_templates')->count())->toBe(16)
                ->and(app(DocumentGenerator::class)->history('policy', $this->policyId))->toBe([]);
            expect(thrownBy(fn () => app(DocumentGenerator::class)->generate('policy_schedule', 'policy', $this->policyId, $outsider), BusinessRuleViolation::class)->reasonCode)->toBe('DOCUMENT_OBJECT_UNKNOWN');
        });
        expect(asTenant($this->ctx['tenant_id'], fn (): int => DB::table('generated_documents')->count()))->toBe(1)
            ->and(DB::table('generated_documents')->count())->toBe(0);
    } finally {
        DB::statement('RESET ROLE');
    }
});
