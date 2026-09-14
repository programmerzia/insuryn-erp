<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Authorization\RoleTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Gap audit GA-41: the claim acknowledgement and discharge voucher templates had no data provider, so nothing could print them; the producer page
 * could not hold documents. The claim page's Documents tab now prints both (a voucher per approved payment), and the producer page has a document list.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('documents');
    $this->pages = fakePdfRenderer();
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'daily_365');
    seedDocumentTemplates($this->ctx['tenant_id']);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->user = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    $this->officer = ($this->user)(['claim.register', 'claim.reserve', 'document.generate']);
    $manager = userWithPermissions($this->ctx['tenant_id'], ['claim.approve', 'claim.pay_request']);
    $d = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);

    [$this->claimId, $this->paymentId] = asTenant($this->ctx['tenant_id'], function () use ($manager, $d): array {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            null, $d('2026-07-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, $d('2026-07-01'), $this->world['admin']);
        $claims = app(ClaimService::class);
        $claim = $claims->register($policy->id, $d('2026-09-01'), 'Rear-ended at a signal in Motijheel', (string) $this->officer->id, $d('2026-09-02'));
        $claims->reserve($claim->id, 3_000_000, 'Initial', (string) $this->officer->id, $d('2026-09-02'));
        $payment = app(ClaimPaymentService::class)->approve($claim->id, 1_800_000, $this->world['policyholder_id'], $manager, $d('2026-09-10'));

        return [$claim->id, $payment->id];
    });
});

it('prints the claim acknowledgement and the discharge voucher of an approved payment from the claim page', function (): void {
    $number = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('claims')->where('id', $this->claimId)->value('number'));

    actingAs($this->officer)->get("/claims/{$this->claimId}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('claims/Show')
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload
            ->where('documentGeneration.url', "/claims/{$this->claimId}/generated-documents")
            ->where('documentGeneration.actions.0', ['label' => 'Print acknowledgement', 'template_code' => 'claim_ack', 'object_id' => null])
            ->where('documentGeneration.actions.1', ['label' => 'Print discharge voucher 1 (18,000.00)', 'template_code' => 'discharge_voucher', 'object_id' => $this->paymentId])
            ->has('documentGeneration.history', 0)));

    actingAs($this->officer)->post("/claims/{$this->claimId}/generated-documents", ['template_code' => 'claim_ack', 'locale' => 'en'], $this->headers)
        ->assertSessionHasNoErrors()->assertRedirect("/claims/{$this->claimId}?tab=documents")->assertSessionHas('status', 'Claim acknowledgement version 1 generated.');
    expect($this->pages[0])->toContain('Claim acknowledgement')->toContain($number)->toContain('Rahima Akter')->toContain('Rear-ended at a signal in Motijheel')
        ->toContain('1 Sep 2026')->toContain('2 Sep 2026')->toContain('We have received your claim')
        ->and(str_contains($this->pages[0], '30,000.00'))->toBeFalse();

    actingAs($this->officer)->post("/claims/{$this->claimId}/generated-documents", ['template_code' => 'discharge_voucher', 'object_id' => $this->paymentId, 'locale' => 'bn'], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Discharge voucher version 1 generated.');
    expect($this->pages[1])->toContain('দাবি নিষ্পত্তি ভাউচার')->toContain("{$number}/P1")->toContain('18,000.00')->toContain('পূর্ণ ও চূড়ান্ত নিষ্পত্তির অর্থ');

    asTenant($this->ctx['tenant_id'], function (): void {
        $rows = DB::table('generated_documents')->orderBy('rendered_at')->get(['object_type', 'object_id', 'template_code']);
        expect($rows->map(fn ($r): array => [$r->object_type, $r->template_code])->all())->toBe([['claim', 'claim_ack'], ['claim_payment', 'discharge_voucher']])
            ->and(DB::table('stored_documents')->where('object_type', 'claim')->where('object_id', $this->claimId)->count())->toBe(2);
    });
    actingAs($this->officer)->get("/claims/{$this->claimId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload->has('documentGeneration.history', 2)->has('documents', 2)));

    // Another claim's payment is not this claim's voucher; someone without document.generate prints nothing.
    actingAs($this->officer)->post("/claims/{$this->claimId}/generated-documents", ['template_code' => 'discharge_voucher', 'object_id' => $this->world['policyholder_id'], 'locale' => 'en'], $this->headers)->assertNotFound();
    $reader = ($this->user)(['reports.financial']);
    actingAs($reader)->post("/claims/{$this->claimId}/generated-documents", ['template_code' => 'claim_ack', 'locale' => 'en'], $this->headers)->assertSessionHasErrors(['reason' => 'PERMISSION_DENIED']);
    actingAs($reader)->get("/claims/{$this->claimId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload->where('documentGeneration.actions', [])));
});

it('gives the claims roles document.generate', function (): void {
    expect(RoleTemplates::all()['claims_officer']['permissions'])->toContain('document.generate')
        ->and(RoleTemplates::all()['claims_manager']['permissions'])->toContain('document.generate');
});

it('attaches and downloads documents on the producer page', function (): void {
    $manager = ($this->user)(['agent.manage']);
    $reader = ($this->user)(['reports.financial']);
    $producer = $this->world['agent_id'];

    actingAs($manager)->get("/distribution/producers/{$producer}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('documentUpload', "/distribution/producers/{$producer}/documents")
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload->has('documents', 0)));
    actingAs($reader)->get("/distribution/producers/{$producer}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('documentUpload', null));

    actingAs($reader)->post("/distribution/producers/{$producer}/documents", ['file' => UploadedFile::fake()->createWithContent('agreement.pdf', "%PDF-1.4 agency agreement")], $this->headers)->assertSessionHasErrors(['reason' => 'PERMISSION_DENIED']);
    actingAs($manager)->post("/distribution/producers/{$producer}/documents", ['file' => UploadedFile::fake()->createWithContent('agreement.pdf', "%PDF-1.4 agency agreement"), 'description' => 'Agency agreement'], $this->headers)
        ->assertSessionHasNoErrors()->assertRedirect("/distribution/producers/{$producer}?tab=documents")->assertSessionHas('status', 'Document agreement.pdf attached.');

    $documentId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('stored_documents')->where('object_type', 'producer')->where('object_id', $producer)->value('id'));
    actingAs($reader)->get("/distribution/producers/{$producer}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->loadDeferredProps('history', fn (AssertableInertia $reload) => $reload->has('documents', 1)->where('documents.0.url', "/distribution/producers/{$producer}/documents/{$documentId}")
            ->where('documents.0.description', 'Agency agreement')));
    actingAs($reader)->get("/distribution/producers/{$producer}/documents/{$documentId}", $this->headers)->assertOk()->assertDownload('agreement.pdf');
});
