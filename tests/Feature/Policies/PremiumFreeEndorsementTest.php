<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Policy\Application\Documents\EndorsementDocumentData;
use App\Modules\Insurance\Policy\Application\Documents\PolicyDocumentFacts;
use App\Modules\Insurance\Policy\Application\PolicyDetailsEndorsement;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Reports\Application\PremiumRegisterQuery;
use App\Modules\Platform\Authorization\RoleTemplates;
use App\Modules\Platform\Documents\Templates\DocumentTemplateCode;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Gap fixes W7 (GA-25 remainder): only a premium or risk change could be endorsed, so a customer who moved house, a changed name or a vehicle loan paid off had
 * no endorsement. "Change details" endorses the insured's name, the address, the mortgagee or the contact details on the policy: numbered `<policy>/E<n>`
 * like any endorsement, printable, with no premium change and no journal.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-14 10:00'));
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->in = fn (callable $fn): mixed => asTenant($this->ctx['tenant_id'], $fn);
    $this->manager = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], RoleTemplates::all()['branch_manager']['permissions'])));
    [$this->policyId, $this->number] = ($this->in)(function (): array {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            $this->world['agent_id'], CarbonImmutable::parse('2026-09-01'), 12_000_000, 'BDT', 2), $this->world['admin']);
        $issued = app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-09-01'), $this->world['admin']);

        return [$policy->id, (string) $issued->number];
    });
    $this->events = fn (): int => ($this->in)(fn (): int => DB::table('accounting_events')->count());
});

it('records an address change as the next numbered endorsement with no premium and no accounting event, and offers to print it', function (): void {
    $events = ($this->events)();
    actingAs($this->manager)->get("/policies/{$this->policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('actions.endorse_details', true)->where('insuredDetails', ['insured_name' => 'Rahima Akter', 'address' => null, 'mortgagee' => null, 'mobile' => null, 'email' => null]));

    $response = actingAs($this->manager)->post("/policies/{$this->policyId}/endorse-details", ['kind' => 'address', 'effective_date' => '2026-09-14', 'address' => 'House 12, Road 5, Dhanmondi, Dhaka',
        'reason' => 'Customer moved'], $this->headers);
    $transaction = ($this->in)(fn (): object => DB::table('policy_transactions')->where('policy_id', $this->policyId)->where('type', 'endorsement')->sole());
    $response->assertSessionHasNoErrors()->assertSessionHas('status', "Endorsement {$this->number}/E1 recorded. The premium is unchanged; nothing is posted.")
        ->assertSessionHas('next', ['label' => 'Print endorsement', 'url' => "/policies/{$this->policyId}/generated-documents?template_code=endorsement&object_id={$transaction->id}", 'method' => 'post']);
    expect([$transaction->endorsement_kind, (int) $transaction->premium_delta_minor, (int) $transaction->net_delta_minor, (int) $transaction->tax_delta_minor, (int) $transaction->policy_version])
        ->toBe(['address', 0, 0, 0, 2])
        ->and(json_decode((string) $transaction->details_change, true))->toBe(['after' => ['address' => 'House 12, Road 5, Dhanmondi, Dhaka'], 'before' => ['address' => null]])
        ->and(($this->events)())->toBe($events)
        ->and(($this->in)(fn (): array => DB::table('audit_events')->where('object_id', $this->policyId)->where('action', 'policy.endorsed')->pluck('reason')->all()))->toBe(['Customer moved']);

    actingAs($this->manager)->get("/policies/{$this->policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('insuredDetails.address', 'House 12, Road 5, Dhanmondi, Dhaka')->where('policy.version', 2)->where('transactions.1.endorsement_kind', 'address'));

    // The same address again is no change; a premium endorsement afterwards is E2, and its installment says so.
    actingAs($this->manager)->post("/policies/{$this->policyId}/endorse-details", ['kind' => 'address', 'effective_date' => '2026-09-14', 'address' => 'House 12, Road 5, Dhanmondi, Dhaka',
        'reason' => 'again'], $this->headers)->assertSessionHasErrors(['form']);
    ($this->in)(fn () => app(PolicyLifecycle::class)->endorse($this->policyId, CarbonImmutable::parse('2026-09-15'), 230_000, 'Sum insured increased', $this->world['admin']));
    expect(($this->in)(fn (): array => DB::table('installments')->where('policy_id', $this->policyId)->whereNotNull('endorsement_no')->pluck('endorsement_no')->map(fn ($n): int => (int) $n)->all()))->toBe([2]);

    // Not premium: the premium register leaves it out.
    $types = ($this->in)(fn (): array => array_column(app(PremiumRegisterQuery::class)->register($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'))['rows'], 'type'));
    expect($types)->toBe(['new', 'endorsement']);
});

it('adds and removes a mortgagee, changes the name and contact details, and prints them on the endorsement and the policy documents', function (): void {
    $service = fn (): PolicyDetailsEndorsement => app(PolicyDetailsEndorsement::class);
    $on = CarbonImmutable::parse('2026-09-14');
    $mortgagee = ($this->in)(fn () => $service()->endorse($this->policyId, 'mortgagee', $on, ['mortgagee' => 'BRAC Bank PLC, Gulshan'], 'Vehicle financed', (string) $this->manager->id));
    ($this->in)(fn () => $service()->endorse($this->policyId, 'name', $on, ['insured_name' => 'Rahima Akter Chowdhury'], 'Name changed after marriage', (string) $this->manager->id));
    ($this->in)(fn () => $service()->endorse($this->policyId, 'contact', $on, ['mobile' => '01712 345678', 'email' => 'Rahima@Example.com'], 'New phone', (string) $this->manager->id));

    ($this->in)(function () use ($mortgagee): void {
        $variables = app(EndorsementDocumentData::class)->variables($mortgagee->id, DocumentTemplateCode::Endorsement, 'en');
        expect($variables['document']['number'])->toBe("{$this->number}/E1")
            ->and($variables['details'])->toContain(['label' => 'Change', 'value' => 'Mortgagee'], ['label' => 'Mortgagee before', 'value' => 'None'], ['label' => 'Mortgagee now', 'value' => 'BRAC Bank PLC, Gulshan'])
            ->and($variables['money'])->toBe([])->and($variables['total']['amount'])->toBe('')
            ->and($variables['special_terms'][0])->toBe('From 14 Sep 2026 Mortgagee reads: BRAC Bank PLC, Gulshan. The premium is unchanged. All other terms remain unchanged.');
        $bangla = app(EndorsementDocumentData::class)->variables($mortgagee->id, DocumentTemplateCode::Endorsement, 'bn');
        expect($bangla['details'])->toContain(['label' => 'পরিবর্তন', 'value' => 'বন্ধকগ্রহীতা']);

        $facts = app(PolicyDocumentFacts::class);
        expect($facts->common($facts->policy($this->policyId), 'en')['parties'])->toContain(['role' => 'Policyholder', 'name' => 'Rahima Akter Chowdhury'], ['role' => 'Mortgagee', 'name' => 'BRAC Bank PLC, Gulshan']);
    });
    expect(($this->in)(fn (): array => PolicyDetailsEndorsement::current(App\Modules\Insurance\Policy\Domain\Models\Policy::query()->whereKey($this->policyId)->firstOrFail())))
        ->toBe(['insured_name' => 'Rahima Akter Chowdhury', 'address' => null, 'mortgagee' => 'BRAC Bank PLC, Gulshan', 'mobile' => '+8801712345678', 'email' => 'rahima@example.com']);
    actingAs($this->manager)->get("/policies/{$this->policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('policy.policyholder', 'Rahima Akter Chowdhury'));
    // The customer record is unchanged (A-235).
    expect(($this->in)(fn (): string => (string) DB::table('parties')->where('id', $this->world['policyholder_id'])->value('display_name')))->toBe('Rahima Akter');

    // The loan is repaid: an empty mortgagee removes it.
    ($this->in)(fn () => $service()->endorse($this->policyId, 'mortgagee', $on, ['mortgagee' => ''], 'Loan repaid', (string) $this->manager->id));
    ($this->in)(function (): void {
        $facts = app(PolicyDocumentFacts::class);
        expect(array_column($facts->common($facts->policy($this->policyId), 'en')['parties'], 'role'))->not->toContain('Mortgagee');
    });
});

it('refuses details endorsements that are empty, invalid, outside cover, on a cancelled policy or by someone who may not endorse', function (): void {
    $service = fn (): PolicyDetailsEndorsement => app(PolicyDetailsEndorsement::class);
    $reason = fn (callable $call): string => thrownBy(fn () => ($this->in)($call), BusinessRuleViolation::class)->reasonCode;
    $on = CarbonImmutable::parse('2026-09-14');
    $manager = (string) $this->manager->id;

    expect($reason(fn () => $service()->endorse($this->policyId, 'name', $on, ['insured_name' => ' '], 'x', $manager)))->toBe('ENDORSEMENT_DETAIL_REQUIRED')
        ->and($reason(fn () => $service()->endorse($this->policyId, 'contact', $on, ['mobile' => '12345'], 'x', $manager)))->toBe('PARTY_MOBILE_INVALID')
        ->and($reason(fn () => $service()->endorse($this->policyId, 'contact', $on, ['email' => 'not-an-email'], 'x', $manager)))->toBe('PARTY_EMAIL_INVALID')
        ->and($reason(fn () => $service()->endorse($this->policyId, 'contact', $on, [], 'x', $manager)))->toBe('ENDORSEMENT_NO_CHANGE')
        ->and($reason(fn () => $service()->endorse($this->policyId, 'period', $on, [], 'x', $manager)))->toBe('ENDORSEMENT_KIND_UNKNOWN')
        ->and($reason(fn () => $service()->endorse($this->policyId, 'address', $on, ['address' => 'Somewhere'], ' ', $manager)))->toBe('REASON_REQUIRED')
        ->and($reason(fn () => $service()->endorse($this->policyId, 'address', CarbonImmutable::parse('2030-01-01'), ['address' => 'Somewhere'], 'x', $manager)))->toBe('ENDORSEMENT_OUTSIDE_COVER');

    $officer = ($this->in)(fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], RoleTemplates::all()['branch_officer']['permissions'])));
    actingAs($officer)->post("/policies/{$this->policyId}/endorse-details", ['kind' => 'address', 'effective_date' => '2026-09-14', 'address' => 'Somewhere', 'reason' => 'x'], $this->headers)->assertSessionHasErrors(['form' => 'You do not have permission for this action. Ask an administrator if you need it.']);

    ($this->in)(fn () => app(PolicyLifecycle::class)->cancel($this->policyId, CarbonImmutable::parse('2026-09-14'), 'sold', $this->world['admin']));
    expect($reason(fn () => $service()->endorse($this->policyId, 'address', $on, ['address' => 'Somewhere'], 'x', $manager)))->toBe('INVALID_POLICY_TRANSITION');
    actingAs($this->manager)->get("/policies/{$this->policyId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('actions.endorse_details', false));
    expect(($this->in)(fn (): int => DB::table('policy_transactions')->where('policy_id', $this->policyId)->whereNotNull('endorsement_kind')->count()))->toBe(0);
});
