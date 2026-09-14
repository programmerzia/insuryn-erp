<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\Reversals\ReversalRequestService;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/**
 * Gap fix GA-04: the approvals inbox shows what approving does. For a manual journal or a reversal the approver sees the lines the final approval posts,
 * the step ("Step 2 of 2"), the request time on the company clock and a link to the object; approving previews those entries as a posting (never
 * "nothing is posted yet" when it posts), while an earlier step previews that nothing is posted.
 */
beforeEach(function (): void {
    Queue::fake();
    $this->withoutVite();
    travelTo(CarbonImmutable::parse('2026-09-14 07:43:57'));
    $this->ctx = seedDemoTenant();
    $this->headers = ['X-Tenant' => $this->ctx['tenant_id']];
    $this->preview = [...$this->headers, 'X-Journal-Preview' => '1', 'Accept' => 'application/json'];
    $user = fn (array $permissions): User => asTenant($this->ctx['tenant_id'], fn (): User => User::query()->findOrFail(userWithPermissions($this->ctx['tenant_id'], array_values($permissions))));
    $this->maker = $user(['accounting.create_manual_journal', 'accounting.reverse_journal']);
    $this->manager = $user(['accounting.approve_journal']);
    $this->cfo = $user(['periods.reopen', 'accounting.approve_journal']);
});

it('shows the lines a manual journal posts at the last step, and previews the approval as the posting it is', function (): void {
    approvalPolicy($this->ctx['tenant_id'], 'journal', ['min_amount_minor' => 1_000_000], [['permission' => 'accounting.approve_journal'], ['permission' => 'periods.reopen']]);
    $journalId = asTenant($this->ctx['tenant_id'], function (): string {
        $journals = app(ManualJournalService::class);
        $journal = $journals->create(new ManualJournalRequest($this->ctx['entity_id'], CarbonImmutable::parse('2026-09-14'), 'Office rent September', JournalKind::Manual, 'Rent for September', 'BDT', [
            new ManualJournalLine($this->ctx['accounts']['salary_expense'], Side::Debit, 3_500_000, ['branch' => $this->ctx['branch_id']]),
            new ManualJournalLine($this->ctx['accounts']['bank_main'], Side::Credit, 3_500_000, ['branch' => $this->ctx['branch_id']]),
        ]), $this->maker->id);
        $journals->submit($journal->id, $this->maker->id);

        return $journal->id;
    });
    $approvalId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('approvals')->where('object_id', $journalId)->value('id'));
    $lines = [['account' => '5300', 'name' => 'Salaries', 'debit' => '35,000.00', 'credit' => null], ['account' => '1010', 'name' => 'Bank - Main', 'debit' => null, 'credit' => '35,000.00']];

    // Step 1 of 2: the manager sees the entries, but approving only passes it on.
    actingAs($this->manager)->get('/approvals', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->component('approvals/Index')
        ->where('approvals.0.step', 1)->where('approvals.0.steps_total', 2)->where('approvals.0.final_step', false)
        ->where('approvals.0.requested_at_label', '14 Sep 2026, 13:43')
        ->where('approvals.0.link', "/accounting/journals/{$journalId}")->where('approvals.0.preview.link_label', 'Open the journal draft')
        ->where('approvals.0.preview.lines', $lines)->where('approvals.0.preview.posts_on_final_step', true)
        ->where('approvals.0.preview.details.0', ['label' => 'Posting date', 'value' => '2026-09-14', 'date' => true])
        ->where('approvals.0.preview.details.2', ['label' => 'Reason', 'value' => 'Rent for September']));
    actingAs($this->manager)->postJson("/approvals/{$approvalId}/decide", ['decision' => 'approved'], $this->preview)->assertOk()
        ->assertJsonPath('posts', false)->assertJsonPath('journals', []);
    actingAs($this->manager)->post("/approvals/{$approvalId}/decide", ['decision' => 'approved'], $this->headers)->assertSessionHasNoErrors();

    // Step 2 of 2: approving posts the journal, so the preview shows it posting — and nothing is written by the preview.
    actingAs($this->cfo)->get('/approvals', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page->where('approvals.0.step', 2)->where('approvals.0.final_step', true));
    actingAs($this->cfo)->postJson("/approvals/{$approvalId}/decide", ['decision' => 'approved'], $this->preview)->assertOk()
        ->assertJsonPath('posts', true)->assertJsonPath('failures', [])
        ->assertJsonPath('journals.0.event', 'MANUAL_JOURNAL')->assertJsonPath('journals.0.date', '2026-09-14')
        ->assertJsonPath('journals.0.lines.0', ['account' => '5300', 'name' => 'Salaries', 'debit' => '35,000.00', 'credit' => null, 'role' => 'salary_expense'])
        ->assertJsonPath('journals.0.totals', ['debit' => '35,000.00', 'credit' => '35,000.00']);
    asTenant($this->ctx['tenant_id'], fn () => expect(DB::table('journals')->where('id', $journalId)->value('status'))->toBe('pending_approval')
        ->and(DB::table('approvals')->where('id', $approvalId)->value('status'))->toBe('pending'));

    actingAs($this->cfo)->post("/approvals/{$approvalId}/decide", ['decision' => 'approved'], $this->headers)->assertSessionHasNoErrors();
    asTenant($this->ctx['tenant_id'], fn () => expect(DB::table('journals')->where('id', $journalId)->value('status'))->toBe('posted'));
});

it('shows the reversal a request posts: the original lines mirrored on the reversal date', function (): void {
    approvalPolicy($this->ctx['tenant_id'], 'journal_reversal', ['min_amount_minor' => 1_000_000], [['permission' => 'accounting.approve_journal']]);
    [$original, $requestId] = asTenant($this->ctx['tenant_id'], function (): array {
        $event = DB::transaction(fn () => app(SubmitAccountingEvent::class)($this->ctx['entity_id'], 'PREMIUM_RECEIVED', 'test', (string) Str::uuid7(), 'ga04-reversal',
            CarbonImmutable::parse('2026-09-10'), CarbonImmutable::parse('2026-09-10'), 'BDT', ['amount' => 2_000_000],
            ['branch' => $this->ctx['branch_id'], 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7()]));
        [$journal] = app(PostingEngine::class)->post($event->id);

        return [$journal, app(ReversalRequestService::class)->request($journal->id, CarbonImmutable::parse('2026-09-14'), 'Cheque bounced', $this->maker->id)];
    });
    $approvalId = asTenant($this->ctx['tenant_id'], fn (): string => (string) DB::table('approvals')->where('object_id', $requestId)->value('id'));

    actingAs($this->manager)->get('/approvals', $this->headers)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('approvals.0.final_step', true)->where('approvals.0.preview.link_label', 'Open the journal to reverse')
        ->where('approvals.0.preview.lines', [['account' => '1010', 'name' => 'Bank - Main', 'debit' => null, 'credit' => '20,000.00'],
            ['account' => '1100', 'name' => 'Premium Receivable', 'debit' => '20,000.00', 'credit' => null]])
        ->where('approvals.0.preview.details.0', ['label' => 'Reversal date', 'value' => '2026-09-14', 'date' => true])
        ->where('approvals.0.preview.details.2', ['label' => 'Reason', 'value' => 'Cheque bounced']));
    actingAs($this->manager)->postJson("/approvals/{$approvalId}/decide", ['decision' => 'approved'], $this->preview)->assertOk()
        ->assertJsonPath('posts', true)->assertJsonPath('journals.0.event', 'REVERSAL')
        ->assertJsonPath('journals.0.lines.0.credit', '20,000.00')->assertJsonPath('journals.0.lines.0.account', '1010');
    asTenant($this->ctx['tenant_id'], fn () => expect(DB::table('journals')->where('id', $original->id)->value('status'))->toBe('posted')
        ->and(DB::table('journals')->where('kind', 'reversal')->count())->toBe(0));
});
