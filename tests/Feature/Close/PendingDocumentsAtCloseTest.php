<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\Close\PendingDocumentsQuery;
use App\Modules\Accounting\Application\Close\PeriodCloseService;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Application\Periods\FiscalPeriodService;
use App\Modules\Accounting\Application\Queries\FiscalPeriodQuery;
use App\Modules\Accounting\Application\Reversals\ReversalRequestService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Accounting\Exceptions\ManualJournalException;
use App\Modules\Accounting\Exceptions\PeriodTransitionException;
use App\Modules\Insurance\Claims\Application\ClaimPaymentService;
use App\Modules\Insurance\Claims\Application\ClaimService;
use App\Modules\Insurance\Collections\Application\AllocationLine;
use App\Modules\Insurance\Collections\Application\ReceiptService;
use App\Modules\Insurance\Collections\Application\RecordReceiptRequest;
use App\Modules\Insurance\Policy\Application\PolicyLifecycle;
use App\Modules\Insurance\Policy\Application\QuoteRequest;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Authorization\SodViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * The first listed pending document of a type.
 *
 * @param array<int, array<string, mixed>> $listed
 * @return array<string, mixed>
 */
function firstPending(array $listed, string $type): array
{
    foreach ($listed as $document) {
        if ($document['type'] === $type) {
            return $document;
        }
    }

    throw new LogicException("No pending {$type}.");
}

/**
 * Slice 2.1b (DECISION D-55, CQ-C4): the close lists every document dated in the period still waiting for approval, release or posting — manual
 * journals and reversals awaiting approval, claim payments not yet paid, refunds not yet released, accounting events not posted. A soft lock goes
 * ahead with them as a warning; the lock is refused (PERIOD_HAS_PENDING_DOCUMENTS, with the list) until each is approved, rejected or, for a manual
 * journal, moved to the next open period by its approver. No override.
 */
beforeEach(function (): void {
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-10-02 10:00')); // September has ended (slice 2.1b early-lock rule)
    $this->ctx = seedDemoTenant();
    $this->world = seedInsuranceWorld($this->ctx, 'monthly');
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->period = fn (string $starts): string => asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('fiscal_periods')->where('starts', $starts)->value('id'));
    $this->september = ($this->period)('2026-09-01');
    $this->maker = userWithPermissions($this->ctx['tenant_id'], ['accounting.create_manual_journal', 'accounting.approve_journal', 'accounting.view_journals', 'accounting.reverse_journal']);
    $this->approver = userWithPermissions($this->ctx['tenant_id'], ['accounting.approve_journal', 'accounting.view_journals', 'periods.soft_lock', 'periods.lock']);
    $this->pending = fn (string $periodId): array => asTenant($this->ctx['tenant_id'], fn (): array => array_map(fn ($d): array => $d->toArray(),
        app(PendingDocumentsQuery::class)->forPeriod(app(FiscalPeriodQuery::class)->find($periodId) ?? throw new LogicException('no period'))));
    $this->journal = function (string $on, string $description): string {
        return asTenant($this->ctx['tenant_id'], function () use ($on, $description): string {
            $journals = app(ManualJournalService::class);
            $journal = $journals->create(new ManualJournalRequest($this->ctx['entity_id'], CarbonImmutable::parse($on), $description, JournalKind::Manual, $description, 'BDT', [
                new ManualJournalLine($this->ctx['accounts']['salary_expense'], Side::Debit, 2_500_000, ['branch' => $this->ctx['branch_id']]),
                new ManualJournalLine($this->ctx['accounts']['bank_main'], Side::Credit, 2_500_000, ['branch' => $this->ctx['branch_id']]),
            ]), $this->maker);
            $journals->submit($journal->id, $this->maker);

            return $journal->id;
        });
    };
    $this->policy = fn (): string => asTenant($this->ctx['tenant_id'], function (): string {
        $policy = app(PolicyLifecycle::class)->quote(new QuoteRequest($this->ctx['entity_id'], $this->ctx['branch_id'], $this->world['product_id'],
            $this->world['policyholder_id'], null, CarbonImmutable::parse('2026-07-01'), 12_000_000, 'BDT', 1), $this->world['admin']);
        app(PolicyLifecycle::class)->issue($policy->id, CarbonImmutable::parse('2026-07-01'), $this->world['admin']);

        return $policy->id;
    });
});

it('lists every kind of document dated in the period that still waits, and nothing settled or dated elsewhere', function (): void {
    $policyId = ($this->policy)();
    $officer = userWithPermissions($this->ctx['tenant_id'], ['claim.register', 'claim.reserve']);
    $manager = userWithPermissions($this->ctx['tenant_id'], ['claim.approve', 'claim.pay_request']);
    $rent = ($this->journal)('2026-09-14', 'Office rent');
    ($this->journal)('2026-10-03', 'October rent');

    asTenant($this->ctx['tenant_id'], function () use ($policyId, $officer, $manager, $rent): void {
        // A posted journal of August reversed on 20 September, waiting for approval.
        $journals = app(ManualJournalService::class);
        $posted = $journals->create(new ManualJournalRequest($this->ctx['entity_id'], CarbonImmutable::parse('2026-08-10'), 'Stationery', JournalKind::Manual, 'Stationery', 'BDT', [
            new ManualJournalLine($this->ctx['accounts']['salary_expense'], Side::Debit, 10_000, ['branch' => $this->ctx['branch_id']]),
            new ManualJournalLine($this->ctx['accounts']['bank_main'], Side::Credit, 10_000, ['branch' => $this->ctx['branch_id']]),
        ]), $this->maker);
        $journals->submit($posted->id, $this->maker);
        $journals->approve($posted->id, $this->approver);
        $reversal = app(ReversalRequestService::class)->request($posted->id, CarbonImmutable::parse('2026-09-20'), 'Booked twice', $this->maker);

        // Claim payments: one waiting for approval (limit), one approved and not released, one dated in October.
        approvalPolicy($this->ctx['tenant_id'], 'claim_payment', ['min_amount_minor' => 1_000_000], [['permission' => 'periods.lock']]);
        $claims = app(ClaimService::class);
        $payments = app(ClaimPaymentService::class);
        $claim = $claims->register($policyId, CarbonImmutable::parse('2026-09-01'), 'Collision', $officer, CarbonImmutable::parse('2026-09-02'));
        $claims->reserve($claim->id, 5_000_000, 'Survey', $officer, CarbonImmutable::parse('2026-09-02'));
        $big = $payments->approve($claim->id, 1_500_000, $this->world['policyholder_id'], $manager, CarbonImmutable::parse('2026-09-15'));
        $small = $payments->approve($claim->id, 400_000, $this->world['policyholder_id'], $manager, CarbonImmutable::parse('2026-09-16'));
        $payments->approve($claim->id, 300_000, $this->world['policyholder_id'], $manager, CarbonImmutable::parse('2026-10-01'));

        // Refunds dated by the day they were requested in Dhaka: 30 Sep 17:00 UTC is still September, 30 Sep 19:00 UTC is 1 October.
        foreach (['2026-09-30 17:00:00+00' => 700_000, '2026-09-30 19:00:00+00' => 800_000] as $at => $amount) {
            DB::table('refunds')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->ctx['tenant_id'], 'entity_id' => $this->ctx['entity_id'], 'branch_id' => $this->ctx['branch_id'],
                'policy_id' => $policyId, 'party_id' => $this->world['policyholder_id'], 'amount_minor' => $amount, 'currency' => 'BDT', 'reason' => 'overpaid',
                'status' => 'requested', 'requested_by' => $this->world['admin'], 'requested_at' => $at]);
        }

        // An accounting event of September that failed, and one posted.
        $event = DB::table('accounting_events')->where('transaction_date', '2026-09-02')->where('status', 'posted')->orderBy('created_at')->first(['id', 'event_type']);
        DB::table('accounting_events')->where('id', $event?->id)->update(['status' => 'failed', 'failure_reason' => 'PERIOD_MISSING']);

        $listed = ($this->pending)($this->september);
        expect(array_map(fn (array $d): array => [$d['type'], $d['date'], $d['status']], $listed))->toBe([
            ['accounting_event', '2026-09-02', 'failed'],
            ['manual_journal', '2026-09-14', 'pending_approval'],
            ['claim_payment', '2026-09-15', 'pending_approval'],
            ['claim_payment', '2026-09-16', 'approved'],
            ['journal_reversal', '2026-09-20', 'pending_approval'],
            ['refund', '2026-09-30', 'requested'],
        ])
            ->and(firstPending($listed, 'manual_journal'))->toMatchArray(['id' => $rent, 'movable' => true, 'amount_minor' => 2_500_000, 'link' => "/accounting/journals/{$rent}"])
            ->and(firstPending($listed, 'journal_reversal'))->toMatchArray(['id' => $reversal, 'movable' => false])
            ->and(array_column(array_filter($listed, fn (array $d): bool => $d['type'] === 'claim_payment'), 'id'))->toBe([$big->id, $small->id])
            ->and(firstPending($listed, 'refund')['amount_minor'])->toBe(700_000)
            ->and(firstPending($listed, 'accounting_event')['label'])->toBe("Accounting event {$event?->event_type} (PERIOD_MISSING)")
            ->and(array_map(fn (array $d): string => $d['type'].' '.$d['date'], ($this->pending)(($this->period)('2026-10-01'))))
            ->toBe(['claim_payment 2026-10-01', 'refund 2026-10-01', 'manual_journal 2026-10-03']);
    });
});

it('soft-locks with a warning, refuses the lock with the list until each document is cleared, and moves a pending journal by its approver only', function (): void {
    $policyId = ($this->policy)();
    asTenant($this->ctx['tenant_id'], fn () => app(ReceiptService::class)->record(new RecordReceiptRequest($this->ctx['entity_id'], $this->ctx['branch_id'], null, 'cash', 3_000_000, 'BDT',
        CarbonImmutable::parse('2026-09-10'), null, 'r1', [new AllocationLine((string) DB::table('installments')->where('policy_id', $policyId)->value('id'), 3_000_000)]), $this->world['admin']));
    $rent = ($this->journal)('2026-09-14', 'Office rent');
    $close = app(PeriodCloseService::class);
    $finance = $this->world['admin'];

    $runId = asTenant($this->ctx['tenant_id'], function () use ($close, $finance): string {
        $runId = $close->start($this->september, $finance);
        foreach (DB::table('period_close_tasks')->where('close_run_id', $runId)->where('code', '<>', 'period_lock')->orderBy('order_no')->get(['id', 'code']) as $task) {
            $close->execute((string) $task->id, $finance, $task->code === 'accruals' ? 'None' : null);
        }
        $trialBalance = json_decode((string) DB::table('period_close_tasks')->where('close_run_id', $runId)->where('code', 'trial_balance')->value('result'), true);
        expect(DB::table('fiscal_periods')->where('id', $this->september)->value('status'))->toBe('soft_locked')
            ->and(DB::table('period_close_tasks')->where('close_run_id', $runId)->where('code', 'trial_balance')->value('status'))->toBe('done')
            ->and($trialBalance['details']['pending_documents'])->toBe(1)
            ->and($trialBalance['summary'])->toBe('Trial balance balances. Warning: 1 manual journal dated in the period still waiting; the lock waits for them.');

        return $runId;
    });
    $lockTask = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('period_close_tasks')->where('close_run_id', $runId)->where('code', 'period_lock')->value('id'));

    // The lock is refused with the list: through the service, the API and the checklist screen; nothing is marked done.
    asTenant($this->ctx['tenant_id'], function () use ($close, $finance, $lockTask, $rent): void {
        $refusal = thrownBy(fn () => $close->execute($lockTask, $finance), PeriodTransitionException::class);
        expect($refusal->reasonCode)->toBe('PERIOD_HAS_PENDING_DOCUMENTS')
            ->and($refusal->details['pending'][0])->toMatchArray(['type' => 'manual_journal', 'id' => $rent, 'date' => '2026-09-14'])
            ->and(DB::table('period_close_tasks')->where('id', $lockTask)->value('status'))->toBe('pending')
            ->and(DB::table('fiscal_periods')->where('id', $this->september)->value('status'))->toBe('soft_locked');
    });
    $admin = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $finance));
    actingAs($admin)->postJson("/api/accounting/close-tasks/{$lockTask}/execute", [], $this->headers + ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('reason', 'PERIOD_HAS_PENDING_DOCUMENTS')->assertJsonPath('details.pending.0.id', $rent);
    actingAs($admin)->from("/close/runs/{$runId}")->post("/close/tasks/{$lockTask}/execute", [], $this->headers)
        ->assertRedirect("/close/runs/{$runId}")->assertSessionHasErrors(['reason' => 'PERIOD_HAS_PENDING_DOCUMENTS']);
    actingAs($admin)->get("/close/runs/{$runId}", $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('close/Run')
        ->where('lock.ready', false)
        ->where('lock.reason', '1 document dated in this period is still waiting. Approve or reject it, or move a pending manual journal to the next period, before locking.')
        ->where('pending.0.id', $rent)->where('pending.0.can_move', true)->where('pending.0.amount', '25,000.00')->where('pending.0.cleared_by', 'Approve or reject it, or its approver moves it to the next open period.'));
    actingAs($admin)->get('/close', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('close/Index')
        ->where('periods.2.label', '2026-03')->where('periods.2.pending.0.id', $rent)->where('periods.1.pending', []));

    // Only its approver moves it: not the maker, not someone without the approval permission; the next period must be open.
    $outsider = userWithPermissions($this->ctx['tenant_id'], ['periods.lock']);
    asTenant($this->ctx['tenant_id'], function () use ($rent, $outsider): void {
        $journals = app(ManualJournalService::class);
        expect(fn () => $journals->moveToNextPeriod($rent, $this->maker))->toThrow(SodViolation::class)
            ->and(fn () => $journals->moveToNextPeriod($rent, $outsider))->toThrow(PermissionDenied::class);
        DB::table('fiscal_periods')->where('starts', '2026-10-01')->update(['status' => 'soft_locked']);
        expect(thrownBy(fn () => $journals->moveToNextPeriod($rent, $this->approver), ManualJournalException::class)->reasonCode)->toBe('NEXT_PERIOD_NOT_OPEN');
        DB::table('fiscal_periods')->where('starts', '2026-10-01')->update(['status' => 'open']);
        expect(DB::table('journals')->where('id', $rent)->value('transaction_date'))->toBe('2026-09-14');
    });
    $approver = asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail((string) $this->approver));
    actingAs($approver)->from("/close/runs/{$runId}")->post("/close/journals/{$rent}/move-to-next-period", ['reason' => 'Invoice arrives in October'], $this->headers)
        ->assertRedirect("/close/runs/{$runId}")->assertSessionHas('status', 'Journal moved to 1 Oct 2026; it waits for approval there.');

    asTenant($this->ctx['tenant_id'], function () use ($close, $finance, $lockTask, $rent): void {
        $journal = DB::table('journals')->where('id', $rent)->first(['transaction_date', 'posting_date', 'effective_date', 'period_id', 'status']);
        $audit = DB::table('audit_events')->where('object_id', $rent)->where('action', 'journal.moved_to_next_period')->first(['actor_user_id', 'before', 'after', 'reason', 'permission']);
        expect([(string) $journal?->transaction_date, (string) $journal?->posting_date, (string) $journal?->effective_date, $journal?->period_id, $journal?->status])
            ->toBe(['2026-10-01', '2026-10-01', '2026-10-01', ($this->period)('2026-10-01'), 'pending_approval'])
            ->and([$audit?->actor_user_id, json_decode((string) $audit?->before, true)['transaction_date'], json_decode((string) $audit?->after, true)['transaction_date'], $audit?->reason, $audit?->permission])
            ->toBe([$this->approver, '2026-09-14', '2026-10-01', 'Invoice arrives in October', 'accounting.approve_journal'])
            ->and(($this->pending)($this->september))->toBe([]);

        $close->execute($lockTask, $finance);
        expect(DB::table('fiscal_periods')->where('id', $this->september)->value('status'))->toBe('locked');
    });
});

it('clears the lock once a pending document is approved or rejected, never by an override', function (): void {
    $rent = ($this->journal)('2026-09-14', 'Office rent');
    $fees = ($this->journal)('2026-09-20', 'Audit fees');
    $cfo = userWithPermissions($this->ctx['tenant_id'], ['accounting.approve_journal', 'accounting.post_in_soft_locked', 'periods.soft_lock', 'periods.lock', 'periods.reopen']);

    asTenant($this->ctx['tenant_id'], function () use ($rent, $fees, $cfo): void {
        $periods = app(FiscalPeriodService::class);
        $periods->softLock($this->september, $cfo);
        $refusal = thrownBy(fn () => $periods->lock($this->september, $cfo), PeriodTransitionException::class);
        expect($refusal->reasonCode)->toBe('PERIOD_HAS_PENDING_DOCUMENTS')->and(array_column($refusal->details['pending'], 'id'))->toBe([$rent, $fees])
            ->and($refusal->getMessage())->toContain('2 manual journals');

        app(ManualJournalService::class)->approve($rent, $cfo); // posts into the soft-locked month (accounting.post_in_soft_locked)
        app(ManualJournalService::class)->reject($fees, $cfo, 'Duplicate of the August accrual');
        expect(($this->pending)($this->september))->toBe([]);
        $periods->lock($this->september, $cfo);
        expect(DB::table('fiscal_periods')->where('id', $this->september)->value('status'))->toBe('locked');
    });
});
