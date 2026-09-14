<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Insurance\Product\Application\ProductCatalogue;
use App\Modules\Insurance\Quotation\Application\QuotationService;
use App\Modules\Insurance\Quotation\Application\QuotationTerms;
use App\Modules\Insurance\Rating\Application\RatingEngine;
use App\Modules\Insurance\Renewal\Application\ExpiryRegister;
use App\Modules\Insurance\Renewal\Application\RenewalRun;
use App\Modules\Insurance\Renewal\Infrastructure\Jobs\RenewalRunJob;
use App\Modules\Insurance\Underwriting\Application\ProposalService;
use App\Modules\Insurance\Underwriting\Application\UnderwritingLimits;
use App\Modules\Platform\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Phase 3 design §4 renewals and §6 expiry register queue (slice R9): the nightly run builds the expiry register by bucket (idempotently, per tenant), offers a
 * renewal quotation at T-45 on the tariff in force on the renewal date with claim-free years moved by the claims record, sends the renewal notice (with its PDF)
 * and reminders once per offset through the log-only channels; the renewal quotation becomes a proposal and the renewal policy (renewal_of_policy_id), the
 * expiring policy is renewed; a policy not renewed carries its reason; the expiry register and renewal conversion reports count them.
 * Golden motor risk (tests/Fixtures/rating/01): 2 claim-free years; policies sold on 15 Sep 2026 expire on 14 Sep 2027 (T-45 = 31 Jul 2027).
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
    ($this->in)(fn () => app(UnderwritingLimits::class)->set('branch_officer', 'motor', 200_000_000, CarbonImmutable::today(), $this->world['admin']));
    /** A rated motor policy sold through quotation → proposal → policy on $day (cover from that day), active. */
    $this->sell = function (string $day, string $registration, array $inputs = []): string {
        travelTo(CarbonImmutable::parse("{$day} 09:00"));

        return ($this->in)(function () use ($registration, $inputs): string {
            $quotations = app(QuotationService::class);
            $terms = new QuotationTerms($this->ctx['branch_id'], $this->world['motor_product_id'], $this->world['policyholder_id'], $this->world['agent_id'], CarbonImmutable::today(),
                [...$this->world['motor_inputs'], 'registration_no' => $registration, 'chassis_no' => "CH-{$registration}", ...$inputs], ['passenger_liability']);
            $quotation = $quotations->issue($quotations->saveDraft($terms, null, $this->officer->id)->id, CarbonImmutable::today(), $this->officer->id);
            $proposals = app(ProposalService::class);
            $proposal = $proposals->createFromQuotation($quotation->id, $this->officer->id);
            $proposals->verifyKyc($proposal->id, 'nid', '1990123456789', $this->officer->id);
            $proposals->submit($proposal->id, $this->officer->id);
            $policy = app(PolicyLifecycle::class)->issueFromProposal($proposal->id, CarbonImmutable::today(), $this->officer->id, 1, 'TRF 1');
            app(PolicyLifecycle::class)->activateDue(CarbonImmutable::today());

            return $policy->id;
        });
    };
    $this->runOn = function (string $day): array {
        travelTo(CarbonImmutable::parse("{$day} 00:30"));

        return ($this->in)(fn (): array => app(RenewalRun::class)->run(CarbonImmutable::today()));
    };
    $this->entry = fn (string $policyId): array => ($this->in)(fn (): array => (array) (DB::table('expiry_register')->where('policy_id', $policyId)->first() ?? []));
});

it('builds the expiry register by bucket for issued and active policies, idempotently, in every tenant', function (): void {
    $a = ($this->sell)('2026-09-15', 'DHK-A-1001');           // expires 2027-09-14
    $b = ($this->sell)('2026-10-01', 'DHK-B-1002');           // expires 2027-09-30
    $c = ($this->sell)('2026-11-20', 'DHK-C-1003');           // expires 2027-11-19: outside 60 days
    $other = seedDemoTenant('renewals-other');

    travelTo(CarbonImmutable::parse('2027-08-30 00:30'));
    (new RenewalRunJob())->handle(app(RenewalRun::class));

    $rows = ($this->in)(fn () => DB::table('expiry_register')->orderBy('expiry')->get(['policy_id', 'policy_number', 'bucket', 'days_left', 'status', 'rated', 'branch_id', 'agent_id', 'as_of']));
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->policy_id)->toBe($a)->and($rows[0]->days_left)->toBe(15)->and($rows[0]->bucket)->toBe(15)->and($rows[0]->rated)->toBeTrue()
        ->and($rows[0]->branch_id)->toBe($this->ctx['branch_id'])->and($rows[0]->agent_id)->toBe($this->world['agent_id'])->and($rows[0]->as_of)->toBe('2027-08-30')
        ->and($rows[1]->policy_id)->toBe($b)->and($rows[1]->days_left)->toBe(31)->and($rows[1]->bucket)->toBe(60)
        ->and(asTenant($other['tenant_id'], fn () => DB::table('expiry_register')->count()))->toBe(0)
        ->and(ExpiryRegister::bucketFor(7))->toBe(7)->and(ExpiryRegister::bucketFor(8))->toBe(15)->and(ExpiryRegister::bucketFor(60))->toBe(60)
        ->and(ExpiryRegister::bucketFor(61))->toBeNull()->and(ExpiryRegister::bucketFor(-1))->toBeNull();

    // Rerun the same day: nothing duplicated, nothing new sent; the next day the days left and buckets move.
    $before = ($this->in)(fn (): array => [DB::table('expiry_register')->count(), DB::table('quotations')->whereNotNull('renewal_of_policy_id')->count(), DB::table('notifications')->count()]);
    (new RenewalRunJob())->handle(app(RenewalRun::class));
    expect(($this->in)(fn (): array => [DB::table('expiry_register')->count(), DB::table('quotations')->whereNotNull('renewal_of_policy_id')->count(), DB::table('notifications')->count()]))->toBe($before)
        ->and($before)->toBe([2, 2, 4]);
    ($this->runOn)('2027-09-08');
    expect(($this->entry)($a))->toMatchArray(['days_left' => 6, 'bucket' => 7])
        ->and(($this->entry)($c))->toBe([]);
});

it('offers the renewal quotation at T-45 once, on the tariff in force on the renewal date, with claim-free years up after no claim and reset after a claim', function (): void {
    $clean = ($this->sell)('2026-09-15', 'DHK-N-2001');
    $claimed = ($this->sell)('2026-09-15', 'DHK-N-2002');
    travelTo(CarbonImmutable::parse('2027-01-10 10:00'));
    ($this->in)(fn () => app(ClaimService::class)->register($claimed, CarbonImmutable::parse('2027-01-05'), 'Rear collision', $this->world['admin'], CarbonImmutable::today()));
    // A newer tariff from 1 Jun 2027 supersedes the one the policies were sold on.
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/rating/01_motor_comprehensive.json')), true, 512, JSON_THROW_ON_ERROR);
    $plan = [...$fixture['plan'], 'version' => 2, 'effective_from' => '2027-06-01'];
    foreach ($plan['tables'] as &$table) {
        if ($table['code'] === 'motor_base') {
            $table['rows'] = array_map(fn (array $row): array => [...$row, 'value_bp' => $row['value_bp'] + 250], $table['rows']);
        }
    }
    unset($table);
    activeRatingPlan($this->ctx['tenant_id'], $plan, supersede: true);

    expect(($this->runOn)('2027-07-30')['quotations'])->toBe(0)          // 46 days: registered, not yet offered
        ->and(($this->entry)($clean))->toMatchArray(['status' => 'upcoming']);
    $run = ($this->runOn)('2027-07-31');                                    // 45 days
    ($this->runOn)('2027-07-31');                                           // rerun: still one quotation per policy

    ($this->in)(function () use ($run, $clean, $claimed): void {
        expect($run['quotations'])->toBe(2)->and(DB::table('quotations')->whereNotNull('renewal_of_policy_id')->count())->toBe(2);
        $version = app(ProductCatalogue::class)->versionOn($this->world['motor_product_id'], CarbonImmutable::parse('2027-09-15'));
        foreach ([$clean => 3, $claimed => 0] as $policyId => $claimFree) {
            $entry = DB::table('expiry_register')->where('policy_id', $policyId)->sole();
            $quotation = DB::table('quotations')->where('id', $entry->renewal_quotation_id)->sole();
            $risk = json_decode((string) $quotation->risk_inputs, true);
            $expected = app(RatingEngine::class)->rate($version, $risk, CarbonImmutable::parse('2027-09-15'), ['passenger_liability']);
            expect($entry->status)->toBe('renewal_offered')
                ->and($quotation->renewal_of_policy_id)->toBe($policyId)->and($quotation->status)->toBe('issued')
                ->and($quotation->inception)->toBe('2027-09-15')->and($quotation->valid_until)->toBe('2027-09-14')
                ->and($quotation->rating_plan_version)->toBe(2)->and($risk['ncb_years'])->toBe($claimFree)
                ->and($risk['registration_no'])->toBe(json_decode((string) DB::table('policies')->where('id', $policyId)->value('risk_inputs'), true)['registration_no'])
                ->and((int) $quotation->gross_premium_minor)->toBe($expected->grossPremiumMinor)
                ->and($quotation->customer_party_id)->toBe($this->world['policyholder_id'])->and($quotation->producer_id)->toBe($this->world['agent_id'])
                ->and($quotation->created_by)->toBeNull()
                ->and(DB::table('audit_events')->where('object_id', $quotation->id)->where('action', 'quotation.issued')->value('actor_type'))->toBe('system');
        }
        // Claim-free years change the premium: the claimed policy's renewal loses its no-claim bonus.
        $premiums = DB::table('quotations')->whereNotNull('renewal_of_policy_id')->pluck('gross_premium_minor', 'renewal_of_policy_id');
        expect((int) $premiums[$claimed])->toBeGreaterThan((int) $premiums[$clean]);
    });
});

it('keeps policies without a rating plan in the register without an automatic quotation, and renews them the Phase 1 way', function (): void {
    $policyId = ($this->in)(function (): string {
        $quote = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'], $this->world['policyholder_id'],
            $this->world['agent_id'], CarbonImmutable::parse('2026-09-15'), 12_000_000, 'BDT'), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($quote->id, CarbonImmutable::today(), $this->world['admin']);
        app(PolicyLifecycle::class)->activateDue(CarbonImmutable::today());

        return $quote->id;
    });
    $rated = ($this->sell)('2026-09-15', 'DHK-R-3001');
    ($this->runOn)('2027-08-15');

    expect(($this->entry)($policyId))->toMatchArray(['rated' => false, 'status' => 'upcoming', 'renewal_quotation_id' => null])
        ->and(($this->in)(fn () => DB::table('renewal_notices')->where('policy_id', $policyId)->count()))->toBe(0);
    $refusal = thrownBy(fn () => ($this->in)(fn () => app(PolicyLifecycle::class)->renew($rated, $this->world['admin'])), BusinessRuleViolation::class);
    expect($refusal->reasonCode)->toBe('RENEWAL_BY_QUOTATION');

    $renewal = ($this->in)(fn () => app(PolicyLifecycle::class)->renew($policyId, $this->world['admin']));
    ($this->runOn)('2027-08-16');
    expect(($this->entry)($policyId))->toMatchArray(['status' => 'upcoming']);             // the renewal is still a quote
    ($this->in)(fn () => app(PolicyLifecycle::class)->issue($renewal->id, CarbonImmutable::today(), $this->world['admin']));
    ($this->runOn)('2027-08-17');
    expect(($this->entry)($policyId))->toMatchArray(['status' => 'renewed', 'renewal_policy_id' => $renewal->id]);
});

it('sends the renewal notice with its document at T-45 and one reminder per offset through the log channels, never twice', function (): void {
    $policyId = ($this->sell)('2026-09-15', 'DHK-M-4001');
    $number = ($this->in)(fn (): string => (string) DB::table('policies')->where('id', $policyId)->value('number'));

    ($this->runOn)('2027-07-31');
    ($this->runOn)('2027-07-31');
    $notices = fn (): array => ($this->in)(fn (): array => DB::table('renewal_notices')->where('policy_id', $policyId)->orderBy('offset_days', 'desc')->get()->map(fn (object $n): array => (array) $n)->all());
    [$notice] = $notices();
    ($this->in)(function () use ($notice, $policyId, $number): void {
        $quotation = DB::table('quotations')->where('renewal_of_policy_id', $policyId)->sole();
        expect($notice['kind'])->toBe('notice')->and($notice['offset_days'])->toBe(45)->and($notice['quotation_id'])->toBe($quotation->id)->and($notice['generated_document_id'])->not->toBeNull()
            ->and(DB::table('generated_documents')->where('id', $notice['generated_document_id'])->sole(['template_code', 'object_type', 'rendered_by']))
            ->toEqual((object) ['template_code' => 'renewal_notice', 'object_type' => 'expiry_register', 'rendered_by' => null])
            ->and(DB::table('stored_documents')->where('id', $notice['stored_document_id'])->value('object_id'))->toBe($policyId)
            ->and(DB::table('notifications')->where('subject_id', $policyId)->orderBy('channel')->get(['channel', 'adapter', 'status', 'recipient_id', 'template_code', 'stored_document_id'])->map(fn ($n): array => (array) $n)->all())
            ->toBe([['channel' => 'email', 'adapter' => 'log', 'status' => 'sent', 'recipient_id' => $this->world['policyholder_id'], 'template_code' => 'renewal_notice', 'stored_document_id' => $notice['stored_document_id']],
                ['channel' => 'sms', 'adapter' => 'log', 'status' => 'sent', 'recipient_id' => $this->world['policyholder_id'], 'template_code' => 'renewal_notice', 'stored_document_id' => $notice['stored_document_id']]])
            ->and(json_decode((string) $notice['notification_ids'], true))->toHaveCount(2)
            ->and(DB::table('notifications')->where('subject_id', $policyId)->value('title'))->toBe("Renewal notice: policy {$number} expires on 14 Sep 2027");
        $gross = \App\Modules\Platform\Money\MinorUnits::format((int) $quotation->gross_premium_minor, 'BDT');
        expect((string) $this->pages[count($this->pages) - 1])->toContain('Renewal notice')->toContain($number)->toContain('Renew by')->toContain('14 Sep 2027')
            ->toContain('Renewal premium')->toContain($gross)->toContain('15 Sep 2027')->toContain((string) $quotation->number);
    });

    ($this->runOn)('2027-08-15');   // 30 days
    ($this->runOn)('2027-08-15');
    ($this->runOn)('2027-09-05');   // 9 days: the 15-day reminder
    ($this->runOn)('2027-09-07');   // 7 days
    ($this->runOn)('2027-09-08');
    $all = $notices();
    expect(array_map(fn (array $n): array => [$n['offset_days'], $n['kind'], $n['sent_on']], $all))->toBe([[45, 'notice', '2027-07-31'], [30, 'reminder', '2027-08-15'], [15, 'reminder', '2027-09-05'], [7, 'reminder', '2027-09-07']])
        ->and(array_unique(array_map(fn (array $n): mixed => $n['stored_document_id'], $all)))->toHaveCount(1)
        ->and(($this->in)(fn () => DB::table('generated_documents')->where('template_code', 'renewal_notice')->count()))->toBe(1)
        ->and(($this->in)(fn () => DB::table('notifications')->where('subject_id', $policyId)->count()))->toBe(8);
});

it('renews end to end: renewal quotation → proposal → policy with renewal_of_policy_id, the expiring policy renewed and the register row renewed', function (): void {
    $policyId = ($this->sell)('2026-09-15', 'DHK-E-5001');
    ($this->runOn)('2027-07-31');
    $quotationId = ($this->in)(fn (): string => (string) DB::table('quotations')->where('renewal_of_policy_id', $policyId)->value('id'));

    travelTo(CarbonImmutable::parse('2027-08-02 10:00'));
    $renewal = ($this->in)(function () use ($quotationId) {
        $proposals = app(ProposalService::class);
        $proposal = $proposals->createFromQuotation($quotationId, $this->officer->id);
        $proposals->verifyKyc($proposal->id, 'nid', '1990123456789', $this->officer->id);
        $submitted = $proposals->submit($proposal->id, $this->officer->id);
        // Not a duplicate of the policy it renews, and not new business.
        expect($submitted->status->value)->toBe('approved')->and($submitted->referral_reasons)->toBe([]);

        return app(PolicyLifecycle::class)->issueFromProposal($proposal->id, CarbonImmutable::today(), $this->officer->id, 1, 'TRF 9');
    });

    ($this->in)(function () use ($renewal, $policyId, $quotationId): void {
        expect($renewal->renewal_of_policy_id)->toBe($policyId)->and($renewal->inception->toDateString())->toBe('2027-09-15')->and($renewal->expiry->toDateString())->toBe('2028-09-14')
            ->and($renewal->quotation_id)->toBe($quotationId)
            ->and(DB::table('policies')->where('id', $policyId)->value('status'))->toBe('renewed')
            ->and(DB::table('expiry_register')->where('policy_id', $policyId)->sole(['status', 'renewal_policy_id', 'renewal_quotation_id']))
            ->toEqual((object) ['status' => 'renewed', 'renewal_policy_id' => $renewal->id, 'renewal_quotation_id' => $quotationId])
            ->and(json_decode((string) DB::table('audit_events')->where('object_id', $policyId)->where('action', 'policy.renewed')->value('after'), true))
            ->toMatchArray(['status' => 'renewed', 'renewal_policy_id' => $renewal->id]);
    });
    // The renewal gets no reminders once renewed, and a second renewal of the same policy is refused.
    ($this->runOn)('2027-08-15');
    expect(($this->in)(fn () => DB::table('renewal_notices')->where('policy_id', $policyId)->count()))->toBe(1)
        ->and(($this->entry)($policyId))->toMatchArray(['status' => 'renewed']);
    $refusal = thrownBy(fn () => ($this->in)(fn () => app(QuotationService::class)->offerRenewal(new \App\Modules\Insurance\Quotation\Application\RenewalQuotationTerms($policyId,
        $this->ctx['branch_id'], $this->world['motor_product_id'], $this->world['policyholder_id'], null, CarbonImmutable::parse('2027-09-15'), $this->world['motor_inputs'], [],
        CarbonImmutable::parse('2027-09-14')), CarbonImmutable::today(), null)), BusinessRuleViolation::class);
    expect($refusal->reasonCode)->toBe('RENEWAL_BASE_NOT_RENEWABLE');
});

it('refuses to issue a renewal for a policy that is no longer renewable', function (): void {
    $policyId = ($this->sell)('2026-09-15', 'DHK-X-5002');
    ($this->runOn)('2027-07-31');
    $quotationId = ($this->in)(fn (): string => (string) DB::table('quotations')->where('renewal_of_policy_id', $policyId)->value('id'));
    travelTo(CarbonImmutable::parse('2027-08-02 10:00'));
    $proposalId = ($this->in)(function () use ($quotationId): string {
        $proposals = app(ProposalService::class);
        $proposal = $proposals->createFromQuotation($quotationId, $this->officer->id);
        $proposals->verifyKyc($proposal->id, 'nid', '1990123456789', $this->officer->id);
        $proposals->submit($proposal->id, $this->officer->id);

        return $proposal->id;
    });
    ($this->in)(fn () => app(PolicyLifecycle::class)->lapse($policyId, 'Premium unpaid', $this->world['admin']));

    $refusal = thrownBy(fn () => ($this->in)(fn () => app(PolicyLifecycle::class)->issueFromProposal($proposalId, CarbonImmutable::today(), $this->officer->id, 1, 'TRF 9')), BusinessRuleViolation::class);
    expect($refusal->reasonCode)->toBe('RENEWAL_BASE_NOT_RENEWABLE')
        ->and(($this->in)(fn () => DB::table('policies')->where('renewal_of_policy_id', $policyId)->count()))->toBe(0);
    ($this->runOn)('2027-08-03');
    expect(($this->entry)($policyId))->toMatchArray(['status' => 'lapsed', 'reason' => 'policy_lapsed']);
});

it('records why a policy is not renewed, declines its renewal quotation, and lapses unrenewed policies after expiry', function (): void {
    $lost = ($this->sell)('2026-09-15', 'DHK-L-6001');
    $silent = ($this->sell)('2026-09-15', 'DHK-L-6002');
    ($this->runOn)('2027-07-31');
    $entry = ($this->entry)($lost);

    actingAs($this->officer)->post("/renewals/{$entry['id']}/not-renewed", ['reason' => 'other'], $this->headers)->assertSessionHasErrors('form');
    actingAs($this->officer)->post("/renewals/{$entry['id']}/not-renewed", ['reason' => 'cheaper'], $this->headers)->assertSessionHasErrors('form');
    actingAs($this->officer)->post("/renewals/{$entry['id']}/not-renewed", ['reason' => 'moved_to_competitor', 'note' => 'Green Delta offered less'], $this->headers)
        ->assertSessionHasNoErrors()->assertRedirect('/renewals');
    actingAs($this->officer)->post("/renewals/{$entry['id']}/not-renewed", ['reason' => 'price'], $this->headers)->assertSessionHasErrors('form');

    ($this->in)(function () use ($lost, $entry): void {
        expect(DB::table('expiry_register')->where('id', $entry['id'])->sole(['status', 'reason', 'reason_note', 'closed_by']))
            ->toEqual((object) ['status' => 'not_renewed', 'reason' => 'moved_to_competitor', 'reason_note' => 'Green Delta offered less', 'closed_by' => $this->officer->id])
            ->and(DB::table('quotations')->where('id', $entry['renewal_quotation_id'])->sole(['status', 'decline_reason']))
            ->toEqual((object) ['status' => 'declined', 'decline_reason' => 'Not renewed: Moved to a competitor — Green Delta offered less'])
            ->and(DB::table('audit_events')->where('object_id', $lost)->where('action', 'renewal.not_renewed')->value('permission'))->toBe('renewal.manage');
    });
    ($this->runOn)('2027-08-15');
    expect(($this->in)(fn () => DB::table('renewal_notices')->where('policy_id', $lost)->count()))->toBe(1);   // no reminder once not renewed

    ($this->runOn)('2027-09-15');   // the day after expiry
    expect(($this->entry)($silent))->toMatchArray(['status' => 'lapsed', 'reason' => 'no_response'])
        ->and(($this->entry)($lost))->toMatchArray(['status' => 'not_renewed']);
    // A lapsed row can still get the reason a person learns later.
    actingAs($this->officer)->post('/renewals/'.($this->entry)($silent)['id'].'/not-renewed', ['reason' => 'sold_asset'], $this->headers)->assertSessionHasNoErrors();
    expect(($this->entry)($silent))->toMatchArray(['status' => 'not_renewed', 'reason' => 'sold_asset']);
});

it('reports the expiry register as at a date and renewal conversion by branch and producer with reasons, in integers and basis points', function (): void {
    $renewed = ($this->sell)('2026-09-15', 'DHK-P-7001');   // expires 2027-09-14, renewed
    $lost = ($this->sell)('2026-09-15', 'DHK-P-7002');      // expires 2027-09-14, not renewed: price
    $open = ($this->sell)('2026-10-01', 'DHK-P-7003');      // expires 2027-09-30, still open
    ($this->runOn)('2027-07-31');
    ($this->in)(function () use ($renewed, $lost): void {
        $quotationId = (string) DB::table('quotations')->where('renewal_of_policy_id', $renewed)->value('id');
        $proposals = app(ProposalService::class);
        $proposal = $proposals->createFromQuotation($quotationId, $this->officer->id);
        $proposals->verifyKyc($proposal->id, 'nid', '1990123456789', $this->officer->id);
        $proposals->submit($proposal->id, $this->officer->id);
        app(PolicyLifecycle::class)->issueFromProposal($proposal->id, CarbonImmutable::today(), $this->officer->id, 1, 'TRF 9');
        app(ExpiryRegister::class)->recordNotRenewed((string) DB::table('expiry_register')->where('policy_id', $lost)->value('id'), 'price', null, $this->officer->id);
    });
    $numbers = ($this->in)(fn (): array => DB::table('policies')->whereIn('id', [$renewed, $lost, $open])->pluck('number', 'id')->all());
    $gross = ($this->in)(fn (): int => (int) DB::table('policies')->where('id', $renewed)->value('gross_premium_minor'));
    $reader = ($this->person)('Farid Finance', ['finance_manager']);

    actingAs($reader)->get('/reports', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reports', fn (Collection $reports): bool => $reports->pluck('key')->intersect(['expiry-register', 'renewal-conversion'])->count() === 2));
    actingAs($reader)->get('/reports/expiry-register?as_of=2027-08-20', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('reports/Show')
        ->where('title', 'Expiry register')->where('filter', 'as_of')
        ->where('rows', fn (Collection $rows): bool => $rows->map(fn ($r): array => [$r['cells']['policy_number'], $r['cells']['days_left'], $r['cells']['bucket'], $r['cells']['status'], $r['link']])
            ->sortBy(fn (array $r): string => $r[0])->values()->all() === collect([
                [$numbers[$renewed], 25, 30, 'renewed', "/policies/{$renewed}"], [$numbers[$lost], 25, 30, 'not renewed', "/policies/{$lost}"], [$numbers[$open], 41, 60, 'upcoming', "/policies/{$open}"],   // not in the register yet on 31 Jul (61 days)
            ])->sortBy(fn (array $r): string => $r[0])->values()->all())
        ->where('totals.policies', '3 policies')->where('totals.gross', \App\Modules\Platform\Money\MinorUnits::format(3 * $gross, 'BDT'))
        ->where('summaries.0.rows', fn (Collection $rows): bool => $rows->map(fn ($r): array => [$r['cells']['group'], $r['cells']['policies']])->all() === [['30 days', 2], ['60 days', 1]])
        ->where('summaries.1.rows.0.cells', fn ($cells): bool => $cells['group'] === 'HO' && $cells['policies'] === 3)
        ->where('summaries.2.rows.0.cells', fn ($cells): bool => $cells['group'] === 'AG-001' && $cells['policies'] === 3));

    actingAs($reader)->get('/reports/renewal-conversion?from=2027-09-01&to=2027-09-30', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('title', 'Renewal conversion by branch')->where('filters.by', 'branch')
        ->where('totals', ['expiring' => '3 policies', 'renewed' => '1 policies', 'not_renewed' => '1 policies', 'conversion' => '33.33%'])
        ->where('summaries.0.rows', [['cells' => ['group' => 'HO', 'expiring' => 3, 'renewed' => 1, 'not_renewed' => 1, 'open' => 1, 'conversion' => '33.33%'], 'link' => null]])
        ->where('summaries.1.rows', [['cells' => ['reason' => 'Price', 'policies' => 1], 'link' => null]])
        ->where('rows', fn (Collection $rows): bool => $rows->firstWhere('cells.policy_number', $numbers[$renewed])['links']['renewal_policy_number'] !== null
            && $rows->firstWhere('cells.policy_number', $numbers[$lost])['cells']['reason'] === 'Price'));
    actingAs($reader)->get('/reports/renewal-conversion?from=2027-09-01&to=2027-09-30&by=agent', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('summaries.0.rows.0.cells.group', 'AG-001')->where('summaries.0.rows.0.cells.conversion', '33.33%'));
    expect(\App\Modules\Insurance\Reports\Application\RenewalConversionQuery::conversionBp(2, 3))->toBe(6667)
        ->and(\App\Modules\Insurance\Reports\Application\RenewalConversionQuery::conversionBp(1, 8))->toBe(1250)
        ->and(\App\Modules\Insurance\Reports\Application\RenewalConversionQuery::conversionBp(0, 0))->toBeNull();
    actingAs($this->officer)->get('/reports/renewal-conversion', $this->headers)->assertForbidden();
});

it('shows the expiry register queue to renewal.manage holders with its actions, and offers a renewal quotation from it', function (): void {
    $policyId = ($this->sell)('2026-09-15', 'DHK-Q-8001');
    ($this->runOn)('2027-07-20');                                    // 56 days: in the 60-day bucket, not yet offered
    travelTo(CarbonImmutable::parse('2027-07-20 10:00'));
    $entryId = (string) ($this->entry)($policyId)['id'];

    actingAs($this->officer)->get('/renewals', $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('renewals/Index')
        ->where('buckets', [7, 15, 30, 60])->where('quoteDaysBefore', 45)->where('filters', ['bucket' => null, 'status' => null, 'branch' => null, 'producer' => null])
        ->where('reasons.0', ['value' => 'price', 'label' => 'Price'])
        ->where('entries.0.id', $entryId)->where('entries.0.policy_id', $policyId)->where('entries.0.days_left', 56)->where('entries.0.bucket', 60)->where('entries.0.status', 'upcoming')
        ->where('entries.0.quotation', null)->where('entries.0.notices', [])->where('entries.0.can_offer', true)->where('entries.0.can_record', true)
        ->where('entries.0.producer', 'AG-001 Jamal Agent')->where('entries.0.branch', 'HO'));
    actingAs($this->officer)->get('/renewals?bucket=7', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('entries', []));
    actingAs($this->officer)->get('/renewals?status=upcoming&branch='.$this->ctx['branch_id'], $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('entries.0.id', $entryId));

    $response = actingAs($this->officer)->post("/renewals/{$entryId}/quote", [], $this->headers)->assertSessionHasNoErrors();
    $quotation = ($this->in)(fn () => DB::table('quotations')->where('renewal_of_policy_id', $policyId)->sole(['id', 'number', 'created_by', 'valid_until']));
    $response->assertRedirect("/quotations/{$quotation->id}")->assertSessionHas('status', "Renewal quotation {$quotation->number} offered.");
    expect($quotation->created_by)->toBe($this->officer->id)->and($quotation->valid_until)->toBe('2027-09-14')
        ->and(($this->entry)($policyId))->toMatchArray(['status' => 'renewal_offered']);
    actingAs($this->officer)->get("/quotations/{$quotation->id}", $this->headers)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('quotation.renewal_of.id', $policyId)->where('can.convert', true));
    actingAs($this->officer)->post("/renewals/{$entryId}/quote", [], $this->headers)->assertSessionHasErrors('form');
    actingAs($this->officer)->get('/renewals', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('entries.0.quotation.number', $quotation->number)->where('entries.0.can_offer', false));
    // The nightly run at T-45 does not offer a second one; the notice goes out for the quotation already offered.
    ($this->runOn)('2027-07-31');
    expect(($this->in)(fn () => DB::table('quotations')->where('renewal_of_policy_id', $policyId)->count()))->toBe(1)
        ->and(($this->in)(fn () => DB::table('renewal_notices')->where('policy_id', $policyId)->value('quotation_id')))->toBe($quotation->id);
    travelTo(CarbonImmutable::parse('2027-07-31 10:00'));
    actingAs($this->officer)->get('/renewals', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('entries.0.notices.0.kind', 'notice')->where('entries.0.notices.0.document_url', fn (string $url): bool => str_starts_with($url, "/policies/{$policyId}/documents/")));

    $accountant = ($this->person)('Ayesha Accountant', ['accountant']);
    actingAs($accountant)->get('/renewals', $this->headers)->assertForbidden();
    actingAs($accountant)->post("/renewals/{$entryId}/not-renewed", ['reason' => 'price'], $this->headers)->assertSessionHasErrors('form');
    ($this->in)(function (): void {
        $holders = DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')->where('rp.permission_code', 'renewal.manage')
            ->where('r.code', 'not like', 'test-%')->orderBy('r.code')->pluck('r.code')->all();
        expect($holders)->toBe(['branch_manager', 'branch_officer']);
    });
});

