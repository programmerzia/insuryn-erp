<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Application\Periods\FiscalPeriodService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Gap fixes W7 (GA-04 remainder): W4 showed the approver what a manual journal or a reversal posts; claim payment approvals, claim payment releases and period
 * reopening still showed only a title and an amount. They now show their facts and the journal lines the final approval posts (reopening posts none, and
 * says so). And a manual journal under an approval policy offers Approve and Reject on its own page to whoever holds the current step — through the approval
 * engine, with the same checks and preview as the inbox.
 */
beforeEach(function (): void {
    Queue::fake();
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-14 10:00'));
    $this->ctx = seedDemoTenant();
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->preview = [...$this->headers, 'X-Journal-Preview' => '1', 'Accept' => 'application/json'];
    $this->user = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
});

it('shows the claim, payee, date and the lines a claim payment approval and its release post', function (): void {
    $world = seedInsuranceWorld($this->ctx);
    approvalPolicy($this->ctx['tenant_id'], 'claim_payment', ['min_amount_minor' => 1_000_000], [['permission' => 'claim.approve']]);
    approvalPolicy($this->ctx['tenant_id'], 'claim_payment_release', ['min_amount_minor' => 1_000_000], [['permission' => 'claim.pay_release']]);
    $officer = ($this->user)(['claim.register', 'claim.reserve']);
    $manager = ($this->user)(['claim.approve', 'claim.pay_request']);
    $checker = ($this->user)(['claim.approve', 'claim.pay_release']);
    $releaser = ($this->user)(['claim.pay_release']);
    [$claimId, $paymentId] = asTenant($this->ctx['tenant_id'], function () use ($world, $officer, $manager): array {
        $d = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $world['product_id'], $world['policyholder_id'], $world['agent_id'],
            $d('2026-09-01'), 12_000_000, 'BDT', 1), $world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, $d('2026-09-01'), $world['admin']);
        $claim = app(ClaimService::class)->register($policy->id, $d('2026-09-05'), 'collision', $officer->id, $d('2026-09-06'));
        app(ClaimService::class)->reserve($claim->id, 3_000_000, 'initial', $officer->id, $d('2026-09-06'));
        $payment = app(ClaimPaymentService::class)->approve($claim->id, 2_000_000, $world['policyholder_id'], $manager->id, $d('2026-09-12'));

        return [$claim->id, $payment->id];
    });
    $number = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('claims')->where('id', $claimId)->value('number'));

    actingAs($checker)->get('/approvals', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('approvals.0.object_type', 'claim_payment')->where('approvals.0.final_step', true)
        ->where('approvals.0.preview.link_label', 'Open the claim')->where('approvals.0.preview.posts_on_final_step', true)
        ->where('approvals.0.preview.details', [['label' => 'Claim', 'value' => $number], ['label' => 'Payee', 'value' => 'Rahima Akter'], ['label' => 'Approval date', 'value' => '2026-09-12', 'date' => true]])
        ->where('approvals.0.preview.lines', [['account' => '2200', 'name' => 'Outstanding Claims Reserve', 'debit' => '20,000.00', 'credit' => null],
            ['account' => '2210', 'name' => 'Claims Payable', 'debit' => null, 'credit' => '20,000.00']]));
    $approvalId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('approvals')->where('object_id', $paymentId)->where('status', 'pending')->value('id'));
    actingAs($checker)->post("/approvals/{$approvalId}/decide", ['decision' => 'approved'], $this->headers)->assertSessionHasNoErrors();

    asTenant($this->ctx['tenant_id'], function () use ($paymentId, $manager, $checker): void {
        app(ClaimPaymentService::class)->requestRelease($paymentId, $manager->id, null);
        app(ClaimPaymentService::class)->release($paymentId, $checker->id, CarbonImmutable::parse('2026-09-13'));
    });
    actingAs($releaser)->get('/approvals', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('approvals.0.object_type', 'claim_payment_release')
        ->where('approvals.0.preview.details.2', ['label' => 'Paid on', 'value' => '2026-09-13', 'date' => true])
        ->where('approvals.0.preview.lines', [['account' => '2210', 'name' => 'Claims Payable', 'debit' => '20,000.00', 'credit' => null],
            ['account' => '1010', 'name' => 'Bank - Main', 'debit' => null, 'credit' => '20,000.00']]));
});

it('shows what reopening a month does: no lines, the month, its status, what is posted in it and the reason', function (): void {
    approvalPolicy($this->ctx['tenant_id'], 'fiscal_period_reopen', [], [['permission' => 'periods.reopen']]);
    $requester = ($this->user)(['periods.reopen']);
    $approver = ($this->user)(['periods.reopen']);
    $periodId = asTenant($this->ctx['tenant_id'], function () use ($requester): string {
        $periodId = (string) DB::table('fiscal_periods')->where('starts', '2026-08-01')->value('id');
        DB::table('fiscal_periods')->where('id', $periodId)->update(['status' => 'locked']);
        app(FiscalPeriodService::class)->reopen($periodId, $requester->id, 'Late surveyor invoice');

        return $periodId;
    });

    actingAs($approver)->get('/approvals', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('approvals.0.object_type', 'fiscal_period_reopen')->where('approvals.0.final_step', true)
        ->where('approvals.0.preview.lines', [])->where('approvals.0.preview.posts_on_final_step', false)
        ->where('approvals.0.preview.details', [['label' => 'Month', 'value' => 'August 2026'], ['label' => 'Status now', 'value' => 'Locked'], ['label' => 'Posted in it', 'value' => '0 journals'],
            ['label' => 'Close', 'value' => 'Not signed off'], ['label' => 'Reason', 'value' => 'Late surveyor invoice'],
            ['label' => 'Posts', 'value' => 'Nothing: reopening lets journals post into this month again']]));
    expect(asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('fiscal_periods')->where('id', $periodId)->value('status')))->toBe('locked');
});

it('offers Approve and Reject on the journal page to the holder of the current step only, deciding through the approval engine', function (): void {
    approvalPolicy($this->ctx['tenant_id'], 'journal', ['min_amount_minor' => 1_000_000], [['permission' => 'accounting.approve_journal'], ['permission' => 'periods.reopen']]);
    $maker = ($this->user)(['accounting.create_manual_journal', 'accounting.view_journals']);
    $manager = ($this->user)(['accounting.approve_journal', 'accounting.view_journals']);
    $cfo = ($this->user)(['periods.reopen', 'accounting.approve_journal', 'accounting.view_journals']);
    $journalId = asTenant($this->ctx['tenant_id'], function () use ($maker): string {
        $journals = app(ManualJournalService::class);
        $journal = $journals->create(new ManualJournalRequest($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-14'), 'Office rent September', JournalKind::Manual, 'Rent for September', 'BDT', [
            new ManualJournalLine($this->ctx['accounts']['salary_expense'], Side::Debit, 3_500_000, ['branch' => $this->ctx['branch_id']]),
            new ManualJournalLine($this->ctx['accounts']['bank_main'], Side::Credit, 3_500_000, ['branch' => $this->ctx['branch_id']]),
        ]), $maker->id);
        $journals->submit($journal->id, $maker->id);

        return $journal->id;
    });
    $approvalId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('approvals')->where('object_id', $journalId)->value('id'));
    $page = fn (User $user) => actingAs($user)->get("/accounting/journals/{$journalId}", $this->headers)->assertOk();

    $page($maker)->assertInertia(fn (AssertableInertia $p) => $p->where('approval', ['id' => $approvalId, 'step' => 1, 'steps_total' => 2, 'may_decide' => false])->where('actions.approve', false));
    $page(($this->user)(['periods.reopen', 'accounting.view_journals']))->assertInertia(fn (AssertableInertia $p) => $p->where('approval.may_decide', false)); // step 1 needs accounting.approve_journal
    $page($manager)->assertInertia(fn (AssertableInertia $p) => $p->where('approval', ['id' => $approvalId, 'step' => 1, 'steps_total' => 2, 'may_decide' => true]));

    // Deciding on the journal page is the engine's decision: previewed, then recorded, and the user returns to the journal.
    actingAs($manager)->postJson("/approvals/{$approvalId}/decide", ['decision' => 'approved', 'return_to' => "/accounting/journals/{$journalId}"], $this->preview)->assertOk()->assertJsonPath('posts', false);
    actingAs($manager)->post("/approvals/{$approvalId}/decide", ['decision' => 'approved', 'return_to' => 'https://example.com/'], $this->headers)->assertSessionHasErrors('return_to');
    actingAs($manager)->post("/approvals/{$approvalId}/decide", ['decision' => 'approved', 'return_to' => "/accounting/journals/{$journalId}"], $this->headers)
        ->assertRedirect("/accounting/journals/{$journalId}")->assertSessionHas('status', 'Decision recorded; the approval is now pending.');

    $page($manager)->assertInertia(fn (AssertableInertia $p) => $p->where('approval', ['id' => $approvalId, 'step' => 2, 'steps_total' => 2, 'may_decide' => false]));
    $page($cfo)->assertInertia(fn (AssertableInertia $p) => $p->where('approval.may_decide', true));
    actingAs($cfo)->post("/approvals/{$approvalId}/decide", ['decision' => 'rejected', 'reason' => '', 'return_to' => "/accounting/journals/{$journalId}"], $this->headers)->assertSessionHasErrors();
    actingAs($cfo)->post("/approvals/{$approvalId}/decide", ['decision' => 'approved', 'return_to' => "/accounting/journals/{$journalId}"], $this->headers)->assertRedirect("/accounting/journals/{$journalId}");
    expect(asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('journals')->where('id', $journalId)->value('status')))->toBe('posted');
    $page($cfo)->assertInertia(fn (AssertableInertia $p) => $p->where('approval', null));
});
