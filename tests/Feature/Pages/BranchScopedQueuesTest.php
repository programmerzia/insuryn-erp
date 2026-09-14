<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ChequeDetails;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\CoverNote\Application\CoverNoteService;
use App\Modules\Insurance\Party\Application\AgentService;
use App\Modules\Insurance\Party\Application\PartyService;
use App\Modules\Insurance\Party\Domain\Enums\PartyKind;
use App\Modules\Insurance\Party\Domain\Enums\PartyRoleType;
use App\Modules\Insurance\Policy\Application\Dunning\DunningRun;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use App\Modules\Insurance\Underwriting\Application\UnderwritingLimits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Follow-up H1 (design §7.2 "branch users never see other branches", D-43, A-136): the screens fix G2 left out — suspense, refunds, agent cash, cheques,
 * dunning, the allocation workbench, the referral queue, cover notes, the expiry register, Home work queues and badges, global search and the lookups — open
 * to a user whose only role is scoped to a branch, show only that branch's records, and refuse another branch's record with 403. Tenant-wide users see
 * every branch as before.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->world = ratedProductsWorld($this->ctx);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->home = $this->ctx['branch_id'];
    $this->ctg = ($this->in)(function (): string {
        DB::table('branches')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'code' => 'CTG',
            'name' => 'Chattogram', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    });
    /** A user holding the template roles, each scoped to $branch ('tenant' for a tenant-wide user). */
    $this->scoped = fn (array $roleCodes, string $branch): User => ($this->in)(function () use ($roleCodes, $branch): User {
        DB::table('users')->insert(['id' => $id = (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'email' => $id.'@example.test', 'name' => 'Branch user',
            'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($roleCodes as $code) {
            DB::table('user_roles')->insert(['tenant_id' => $this->ctx['tenant_id'], 'user_id' => $id, 'role_id' => DB::table('roles')->where('code', $code)->value('id'),
                'scope_type' => $branch === 'tenant' ? 'tenant' : 'branch', 'scope_id' => $branch === 'tenant' ? $this->ctx['tenant_id'] : $branch]);
        }

        return User::query()->findOrFail($id);
    });
    $admin = $this->world['admin'];
    ($this->in)(function () use ($admin): void {
        $code = (string) DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $admin)->value('r.code');
        app(UnderwritingLimits::class)->set($code, 'motor', 1_000_000_000, CarbonImmutable::today(), $admin); // motor proposals approve; fire ones are referred
    });
    /**
     * In $branch: a producer of the branch, an issued policy with two unpaid installments, an unallocated receipt (suspense), a cheque, an agent collection,
     * a claim, an issued quotation, a referred fire proposal, an approved motor proposal with its cover note, and a cancelled paid policy with a refund due.
     */
    $this->sold = fn (string $branch, string $code): array => ($this->in)(function () use ($branch, $code, $admin): array {
        $d = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);
        $lifecycle = app(PolicyLifecycle::class);
        $receipts = app(ReceiptService::class);
        $party = app(PartyService::class)->create(PartyKind::Individual, "Agent {$code}", null, [PartyRoleType::Agent], $admin);
        $agent = app(AgentService::class)->create($party->id, "AG-{$code}", $branch, null, null, $admin);
        $policy = $lifecycle->issue($lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $branch, $this->world['product_id'], $this->world['policyholder_id'], null,
            $d('2026-09-01'), 1_200_000, 'BDT', 2), $admin)->id, $d('2026-09-01'), $admin);
        $receipt = $receipts->record(new RecordReceiptRequest($this->ctx['entity_id'], $branch, null, 'cash', 100_000, 'BDT', $d('2026-09-10'), null, "counter {$code}", []), $admin);
        $cheque = $receipts->record(new RecordReceiptRequest($this->ctx['entity_id'], $branch, null, 'cheque', 50_000, 'BDT', $d('2026-09-11'), null, "cheque {$code}", [],
            new ChequeDetails("CHQ{$code}", 'Sonali Bank', $d('2026-09-11'))), $admin);
        $receipts->record(new RecordReceiptRequest($this->ctx['entity_id'], $branch, null, 'cash', 600_000, 'BDT', $d('2026-09-12'), null, "agent {$code}",
            [new AllocationLine((string) DB::table('installments')->where('policy_id', $policy->id)->where('no', 2)->value('id'), 600_000)], null, $agent->id), $admin);
        $claim = app(ClaimService::class)->register($policy->id, $d('2026-09-10'), "Collision {$code}", $admin, $d('2026-09-11'));
        $paid = $lifecycle->issue($lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $branch, $this->world['product_id'], $this->world['policyholder_id'], null,
            $d('2026-09-01'), 1_200_000, 'BDT', 1), $admin)->id, $d('2026-09-01'), $admin);
        $receipts->record(new RecordReceiptRequest($this->ctx['entity_id'], $branch, null, 'bank_transfer', 1_200_000, 'BDT', $d('2026-09-02'), null, "premium {$code}",
            [new AllocationLine((string) DB::table('installments')->where('policy_id', $paid->id)->value('id'), 1_200_000)]), $admin);
        $lifecycle->cancel($paid->id, $d('2026-09-14'), 'sold', $admin);

        $quotations = app(QuotationService::class);
        $quote = fn (string $product, array $inputs) => $quotations->issue($quotations->saveDraft(new QuotationTerms($branch, $this->world["{$product}_product_id"],
            $this->world['policyholder_id'], $this->world['agent_id'], $d('2026-09-15'), $inputs, []), null, $admin)->id, CarbonImmutable::today(), $admin);
        $motor = fn (): array => [...$this->world['motor_inputs'], 'registration_no' => 'DHK-'.Str::random(6), 'chassis_no' => 'CH-'.Str::random(8)];
        $quotation = $quote('motor', $motor());
        $proposals = app(ProposalService::class);
        $referred = $proposals->createFromQuotation($quote('fire', [...$this->world['fire_inputs'], 'address' => "Road {$code} ".Str::random(6)])->id, $admin);
        $proposals->verifyKyc($referred->id, 'nid', '1990123456789', $admin);
        $referred = $proposals->submit($referred->id, $admin);
        $approved = $proposals->createFromQuotation($quote('motor', $motor())->id, $admin);
        $proposals->verifyKyc($approved->id, 'nid', '1990123456789', $admin);
        $approved = $proposals->submit($approved->id, $admin);
        $note = app(CoverNoteService::class)->issue($approved->id, $d('2026-09-15'), $d('2026-10-10'), $admin, 'TRF-CN-'.$code); // GA-28: the premium was received

        return ['agent' => $agent->id, 'policy' => $policy->id, 'number' => (string) $policy->number, 'paid' => $paid->id, 'receipt' => $receipt->id, 'receipt_number' => $receipt->number,
            'cheque' => $cheque->id, 'claim' => $claim->id, 'claim_number' => $claim->number, 'quotation' => $quotation->id, 'referred' => $referred->id,
            'referred_status' => $referred->status->value, 'approved_status' => $approved->status->value, 'cover_note' => $note->id,
            'suspense' => (string) DB::table('suspense_items')->where('receipt_id', $receipt->id)->value('id')];
    });
    $this->mine = ($this->sold)($this->home, 'HO');
    $this->theirs = ($this->sold)($this->ctg, 'CTG');
    $this->rows = fn (mixed $rows): array => (array) json_decode((string) json_encode($rows), true);
    /** Every row passes $check (true for no rows). */
    $this->every = fn (mixed $rows, callable $check): bool => array_filter(($this->rows)($rows), fn (mixed $row): bool => ! $check((array) $row)) === [];
    $this->ids = fn (mixed $rows, string $key = 'id'): array => array_values(array_map(fn (array $row): string => (string) $row[$key], ($this->rows)($rows)));
});

it('sets up one of everything in each branch', function (): void {
    expect($this->mine['referred_status'])->toBe('submitted')->and($this->mine['approved_status'])->toBe('approved')
        ->and($this->theirs['referred_status'])->toBe('submitted')->and($this->theirs['approved_status'])->toBe('approved')
        ->and($this->mine['suspense'])->not->toBe('')->and($this->theirs['suspense'])->not->toBe('');
});

it('opens suspense, refunds, agent cash, cheques and dunning to a branch-scoped user with only their branch', function (): void {
    ($this->in)(fn () => app(DunningRun::class)->run($this->ctx['entity_id'], CarbonImmutable::parse('2026-10-09')));
    $manager = ($this->scoped)(['branch_manager'], $this->home);

    actingAs($manager)->get('/suspense?as_of=2026-09-30', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('suspense/Index')
        ->has('ageing.items', 2)->where('ageing.total', '1,500.00')
        ->where('ageing.items', fn ($rows): bool => in_array($this->mine['receipt'], ($this->ids)($rows, 'receipt_id'), true)
            && ($this->every)($rows, fn (array $row): bool => str_starts_with((string) $row['receipt_number'], 'RCT-HO-')))
        ->where('installments', fn ($rows): bool => ($this->rows)($rows) !== [] && ($this->every)($rows, fn (array $row): bool => str_starts_with((string) $row['label'], $this->mine['number'].' '))));

    actingAs($manager)->get('/refunds', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('refunds/Index')
        ->where('refundable', fn ($rows): bool => ($this->ids)($rows, 'policy_id') === [$this->mine['paid']]));

    actingAs($manager)->get('/agent-cash?as_of=2026-09-30', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('agentCash/Index')
        ->where('position.rows', fn ($rows): bool => ($this->ids)($rows, 'agent_id') === [$this->mine['agent']])->where('position.totals.collected_minor', '6,000.00')
        ->where('agents', fn ($rows): bool => ! in_array($this->theirs['agent'], ($this->ids)($rows), true) && in_array($this->mine['agent'], ($this->ids)($rows), true)));

    actingAs($manager)->get('/cheques?from=2026-09-01&to=2026-09-30', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Cheques')
        ->where('register.rows', fn ($rows): bool => ($this->ids)($rows, 'receipt_id') === [$this->mine['cheque']])->where('register.totals.presented', '500.00'));

    actingAs($manager)->get('/dunning?from=2026-10-01&to=2026-10-31', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Dunning')
        ->where('notices', fn ($rows): bool => ($this->rows)($rows) !== [] && ($this->every)($rows, fn (array $row): bool => $row['policy_id'] === $this->mine['policy'])));
});

it('lists every branch on those screens for a tenant-wide user', function (): void {
    ($this->in)(fn () => app(DunningRun::class)->run($this->ctx['entity_id'], CarbonImmutable::parse('2026-10-09')));
    $manager = ($this->scoped)(['branch_manager'], 'tenant');

    actingAs($manager)->get('/suspense?as_of=2026-09-30', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('ageing.items', fn ($rows): bool => array_intersect([$this->mine['receipt'], $this->theirs['receipt']], ($this->ids)($rows, 'receipt_id')) === [$this->mine['receipt'], $this->theirs['receipt']]));
    actingAs($manager)->get('/refunds', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->has('refundable', 2));
    actingAs($manager)->get('/agent-cash?as_of=2026-09-30', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->has('position.rows', 2));
    actingAs($manager)->get('/cheques?from=2026-09-01&to=2026-09-30', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->has('register.rows', 2));
    actingAs($manager)->get('/dunning?from=2026-10-01&to=2026-10-31', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('notices', fn ($rows): bool => array_diff([$this->mine['policy'], $this->theirs['policy']], ($this->ids)($rows, 'policy_id')) === []));
    actingAs($manager)->get("/receipts/{$this->theirs['receipt']}/allocate", $this->headers)->assertOk();
});

it('opens the allocation workbench for the branch\'s receipts only, with the branch\'s installments', function (): void {
    $manager = ($this->scoped)(['branch_manager'], $this->home);

    actingAs($manager)->get("/receipts/{$this->mine['receipt']}/allocate", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('receipts/Allocate')
        ->where('suspenseItemId', $this->mine['suspense'])
        ->where('candidates', fn ($rows): bool => ($this->rows)($rows) !== [] && ($this->every)($rows, fn (array $row): bool => $row['policy_number'] === $this->mine['number'])));
    actingAs($manager)->get("/receipts/{$this->theirs['receipt']}/allocate", $this->headers)->assertForbidden();
    // The allocation itself keeps its per-branch check.
    actingAs($manager)->post("/suspense/{$this->theirs['suspense']}/allocations", ['on' => '2026-09-15', 'lines' => [['installment_id' => (string) ($this->in)(fn () => DB::table('installments')
        ->where('policy_id', $this->theirs['policy'])->value('id')), 'amount' => '100.00']]], $this->headers)->assertSessionHasErrors(['reason' => 'PERMISSION_DENIED']);
});

it('lists only the branch\'s referrals, cover notes and expiring policies', function (): void {
    $manager = ($this->scoped)(['branch_manager'], $this->home);

    actingAs($manager)->get('/underwriting/referrals', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('underwriting/Referrals')
        ->where('referrals', fn ($rows): bool => ($this->ids)($rows) === [$this->mine['referred']]));
    actingAs($manager)->get('/cover-notes', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('coverNotes/Index')
        ->where('coverNotes', fn ($rows): bool => ($this->ids)($rows) === [$this->mine['cover_note']]));
    actingAs(($this->scoped)(['branch_manager'], 'tenant'))->get('/underwriting/referrals', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->has('referrals', 2));
    actingAs(($this->scoped)(['branch_manager'], 'tenant'))->get('/cover-notes', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->has('coverNotes', 2));

    travelTo(CarbonImmutable::parse('2027-08-05 09:00')); // the policies sold on 1 Sep 2026 expire on 31 Aug 2027: in the 30-day bucket
    actingAs($manager)->get('/renewals', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('renewals/Index')
        ->where('entries', fn ($rows): bool => ($this->rows)($rows) !== [] && ($this->every)($rows, fn (array $row): bool => $row['branch'] === 'HO')
            && in_array($this->mine['policy'], ($this->ids)($rows, 'policy_id'), true))
        ->where('branches', fn ($rows): bool => ($this->ids)($rows) === [$this->home]));
    actingAs(($this->scoped)(['branch_manager'], 'tenant'))->get('/renewals', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('entries', fn ($rows): bool => in_array($this->theirs['policy'], ($this->ids)($rows, 'policy_id'), true) && in_array($this->mine['policy'], ($this->ids)($rows, 'policy_id'), true)));
});

it('counts only the branch\'s work in Home queues and sidebar badges', function (): void {
    $claims = ($this->scoped)(['claims_officer'], $this->home);
    actingAs($claims)->get('/home', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues.0.key', 'claims_awaiting_reserve')->where('queues.0.count', 1)->where('queues.0.rows.0.href', "/claims/{$this->mine['claim']}")
        ->where('shell.badges.claims', 1));
    $accountant = ($this->scoped)(['accountant'], $this->home);
    actingAs($accountant)->get('/home', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues.0.key', 'unallocated_receipts')->where('queues.0.count', 2)
        ->where('queues.0.rows', fn ($rows): bool => ($this->every)($rows, fn (array $row): bool => str_starts_with((string) $row['cells']['receipt'], 'RCT-HO-')))
        ->where('shell.badges.suspense', 2));
    $officer = ($this->scoped)(['branch_officer'], $this->home);
    actingAs($officer)->get('/home', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues.3.key', 'quotes')->where('queues.3.rows', fn ($rows): bool => ($this->rows)($rows) !== []
            && ($this->every)($rows, fn (array $row): bool => str_contains((string) $row['cells']['number'], '-HO-'))));

    // Tenant-wide: both branches.
    actingAs(($this->scoped)(['claims_officer'], 'tenant'))->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queues.0.count', 2)->where('shell.badges.claims', 2));
    actingAs(($this->scoped)(['accountant'], 'tenant'))->get('/home', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('queues.0.count', 4));
});

it('finds only the branch\'s policies, claims and receipts in search and lookups, and every customer', function (): void {
    $manager = ($this->scoped)(['branch_manager', 'claims_manager'], $this->home);
    $labels = fn (string $url): array => array_column((array) actingAs($manager)->getJson($url, $this->headers)->assertOk()->json('results'), 'label');

    expect($labels('/search?q=Collision'))->toBe([$this->mine['claim_number']])
        ->and($labels('/search?q=counter'))->toBe([$this->mine['receipt_number']])
        ->and($labels('/search?q='.urlencode($this->theirs['number'])))->toBe([])
        ->and($labels('/search?q='.urlencode($this->mine['number'])))->toBe([$this->mine['number']])
        ->and($labels('/search?q=Rahima'))->toContain('Rahima Akter')
        ->and(array_filter($labels('/search?q=Rahima'), fn (string $label): bool => str_starts_with($label, 'POL-CTG')))->toBe([]);

    expect($labels('/lookup/policy?q=POL'))->toContain($this->mine['number']);
    expect(in_array($this->theirs['number'], $labels('/lookup/policy?q=POL'), true))->toBeFalse()
        ->and(array_filter($labels('/lookup/installment?q=POL'), fn (string $label): bool => ! str_starts_with($label, 'POL-HO')))->toBe([])
        ->and($labels('/lookup/installment?q=POL'))->not->toBe([])
        ->and($labels('/lookup/customer?q=Rahima'))->toBe(['Rahima Akter']);

    $tenantWide = ($this->scoped)(['branch_manager', 'claims_manager'], 'tenant');
    expect(array_column((array) actingAs($tenantWide)->getJson('/search?q=Collision', $this->headers)->json('results'), 'label'))->toHaveCount(2)
        ->and(array_column((array) actingAs($tenantWide)->getJson('/lookup/policy?q=POL', $this->headers)->json('results'), 'label'))->toContain($this->theirs['number']);
    // Without any of the area's permissions the lookups stay closed.
    actingAs(($this->scoped)(['claims_officer'], $this->home))->getJson('/lookup/installment?q=POL', $this->headers)->assertForbidden();
});

it('refuses another branch\'s quotation and cover note documents and printing', function (): void {
    fakePdfRenderer();
    seedDocumentTemplates($this->ctx['tenant_id']);
    $manager = ($this->scoped)(['branch_manager'], $this->home);

    actingAs($manager)->post("/cover-notes/{$this->mine['cover_note']}/generated-documents", ['locale' => 'en'], $this->headers)->assertSessionHasNoErrors()->assertRedirect('/cover-notes');
    actingAs($manager)->post("/cover-notes/{$this->theirs['cover_note']}/generated-documents", ['locale' => 'en'], $this->headers)->assertSessionHasErrors(['reason' => 'PERMISSION_DENIED']);
    actingAs($manager)->post("/quotations/{$this->mine['quotation']}/generated-documents", ['locale' => 'en'], $this->headers)->assertSessionHasNoErrors();
    actingAs($manager)->post("/quotations/{$this->theirs['quotation']}/generated-documents", ['locale' => 'en'], $this->headers)->assertSessionHasErrors(['reason' => 'PERMISSION_DENIED']);
    $mineDoc = (string) ($this->in)(fn () => DB::table('generated_documents')->where('object_id', $this->mine['cover_note'])->value('stored_document_id'));
    actingAs($manager)->get("/cover-notes/{$this->mine['cover_note']}/documents/{$mineDoc}", $this->headers)->assertOk();
    actingAs($manager)->get("/cover-notes/{$this->theirs['cover_note']}/documents/{$mineDoc}", $this->headers)->assertForbidden();
    actingAs($manager)->get("/quotations/{$this->theirs['quotation']}/documents/{$mineDoc}", $this->headers)->assertForbidden();
});
