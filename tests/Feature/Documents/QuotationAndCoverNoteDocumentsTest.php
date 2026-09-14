<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\CoverNote\Application\CoverNoteService;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use App\Modules\Insurance\Underwriting\Application\UnderwritingLimits;
use App\Modules\Platform\Documents\Generation\DocumentGenerator;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Phase 3 design §3 documents for the quote-to-cover flow (after R4, R6 and R8): the quotation and the cover note print from their active templates
 * like the policy schedule — risk details with the product's own labels, the frozen premium breakdown, validity — through the DocumentGenerator
 * (stored immutably on the object, versioned, audited). A draft quotation has nothing to print yet.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    Storage::fake('documents');
    $this->pages = fakePdfRenderer();
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->world = ratedProductsWorld($this->ctx);
    seedDocumentTemplates($this->ctx['tenant_id']);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->officer = ($this->in)(function (): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'email' => 'rafiq@example.test', 'name' => 'Rafiq Officer',
            'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', 'branch_officer')->value('id'),
            'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);
        app(UnderwritingLimits::class)->set('branch_officer', 'motor', 200_000_000, CarbonImmutable::today(), $this->world['admin']);

        return User::query()->findOrFail($id);
    });
    [$this->draftId, $this->quotationId, $this->coverNoteId] = ($this->in)(function (): array {
        $quotations = app(QuotationService::class);
        $terms = new QuotationTerms($this->ctx['branch_id'], $this->world['motor_product_id'], $this->world['policyholder_id'], $this->world['agent_id'],
            CarbonImmutable::parse('2026-09-20'), $this->world['motor_inputs'], ['passenger_liability']);
        $draft = $quotations->saveDraft($terms, null, $this->officer->id);
        $quotation = $quotations->issue($quotations->saveDraft($terms, null, $this->officer->id)->id, CarbonImmutable::today(), $this->officer->id);
        $proposals = app(ProposalService::class);
        $proposal = $proposals->createFromQuotation($quotation->id, $this->officer->id);
        $proposals->verifyKyc($proposal->id, 'nid', '1990123456789', $this->officer->id);
        $proposals->submit($proposal->id, $this->officer->id);
        $note = app(CoverNoteService::class)->issue($proposal->id, CarbonImmutable::parse('2026-09-20'), CarbonImmutable::parse('2026-10-19'), $this->officer->id, 'TRF 1001'); // GA-28: not on credit

        return [$draft->id, $quotation->id, $note->id];
    });
});

it('prints an issued quotation with its risk details, frozen premium and validity, in English and Bangla', function (): void {
    $quotation = ($this->in)(fn () => DB::table('quotations')->where('id', $this->quotationId)->first(['number', 'gross_premium_minor']));
    $generated = ($this->in)(fn () => app(DocumentGenerator::class)->generate('quotation', 'quotation', $this->quotationId, $this->officer->id));
    $html = (string) $this->pages[0];

    expect($generated->number)->toBe($quotation->number)
        ->and($generated->version)->toBe(1)
        ->and($html)->toContain($quotation->number)->toContain('Private car')->toContain('Sum insured')->toContain('30,918.30')->toContain('Valid until')->toContain('29 Sep 2026')
        ->and(($this->in)(fn () => DB::table('stored_documents')->where('object_type', 'quotation')->where('object_id', $this->quotationId)->count()))->toBe(1);

    ($this->in)(fn () => app(DocumentGenerator::class)->generate('quotation', 'quotation', $this->quotationId, $this->officer->id, 'bn'));
    expect((string) $this->pages[1])->toContain('ব্যক্তিগত গাড়ি')->toContain('বিমাকৃত অঙ্ক');

    $refusal = thrownBy(fn () => ($this->in)(fn () => app(DocumentGenerator::class)->generate('quotation', 'quotation', $this->draftId, $this->officer->id)), BusinessRuleViolation::class);
    expect($refusal->reasonCode)->toBe('DOCUMENT_OBJECT_NOT_READY');
});

it('prints the cover note with its temporary period of cover', function (): void {
    $number = ($this->in)(fn () => DB::table('cover_notes')->where('id', $this->coverNoteId)->value('number'));
    $generated = ($this->in)(fn () => app(DocumentGenerator::class)->generate('cover_note', 'cover_note', $this->coverNoteId, $this->officer->id));

    expect($generated->number)->toBe($number)
        ->and((string) $this->pages[0])->toContain($number)->toContain('20 Sep 2026')->toContain('19 Oct 2026')->toContain('Registration number')->toContain('30,918.30');
});

it('generates and downloads both from their pages', function (): void {
    actingAs($this->officer)->post("/quotations/{$this->quotationId}/generated-documents", ['locale' => 'en'], $this->headers)
        ->assertRedirect("/quotations/{$this->quotationId}")->assertSessionHas('status', 'Quotation version 1 generated.');
    actingAs($this->officer)->get("/quotations/{$this->quotationId}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('generation.actions.0.label', 'Generate quotation')
        ->where('generation.history.0.version', 1)
        ->where('generation.history.0.url', fn (string $url): bool => str_starts_with($url, "/quotations/{$this->quotationId}/documents/")));
    $url = ($this->in)(fn (): string => "/quotations/{$this->quotationId}/documents/".DB::table('stored_documents')->where('object_id', $this->quotationId)->value('id'));
    expect(actingAs($this->officer)->get($url, $this->headers)->assertOk()->streamedContent())->toStartWith('%PDF');

    actingAs($this->officer)->post("/cover-notes/{$this->coverNoteId}/generated-documents", ['locale' => 'bn'], $this->headers)
        ->assertRedirect('/cover-notes')->assertSessionHas('status', 'Cover note version 1 generated.');
    $noteUrl = ($this->in)(fn (): string => "/cover-notes/{$this->coverNoteId}/documents/".DB::table('stored_documents')->where('object_id', $this->coverNoteId)->value('id'));
    expect(actingAs($this->officer)->get($noteUrl, $this->headers)->assertOk()->streamedContent())->toStartWith('%PDF');
    actingAs($this->officer)->get('/cover-notes', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('coverNotes.0.documents.0.url', $noteUrl));

    $outsider = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], ['claim.register'])));
    actingAs($outsider)->post("/quotations/{$this->quotationId}/generated-documents", ['locale' => 'en'], $this->headers)->assertSessionHasErrors('form');
});
