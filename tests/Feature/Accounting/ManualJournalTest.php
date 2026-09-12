<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\LedgerQuery;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalLine;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalRequest;
use App\Modules\Accounting\Application\ManualJournals\ManualJournalService;
use App\Modules\Accounting\Domain\Enums\JournalKind;
use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Exceptions\ManualJournalException;
use App\Modules\Platform\Approvals\ApprovalException;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Authorization\SodViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Design §5.2 journal: draft ─▶ pending_approval ─approve─▶ approved ─▶ posted, reject ─▶ cancelled.
 * D-11: maker ≠ checker always; an approval policy whose amount threshold matches routes the journal
 * through its steps. §6.2 INVARIANT: control accounts reject manual journals unless the actor has
 * accounting.post_to_control and the journal is kind=adjustment with a reason.
 */
beforeEach(function (): void {
    $this->ctx = seedDemoTenant();
    $this->maker = userWithPermissions($this->ctx['tenant_id'], ['accounting.create_manual_journal', 'accounting.approve_journal']);
    $this->checker = userWithPermissions($this->ctx['tenant_id'], ['accounting.approve_journal']);
});

function journals(): ManualJournalService
{
    return app(ManualJournalService::class);
}

/**
 * @param array{tenant_id: string, entity_id: string, branch_id: string, book_id: string, accounts: array<string, string>} $ctx
 */
function manualJournal(array $ctx, int $amount = 150_000, string $debitRole = 'bank_main', string $creditRole = 'retained_earnings',
    JournalKind $kind = JournalKind::Manual, ?string $reason = 'accrual correction'): ManualJournalRequest
{
    return new ManualJournalRequest(
        entityId: $ctx['entity_id'],
        transactionDate: CarbonImmutable::parse('2026-09-20'),
        description: 'Month-end accrual',
        kind: $kind,
        reason: $reason,
        currency: 'BDT',
        lines: [
            new ManualJournalLine($ctx['accounts'][$debitRole], Side::Debit, $amount, ['branch' => $ctx['branch_id']], 'debit leg'),
            new ManualJournalLine($ctx['accounts'][$creditRole], Side::Credit, $amount, ['branch' => $ctx['branch_id']], 'credit leg'),
        ],
    );
}

it('posts a manual journal only after a different user approves it', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $draft = journals()->create(manualJournal($this->ctx), $this->maker);
        expect($draft->status->value)->toBe('draft')->and($draft->number)->toBeNull()
            ->and(app(LedgerQuery::class)->balance($this->ctx['accounts']['bank_main'], $this->ctx['book_id'], CarbonImmutable::parse('2026-09-30')))->toBe(0);

        expect(journals()->submit($draft->id, $this->maker))->toBeNull(); // no policy: single checker
        expect(Journal::query()->findOrFail($draft->id)->status->value)->toBe('pending_approval');

        // The maker holds accounting.approve_journal too, but may not check their own journal.
        expect(fn () => journals()->approve($draft->id, $this->maker))->toThrow(SodViolation::class);

        $posted = journals()->approve($draft->id, $this->checker);

        expect($posted->status->value)->toBe('posted')
            ->and($posted->number)->toStartWith('JV-2026-')
            ->and($posted->getAttribute('approved_by'))->toBe($this->checker)
            ->and($posted->getAttribute('created_by'))->toBe($this->maker)
            ->and(app(LedgerQuery::class)->balance($this->ctx['accounts']['bank_main'], $this->ctx['book_id'], CarbonImmutable::parse('2026-09-30')))->toBe(150_000)
            ->and(DB::table('audit_events')->where('object_id', $draft->id)->where('action', 'journal.posted')->value('actor_user_id'))->toBe($this->checker);
    });
});

it('requires the approve permission from the checker', function (): void {
    $bystander = userWithPermissions($this->ctx['tenant_id'], ['accounting.view_journals']);

    asTenant($this->ctx['tenant_id'], function () use ($bystander): void {
        $draft = journals()->create(manualJournal($this->ctx), $this->maker);
        journals()->submit($draft->id, $this->maker);

        expect(thrownBy(fn () => journals()->approve($draft->id, $bystander), PermissionDenied::class)->permission)->toBe('accounting.approve_journal');
    });
});

it('refuses drafts that are unbalanced, too short, or use another entity\'s accounts', function (): void {
    $otherTenant = seedDemoTenant('manual-other');

    asTenant($this->ctx['tenant_id'], function () use ($otherTenant): void {
        $request = manualJournal($this->ctx);
        $unbalanced = new ManualJournalRequest($request->entityId, $request->transactionDate, $request->description, $request->kind, $request->reason, 'BDT',
            [$request->lines[0], new ManualJournalLine($this->ctx['accounts']['retained_earnings'], Side::Credit, 149_999, [], null)]);
        $single = new ManualJournalRequest($request->entityId, $request->transactionDate, $request->description, $request->kind, $request->reason, 'BDT', [$request->lines[0]]);
        $foreign = new ManualJournalRequest($request->entityId, $request->transactionDate, $request->description, $request->kind, $request->reason, 'BDT',
            [$request->lines[0], new ManualJournalLine($otherTenant['accounts']['retained_earnings'], Side::Credit, 150_000, [], null)]);

        expect(thrownBy(fn () => journals()->create($unbalanced, $this->maker), \App\Modules\Accounting\Exceptions\UnbalancedJournalException::class)->reasonCode)->toBe('UNBALANCED')
            ->and(thrownBy(fn () => journals()->create($single, $this->maker), ManualJournalException::class)->reasonCode)->toBe('TOO_FEW_LINES')
            ->and(thrownBy(fn () => journals()->create($foreign, $this->maker), ManualJournalException::class)->reasonCode)->toBe('UNKNOWN_ACCOUNT')
            ->and(Journal::query()->count())->toBe(0);
    });
});

it('routes journals at or above the policy threshold through all steps in order', function (): void {
    approvalPolicy($this->ctx['tenant_id'], 'journal', ['min_amount_minor' => 1_000_000], [['permission' => 'accounting.approve_journal'], ['permission' => 'periods.reopen']]);
    $cfo = userWithPermissions($this->ctx['tenant_id'], ['periods.reopen']);
    $financeAndCfo = userWithPermissions($this->ctx['tenant_id'], ['accounting.approve_journal', 'periods.reopen']);

    asTenant($this->ctx['tenant_id'], function () use ($cfo, $financeAndCfo): void {
        $small = journals()->create(manualJournal($this->ctx, 999_999), $this->maker);
        expect(journals()->submit($small->id, $this->maker))->toBeNull()
            ->and(journals()->approve($small->id, $this->checker)->status->value)->toBe('posted');

        $large = journals()->create(manualJournal($this->ctx, 1_000_000), $this->maker);
        expect(journals()->submit($large->id, $this->maker))->toBeString();

        expect(thrownBy(fn () => journals()->approve($large->id, $cfo), PermissionDenied::class)->permission)->toBe('accounting.approve_journal');

        expect(journals()->approve($large->id, $financeAndCfo)->status->value)->toBe('pending_approval');
        expect(thrownBy(fn () => journals()->approve($large->id, $financeAndCfo), ApprovalException::class)->reasonCode)->toBe('APPROVER_ALREADY_DECIDED');

        $posted = journals()->approve($large->id, $cfo);
        expect($posted->status->value)->toBe('posted')->and($posted->getAttribute('approved_by'))->toBe($cfo)
            ->and(DB::table('approval_decisions')->count())->toBe(2)
            ->and(DB::table('approvals')->where('object_id', $large->id)->value('status'))->toBe('approved');
    });
});

it('cancels a rejected journal without posting it', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $draft = journals()->create(manualJournal($this->ctx), $this->maker);
        journals()->submit($draft->id, $this->maker);

        expect(thrownBy(fn () => journals()->reject($draft->id, $this->checker, ''), ManualJournalException::class)->reasonCode)->toBe('REASON_REQUIRED');
        journals()->reject($draft->id, $this->checker, 'wrong period');

        $journal = Journal::query()->findOrFail($draft->id);
        expect($journal->status->value)->toBe('cancelled')->and($journal->number)->toBeNull()
            ->and(fn () => journals()->approve($draft->id, $this->checker))->toThrow(ManualJournalException::class);
    });
});

it('rejects manual journals on control accounts unless adjustment, reason and post_to_control all hold', function (): void {
    $controller = userWithPermissions($this->ctx['tenant_id'], ['accounting.create_manual_journal', 'accounting.post_to_control']);
    $controlChecker = userWithPermissions($this->ctx['tenant_id'], ['accounting.approve_journal', 'accounting.post_to_control']);

    asTenant($this->ctx['tenant_id'], function () use ($controller, $controlChecker): void {
        $onControl = fn (JournalKind $kind, ?string $reason): ManualJournalRequest => manualJournal($this->ctx, 50_000, 'premium_receivable', 'retained_earnings', $kind, $reason);

        expect(thrownBy(fn () => journals()->create($onControl(JournalKind::Manual, 'fix'), $controller), ManualJournalException::class)->reasonCode)->toBe('CONTROL_ACCOUNT')
            ->and(thrownBy(fn () => journals()->create($onControl(JournalKind::Adjustment, null), $controller), ManualJournalException::class)->reasonCode)->toBe('CONTROL_ACCOUNT')
            ->and(thrownBy(fn () => journals()->create($onControl(JournalKind::Adjustment, 'fix'), $this->maker), ManualJournalException::class)->reasonCode)->toBe('CONTROL_ACCOUNT');

        $adjustment = journals()->create($onControl(JournalKind::Adjustment, 'subledger variance fix'), $controller);
        journals()->submit($adjustment->id, $controller);

        expect(thrownBy(fn () => journals()->approve($adjustment->id, $this->checker), ManualJournalException::class)->reasonCode)->toBe('CONTROL_ACCOUNT');
        expect(journals()->approve($adjustment->id, $controlChecker)->status->value)->toBe('posted');
    });
});
