<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\CoverNote\Application\CoverNoteService;
use App\Modules\Insurance\CoverNote\Domain\Models\CoverNote;
use App\Modules\Insurance\CoverNote\Infrastructure\Jobs\CoverNoteExpiryJob;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use App\Modules\Insurance\Underwriting\Application\UnderwritingLimits;
use App\Modules\Insurance\Underwriting\Domain\Models\Proposal;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Phase 3 design §2 step 3 (slice R6): a cover note for an approved proposal, branch-coded number, validity capped per class (OPEN 2), no accounting unless the
 * product recognises premium at cover note (refused: not supported yet), superseded by the policy (R7 hook), expired nightly, cancelled with a reason; the cover
 * notes queue sorted by expiry and the permissions.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->world = ratedProductsWorld($this->ctx);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->person = fn (string $name, array $roleCodes): User => ($this->in)(function () use ($name, $roleCodes): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'email' => Str::slug($name).'@example.test', 'name' => $name,
            'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($roleCodes as $code) {
            DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $code)->value('id'),
                'scope_type' => 'tenant', 'scope_id' => $this->ctx['tenant_id']]);
        }

        return User::query()->findOrFail($id);
    });
    $this->officer = ($this->person)('Rafiq Officer', ['branch_officer']);
    $this->manager = ($this->person)('Salma Manager', ['branch_manager']);
    ($this->in)(function (): void {
        // GA-28: these cases are about numbers, dates and states, so the world's products issue on credit; the premium rule has its own case below.
        foreach (['motor_version_id', 'fire_version_id'] as $version) {
            app(ProductCatalogue::class)->configureRating($this->world[$version], ['allow_credit_issue' => true], $this->world['admin']);
        }
        app(UnderwritingLimits::class)->set('branch_officer', 'motor', 200_000_000, CarbonImmutable::today(), $this->world['admin']);
        app(UnderwritingLimits::class)->set('branch_officer', 'fire', 500_000_000_000, CarbonImmutable::today(), $this->world['admin']);
    });
    /** An approved (auto-approved) proposal of the officer. */
    $this->approved = fn (string $product = 'motor', array $inputs = []): Proposal => ($this->in)(function () use ($product, $inputs): Proposal {
        $quotations = app(QuotationService::class);
        $terms = new QuotationTerms($this->ctx['branch_id'], $this->world["{$product}_product_id"], $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-09-20'),
            [...$this->world["{$product}_inputs"], ...$inputs], []);
        $quotation = $quotations->issue($quotations->saveDraft($terms, null, $this->officer->id)->id, CarbonImmutable::today(), $this->officer->id);
        $proposals = app(ProposalService::class);
        $proposal = $proposals->createFromQuotation($quotation->id, $this->officer->id);
        $proposals->verifyKyc($proposal->id, 'nid', '1990123456789', $this->officer->id);

        return $proposals->submit($proposal->id, $this->officer->id);
    });
    $this->service = fn (): CoverNoteService => app(CoverNoteService::class);
    $this->d = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);
});

it('issues a branch-numbered cover note for an approved proposal and posts no accounting', function (): void {
    $proposal = ($this->approved)();
    $counts = fn (): array => ($this->in)(fn (): array => [DB::table('accounting_events')->count(), DB::table('outbox')->count(), DB::table('journals')->count()]);
    $before = $counts();

    $note = ($this->in)(fn (): CoverNote => ($this->service)()->issue($proposal->id, ($this->d)('2026-09-20'), ($this->d)('2026-10-19'), $this->officer->id));

    expect($note->number)->toBe('CVN-HO-2026-000001')->and($note->status->value)->toBe('active')->and($note->class_code)->toBe('motor')
        ->and($note->valid_to->toDateString())->toBe('2026-10-19')->and($counts())->toBe($before)
        ->and(($this->in)(fn () => DB::table('document_numbers')->where('number', 'CVN-HO-2026-000001')->value('status')))->toBe('used')
        ->and(($this->in)(fn () => DB::table('audit_events')->where('object_id', $note->id)->value('action')))->toBe('cover_note.issued')
        ->and(($this->in)(fn () => app(ProposalService::class)->approvedForIssue($proposal->id)->activeCoverNoteId))->toBe($note->id)
        ->and(thrownBy(fn () => ($this->in)(fn () => ($this->service)()->issue($proposal->id, ($this->d)('2026-09-21'), ($this->d)('2026-09-30'), $this->officer->id)), BusinessRuleViolation::class)->reasonCode)
        ->toBe('COVER_NOTE_ALREADY_ACTIVE');
});

it('caps validity per class, refuses backdating and unapproved proposals', function (): void {
    config(['erp.cover_notes.max_days' => ['default' => 30, 'fire' => 15]]);
    $motor = ($this->approved)();
    $fire = ($this->approved)('fire');
    $referred = ($this->approved)('motor', ['sum_insured' => 900_000_000, 'registration_no' => 'DHK-9', 'chassis_no' => 'CH-9']); // above the officer's limit

    ($this->in)(function () use ($motor, $fire, $referred): void {
        $notes = ($this->service)();
        expect(CoverNoteService::maxDays('fire'))->toBe(15)->and(CoverNoteService::maxDays('motor'))->toBe(30)
            ->and($referred->status->value)->toBe('submitted')
            ->and(thrownBy(fn () => $notes->issue($referred->id, ($this->d)('2026-09-20'), ($this->d)('2026-09-30'), $this->officer->id), BusinessRuleViolation::class)->reasonCode)->toBe('PROPOSAL_NOT_APPROVED')
            ->and(thrownBy(fn () => $notes->issue($motor->id, ($this->d)('2026-09-14'), ($this->d)('2026-09-30'), $this->officer->id), BusinessRuleViolation::class)->reasonCode)->toBe('COVER_NOTE_BACKDATED')
            ->and(thrownBy(fn () => $notes->issue($motor->id, ($this->d)('2026-09-20'), ($this->d)('2026-09-19'), $this->officer->id), BusinessRuleViolation::class)->reasonCode)->toBe('COVER_NOTE_DATES_INVALID')
            ->and(thrownBy(fn () => $notes->issue($motor->id, ($this->d)('2026-09-20'), ($this->d)('2026-10-20'), $this->officer->id), BusinessRuleViolation::class)->getMessage())
            ->toBe('A motor cover note lasts at most 30 days, so it ends by 19 Oct 2026.')
            ->and(thrownBy(fn () => $notes->issue($fire->id, ($this->d)('2026-09-15'), ($this->d)('2026-09-30'), $this->officer->id), BusinessRuleViolation::class)->reasonCode)->toBe('COVER_NOTE_TOO_LONG')
            ->and($notes->issue($fire->id, ($this->d)('2026-09-15'), ($this->d)('2026-09-29'), $this->officer->id)->valid_to->toDateString())->toBe('2026-09-29')
            ->and($notes->issue($motor->id, ($this->d)('2026-09-15'), ($this->d)('2026-09-15'), $this->officer->id)->number)->toBe('CVN-HO-2026-000002');
        expect(DB::table('document_numbers')->where('status', 'reserved')->where('number', 'like', 'CVN-%')->count())->toBe(0);
    });
});

it('refuses cover notes for a product that recognises premium at cover note, a gap until a posting path exists', function (): void {
    ($this->in)(function (): void {
        $catalogue = app(ProductCatalogue::class);
        $product = $catalogue->createProduct('MOTOR-CN', 'Motor recognised at cover note', 'motor', $this->world['admin']);
        $version = $catalogue->addVersion($product->id, ['effective_from' => '2026-01-01', 'term_months' => 12, 'earning_method' => 'monthly', 'posting_rule_set' => 'default',
            'tax_profile' => ['tax_type' => 'VAT', 'jurisdiction' => 'BD', 'inclusive' => false, 'refund_tax_on_cancellation' => true], 'class_code' => 'motor',
            'risk_schema' => Database\Seeders\DemoRatingCatalogue::riskSchema('motor'), 'coverage_definitions' => Database\Seeders\DemoRatingCatalogue::coverages('motor'),
            'recognise_at' => 'cover_note'], $this->world['admin']);
        $this->world['motor_product_id'] = $product->id;
        expect($version->recognise_at->value)->toBe('cover_note');
    });
    $proposal = ($this->approved)();
    ($this->in)(function () use ($proposal): void {
        $refusal = thrownBy(fn () => ($this->service)()->issue($proposal->id, ($this->d)('2026-09-20'), ($this->d)('2026-09-30'), $this->officer->id), BusinessRuleViolation::class);
        expect($refusal->reasonCode)->toBe('RECOGNITION_AT_COVER_NOTE_NOT_SUPPORTED')->and(CoverNote::query()->count())->toBe(0)->and(DB::table('accounting_events')->count())->toBe(0);
    });
});

it('is superseded by the policy inside its transaction, and every note of the proposal with it', function (): void {
    $proposal = ($this->approved)();
    ($this->in)(function () use ($proposal): void {
        $notes = ($this->service)();
        $first = $notes->issue($proposal->id, ($this->d)('2026-09-15'), ($this->d)('2026-09-16'), $this->officer->id);
        travelTo(CarbonImmutable::parse('2026-09-17 02:00'));
        expect($notes->expireDue(CarbonImmutable::today()))->toBe(1);
        $second = $notes->issue($proposal->id, ($this->d)('2026-09-17'), ($this->d)('2026-10-01'), $this->officer->id);
        $policyId = (string) Str::uuid7();

        expect(fn () => $notes->supersede($second->id, $policyId, $this->officer->id))->toThrow(LogicException::class);
        expect(DB::transaction(fn (): int => $notes->supersedeForProposal($proposal->id, $policyId, $this->officer->id)))->toBe(2);
        foreach ([$first, $second] as $note) {
            $stored = CoverNote::query()->whereKey($note->id)->firstOrFail();
            expect($stored->status->value)->toBe('superseded')->and($stored->superseded_by_policy_id)->toBe($policyId);
        }
        expect(thrownBy(fn () => DB::transaction(fn () => $notes->supersede($first->id, $policyId, $this->officer->id)), BusinessRuleViolation::class)->reasonCode)->toBe('COVER_NOTE_NOT_ACTIVE')
            ->and(DB::table('audit_events')->where('object_id', $second->id)->where('action', 'cover_note.superseded')->value('permission'))->toBe('policy.issue');
    });
});

it('expires nightly after the last day and cancels with a reason', function (): void {
    $proposal = ($this->approved)();
    $other = ($this->approved)('fire');
    [$ending, $cancelled] = ($this->in)(fn (): array => [
        ($this->service)()->issue($proposal->id, ($this->d)('2026-09-15'), ($this->d)('2026-09-20'), $this->officer->id),
        ($this->service)()->issue($other->id, ($this->d)('2026-09-15'), ($this->d)('2026-09-25'), $this->officer->id),
    ]);

    ($this->in)(function () use ($cancelled): void {
        expect(thrownBy(fn () => ($this->service)()->cancel($cancelled->id, 'Customer withdrew', $this->officer->id), PermissionDenied::class)->permission)->toBe('cover_note.cancel')
            ->and(thrownBy(fn () => ($this->service)()->cancel($cancelled->id, ' ', $this->manager->id), BusinessRuleViolation::class)->reasonCode)->toBe('REASON_REQUIRED');
        $done = ($this->service)()->cancel($cancelled->id, 'Customer withdrew', $this->manager->id);
        expect($done->status->value)->toBe('cancelled')->and($done->cancel_reason)->toBe('Customer withdrew')
            ->and(thrownBy(fn () => ($this->service)()->cancel($cancelled->id, 'again', $this->manager->id), BusinessRuleViolation::class)->reasonCode)->toBe('COVER_NOTE_NOT_ACTIVE');
    });

    // Slice 2.1b (D-54): the last day ends at midnight in Dhaka (18:00 UTC), not at midnight UTC.
    travelTo(CarbonImmutable::parse('2026-09-20 17:59'));
    app(CoverNoteExpiryJob::class)->handle(app(CoverNoteService::class));
    expect(($this->in)(fn (): string => CoverNote::query()->whereKey($ending->id)->firstOrFail()->status->value))->toBe('active');
    travelTo(CarbonImmutable::parse('2026-09-20 18:00'));
    app(CoverNoteExpiryJob::class)->handle(app(CoverNoteService::class));
    ($this->in)(function () use ($ending, $cancelled): void {
        expect(CoverNote::query()->whereKey($ending->id)->firstOrFail()->status->value)->toBe('expired')
            ->and(CoverNote::query()->whereKey($cancelled->id)->firstOrFail()->status->value)->toBe('cancelled')
            ->and(DB::table('audit_events')->where('object_id', $ending->id)->where('action', 'cover_note.expired')->value('actor_type'))->toBe('system');
    });
});

it('issues a cover note on credit only when the product issues on credit, otherwise against a premium received with its reference (GA-28, A-196)', function (): void {
    ($this->in)(fn () => app(ProductCatalogue::class)->configureRating($this->world['motor_version_id'], ['allow_credit_issue' => false], $this->world['admin']));
    $proposal = ($this->approved)();
    $refusal = thrownBy(fn () => ($this->in)(fn () => ($this->service)()->issue($proposal->id, ($this->d)('2026-09-20'), ($this->d)('2026-09-30'), $this->officer->id)), BusinessRuleViolation::class);
    expect($refusal->reasonCode)->toBe('PREMIUM_NOT_RECEIVED')
        ->and(thrownBy(fn () => ($this->in)(fn () => ($this->service)()->issue($proposal->id, ($this->d)('2026-09-20'), ($this->d)('2026-09-30'), $this->officer->id, str_repeat('x', 129))), BusinessRuleViolation::class)->reasonCode)
        ->toBe('PREMIUM_REFERENCE_INVALID')
        ->and(($this->in)(fn () => CoverNote::query()->count()))->toBe(0);

    actingAs($this->officer)->get("/proposals/{$proposal->id}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('policyIssue.allow_credit', false));
    actingAs($this->officer)->post("/proposals/{$proposal->id}/cover-notes", ['valid_from' => '2026-09-20', 'valid_to' => '2026-09-30'], $this->headers)->assertSessionHasErrors('form');
    actingAs($this->officer)->post("/proposals/{$proposal->id}/cover-notes", ['valid_from' => '2026-09-20', 'valid_to' => '2026-09-30', 'premium_received' => true, 'premium_reference' => 'TRF 5521'], $this->headers)
        ->assertSessionHasNoErrors();
    $note = ($this->in)(fn () => DB::table('cover_notes')->first(['id', 'issue_basis', 'premium_received_reference']));
    expect([$note?->issue_basis, $note?->premium_received_reference])->toBe(['premium_received', 'TRF 5521'])
        ->and(($this->in)(fn () => json_decode((string) DB::table('audit_events')->where('object_id', $note?->id)->where('action', 'cover_note.issued')->value('after'), true)['premium_received_reference']))->toBe('TRF 5521');
    actingAs($this->officer)->get('/cover-notes', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('coverNotes.0.issue_basis', 'premium_received')->where('coverNotes.0.premium_received_reference', 'TRF 5521')->where('coverNotes.0.policy', null));

    // On a product that issues on credit, the note needs no reference and says so.
    $fire = ($this->approved)('fire');
    expect(($this->in)(fn () => ($this->service)()->issue($fire->id, ($this->d)('2026-09-20'), ($this->d)('2026-09-30'), $this->officer->id)->issue_basis))->toBe('credit');
});

it('lists cover notes by expiry with the expiring filter, issues from the proposal page and follows the permissions', function (): void {
    $proposal = ($this->approved)();
    $other = ($this->approved)('fire');
    actingAs($this->officer)->get("/proposals/{$proposal->id}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('can.issue_cover_note', true)->where('coverNoteMaxDays', 30)->where('coverNotes', []));
    actingAs($this->officer)->post("/proposals/{$proposal->id}/cover-notes", ['valid_from' => '2026-09-20', 'valid_to' => '2026-10-19'], $this->headers)
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Cover note CVN-HO-2026-000001 issued.');
    actingAs($this->officer)->post("/proposals/{$other->id}/cover-notes", ['valid_from' => '2026-09-15', 'valid_to' => '2026-09-18'], $this->headers)->assertSessionHasNoErrors();
    actingAs($this->officer)->get("/proposals/{$proposal->id}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('can.issue_cover_note', false)->where('coverNotes.0.number', 'CVN-HO-2026-000001')->where('coverNotes.0.status', 'active'));

    actingAs($this->officer)->get('/cover-notes', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('coverNotes/Index')
        ->where('within', null)->where('expiringDays', 7)
        ->where('coverNotes.0.number', 'CVN-HO-2026-000002')->where('coverNotes.0.days_left', 3)->where('coverNotes.0.can_cancel', false)
        ->where('coverNotes.1.number', 'CVN-HO-2026-000001')->where('coverNotes.1.valid_to', '2026-10-19'));
    actingAs($this->manager)->get('/cover-notes?within=7', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('within', 7)->where('coverNotes', fn ($rows): bool => count($rows) === 1)->where('coverNotes.0.can_cancel', true));
    $id = ($this->in)(fn (): string => (string) CoverNote::query()->where('number', 'CVN-HO-2026-000002')->value('id'));
    actingAs($this->officer)->post("/cover-notes/{$id}/cancel", ['reason' => 'Wrong risk'], $this->headers)->assertSessionHasErrors('form');
    actingAs($this->manager)->post("/cover-notes/{$id}/cancel", ['reason' => 'Wrong risk'], $this->headers)->assertSessionHasNoErrors()->assertRedirect('/cover-notes');

    $accountant = ($this->person)('Ayesha Accountant', ['accountant']);
    actingAs($accountant)->get('/cover-notes', $this->headers)->assertForbidden();
    actingAs($accountant)->post("/proposals/{$proposal->id}/cover-notes", ['valid_from' => '2026-09-20', 'valid_to' => '2026-09-21'], $this->headers)->assertSessionHasErrors('form');
    ($this->in)(function (): void {
        $holders = fn (string $permission): array => DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')->where('rp.permission_code', $permission)
            ->where('r.code', 'not like', 'test-%')->orderBy('r.code')->pluck('r.code')->all();
        expect($holders('cover_note.issue'))->toBe(['branch_manager', 'branch_officer'])->and($holders('cover_note.cancel'))->toBe(['branch_manager']);
    });
});
