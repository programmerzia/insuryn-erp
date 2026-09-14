<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\CoverNote\Application\CoverNoteService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use App\Modules\Insurance\Underwriting\Application\UnderwritingLimits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Gap audit GA-29: the command palette finds any number a customer quotes — quotation, proposal, cover note, producer code, and the vehicle's
 * registration or chassis number — only in areas the user may open; and an object page tells the palette the actions the user may take on it.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-15 09:00'));
    $this->ctx = seedDemoTenant();
    seedRoleTemplates($this->ctx['tenant_id']);
    $this->world = ratedProductsWorld($this->ctx);
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->userWith = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    $admin = $this->world['admin'];
    $this->made = asTenant($this->ctx['tenant_id'], function () use ($admin): array {
        $code = (string) DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $admin)->value('r.code');
        app(UnderwritingLimits::class)->set($code, 'motor', 1_000_000_000, CarbonImmutable::today(), $admin);
        $quotations = app(QuotationService::class);
        $inputs = [...$this->world['motor_inputs'], 'registration_no' => 'DHA-GA-11-4321', 'chassis_no' => 'CHS-ZX-98765'];
        $quotation = $quotations->issue($quotations->saveDraft(new QuotationTerms($this->ctx['branch_id'], $this->world['motor_product_id'], $this->world['policyholder_id'], $this->world['agent_id'],
            CarbonImmutable::parse('2026-09-15'), $inputs, []), null, $admin)->id, CarbonImmutable::today(), $admin);
        $proposals = app(ProposalService::class);
        $proposal = $proposals->createFromQuotation($quotation->id, $admin);
        $proposals->verifyKyc($proposal->id, 'nid', '1990123456789', $admin);
        $proposal = $proposals->submit($proposal->id, $admin);
        $note = app(CoverNoteService::class)->issue($proposal->id, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-10-10'), $admin, 'TRF-1');

        return ['quotation' => $quotation->fresh(), 'proposal' => $proposal, 'note' => $note];
    });
});

it('finds quotations, proposals and cover notes by number, and producers by code or name', function (): void {
    $officer = ($this->userWith)(['quotation.create', 'cover_note.issue', 'policy.create']);
    $search = fn (User $user, string $q): array => (array) actingAs($user)->getJson('/search?q='.urlencode($q), $this->headers)->assertOk()->json('results');
    $quotation = (string) $this->made['quotation']->number;
    $proposal = (string) $this->made['proposal']->number;
    $note = (string) $this->made['note']->number;

    expect($search($officer, $quotation))->toContain(['kind' => 'quotation', 'label' => $quotation, 'detail' => 'Rahima Akter · Converted · DHA-GA-11-4321', 'href' => "/quotations/{$this->made['quotation']->id}"])
        ->and(collect($search($officer, (string) preg_replace('/^([A-Z]+)-[A-Z0-9]+-\d{4}-0*(\d+)$/', '$1-$2', $quotation)))->pluck('label')->all())->toContain($quotation) // QUO-1
        ->and(collect($search($officer, $proposal))->firstWhere('kind', 'proposal'))->toMatchArray(['label' => $proposal, 'href' => "/proposals/{$this->made['proposal']->id}"])
        ->and(collect($search($officer, $note))->firstWhere('kind', 'cover_note'))->toMatchArray(['label' => $note, 'detail' => 'Rahima Akter · Active · until 10 Oct 2026', 'href' => "/proposals/{$this->made['proposal']->id}"]);

    $producers = ($this->userWith)(['agent.manage']);
    expect(collect($search($producers, 'AG-001'))->firstWhere('kind', 'producer'))->toMatchArray(['label' => 'AG-001', 'href' => "/distribution/producers/{$this->world['agent_id']}"])
        ->and(collect($search($producers, 'ag 1'))->pluck('label')->all())->toContain('AG-001')
        ->and(collect($search($producers, 'Jamal'))->pluck('kind')->all())->toContain('producer')
        // Areas the user cannot open stay out.
        ->and(collect($search($producers, $quotation))->pluck('kind')->all())->not->toContain('quotation')
        ->and(collect($search(($this->userWith)(['claim.register']), 'AG-001'))->pluck('kind')->all())->not->toContain('producer')
        ->and(collect($search(($this->userWith)(['claim.register']), $note))->pluck('kind')->all())->not->toContain('cover_note');
});

it('finds policies, proposals and quotations by the vehicle\'s registration or chassis number, however it is typed', function (): void {
    $officer = ($this->userWith)(['quotation.create', 'policy.create']);
    $policy = asTenant($this->ctx['tenant_id'], function (): string {
        $admin = $this->world['admin'];
        $quotations = app(QuotationService::class);
        $inputs = [...$this->world['motor_inputs'], 'registration_no' => 'DHA-METRO-GA-15-7777', 'chassis_no' => 'CHS-POL-00077'];
        $quotation = $quotations->issue($quotations->saveDraft(new QuotationTerms($this->ctx['branch_id'], $this->world['motor_product_id'], $this->world['policyholder_id'], $this->world['agent_id'],
            CarbonImmutable::parse('2026-09-15'), $inputs, []), null, $admin)->id, CarbonImmutable::today(), $admin);
        $proposals = app(ProposalService::class);
        $proposal = $proposals->createFromQuotation($quotation->id, $admin);
        $proposals->verifyKyc($proposal->id, 'nid', '1990123456789', $admin);
        $proposals->submit($proposal->id, $admin);

        return app(PolicyLifecycle::class)->issueFromProposal($proposal->id, CarbonImmutable::parse('2026-09-15'), $admin, 1, 'TRF-2')->id;
    });
    $kinds = fn (string $q): array => collect((array) actingAs($officer)->getJson('/search?q='.urlencode($q), $this->headers)->assertOk()->json('results'))
        ->map(fn (array $r): string => "{$r['kind']}:{$r['href']}")->all();

    expect($kinds('DHA GA 11 4321'))->toContain("quotation:/quotations/{$this->made['quotation']->id}", "proposal:/proposals/{$this->made['proposal']->id}")
        ->and($kinds('chs-zx-98765'))->toContain("proposal:/proposals/{$this->made['proposal']->id}")
        ->and($kinds('metro ga 15-7777'))->toContain("policy:/policies/{$policy}")
        ->and($kinds('CHSPOL00077'))->toContain("policy:/policies/{$policy}")
        ->and($kinds('4321'))->toContain("quotation:/quotations/{$this->made['quotation']->id}");
});

it('tells the policy page whether the user may request a refund', function (): void {
    $policy = asTenant($this->ctx['tenant_id'], function (): string {
        $lifecycle = app(PolicyLifecycle::class);
        $policy = $lifecycle->issue($lifecycle->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'], null,
            CarbonImmutable::parse('2026-09-01'), 1_200_000, 'BDT', 1), $this->world['admin'])->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);
        app(App\Modules\Insurance\Collections\Application\ReceiptService::class)->record(new App\Modules\Insurance\Collections\Application\RecordReceiptRequest($this->ctx['entity_id'],
            $this->ctx['branch_id'], null, 'bank_transfer', 1_200_000, 'BDT', CarbonImmutable::parse('2026-09-02'), null, 'premium',
            [new App\Modules\Insurance\Collections\Application\AllocationLine((string) DB::table('installments')->where('policy_id', $policy->id)->value('id'), 1_200_000)]), $this->world['admin']);
        $lifecycle->cancel($policy->id, CarbonImmutable::parse('2026-09-14'), 'sold', $this->world['admin']);

        return $policy->id;
    });
    actingAs(($this->userWith)(['policy.create', 'receipt.refund_request']))->get("/policies/{$policy}", $this->headers)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('actions.refund', true)->where('actions.cancel', false));
    actingAs(($this->userWith)(['policy.create']))->get("/policies/{$policy}", $this->headers)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('actions.refund', false));
});
