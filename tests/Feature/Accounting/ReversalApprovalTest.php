<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Periods\FiscalPeriodService;
use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\Reversals\ReversalRequestService;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Platform\Authorization\PermissionDenied;
use App\Modules\Platform\Authorization\SodViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * D-11 / design §2.3: "every reversal requires reason, created_by and approval per policy" — a reversal
 * is requested, then approved by someone other than the requester (and by every policy step when a
 * policy matches), and only then executed. §5.3: reopening a period goes through approval when a
 * policy matches.
 */
beforeEach(function (): void {
    Queue::fake();
    $this->ctx = seedDemoTenant();
    $this->requester = userWithPermissions($this->ctx['tenant_id'], ['accounting.reverse_journal', 'accounting.approve_journal']);
    $this->checker = userWithPermissions($this->ctx['tenant_id'], ['accounting.approve_journal']);
    $this->posted = asTenant($this->ctx['tenant_id'], function (): Journal {
        $event = DB::transaction(fn () => app(SubmitAccountingEvent::class)(
            $this->ctx['entity_id'], 'PREMIUM_RECEIVED', 'test', (string) Str::uuid7(), 'reversal-approval',
            CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'BDT', ['amount' => 2_000_000],
            ['branch' => $this->ctx['branch_id'], 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7()]));
        [$journal] = app(PostingEngine::class)->post($event->id);

        return $journal;
    });
});

function reversals(): ReversalRequestService
{
    return app(ReversalRequestService::class);
}

function journalStatus(string $journalId): string
{
    return Journal::query()->findOrFail($journalId)->status->value;
}

it('reverses only after a different user approves the request', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $requestId = reversals()->request($this->posted->id, CarbonImmutable::parse('2026-09-20'), 'receipt bounced', $this->requester);
        expect(journalStatus($this->posted->id))->toBe('posted');

        expect(fn () => reversals()->approve($requestId, $this->requester))->toThrow(SodViolation::class);
        expect(journalStatus($this->posted->id))->toBe('posted');

        $reversal = reversals()->approve($requestId, $this->checker) ?? throw new RuntimeException('The reversal was not executed.');

        expect(journalStatus($this->posted->id))->toBe('reversed')
            ->and($reversal->reverses_journal_id)->toBe($this->posted->id)
            ->and($reversal->getAttribute('created_by'))->toBe($this->requester)
            ->and($reversal->getAttribute('approved_by'))->toBe($this->checker)
            ->and($reversal->reason)->toBe('receipt bounced')
            ->and(DB::table('journal_reversal_requests')->where('id', $requestId)->value('status'))->toBe('executed');
    });
});

it('requires the reverse permission to request and the approve permission to approve', function (): void {
    $clerk = userWithPermissions($this->ctx['tenant_id'], ['accounting.view_journals']);

    asTenant($this->ctx['tenant_id'], function () use ($clerk): void {
        expect(thrownBy(fn () => reversals()->request($this->posted->id, CarbonImmutable::parse('2026-09-20'), 'x', $clerk), PermissionDenied::class)->permission)->toBe('accounting.reverse_journal');

        $requestId = reversals()->request($this->posted->id, CarbonImmutable::parse('2026-09-20'), 'receipt bounced', $this->requester);
        expect(thrownBy(fn () => reversals()->approve($requestId, $clerk), PermissionDenied::class)->permission)->toBe('accounting.approve_journal');
    });
});

it('follows the approval policy steps when one matches the reversal', function (): void {
    approvalPolicy($this->ctx['tenant_id'], 'journal_reversal', ['min_amount_minor' => 1_000_000], [['permission' => 'accounting.approve_journal'], ['permission' => 'accounting.post_to_control']]);
    $cfo = userWithPermissions($this->ctx['tenant_id'], ['accounting.post_to_control']);

    asTenant($this->ctx['tenant_id'], function () use ($cfo): void {
        $requestId = reversals()->request($this->posted->id, CarbonImmutable::parse('2026-09-20'), 'receipt bounced', $this->requester);

        reversals()->approve($requestId, $this->checker);
        expect(journalStatus($this->posted->id))->toBe('posted');

        reversals()->approve($requestId, $cfo);
        expect(journalStatus($this->posted->id))->toBe('reversed');
    });
});

it('does not reverse a rejected request', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $requestId = reversals()->request($this->posted->id, CarbonImmutable::parse('2026-09-20'), 'receipt bounced', $this->requester);
        reversals()->reject($requestId, $this->checker, 'receipt cleared after all');

        expect(journalStatus($this->posted->id))->toBe('posted')
            ->and(DB::table('journal_reversal_requests')->where('id', $requestId)->value('status'))->toBe('rejected')
            ->and(fn () => reversals()->approve($requestId, $this->checker))->toThrow(App\Modules\Accounting\Exceptions\ReversalRequestException::class);
    });
});

it('reopens a period through approval when a reopen policy matches', function (): void {
    approvalPolicy($this->ctx['tenant_id'], 'fiscal_period_reopen', [], [['permission' => 'accounting.post_to_control']]);
    $closer = userWithPermissions($this->ctx['tenant_id'], ['periods.soft_lock', 'periods.lock', 'periods.reopen']);
    $cfo = userWithPermissions($this->ctx['tenant_id'], ['accounting.post_to_control']);

    asTenant($this->ctx['tenant_id'], function () use ($closer, $cfo): void {
        $september = (string) DB::table('fiscal_periods')->where('period', 3)->value('id');
        $periods = app(FiscalPeriodService::class);
        $periods->softLock($september, $closer);
        $periods->lock($september, $closer);

        $approvalId = $periods->reopen($september, $closer, 'late supplier invoice');

        expect($approvalId)->toBeString()
            ->and(DB::table('fiscal_periods')->where('id', $september)->value('status'))->toBe('locked');

        app(App\Modules\Platform\Approvals\ApprovalService::class)->decide((string) $approvalId, $cfo, App\Modules\Platform\Approvals\Decision::Approved, null);

        expect(DB::table('fiscal_periods')->where('id', $september)->value('status'))->toBe('open')
            ->and(DB::table('audit_events')->where('object_id', $september)->where('action', 'period.reopened')->value('reason'))->toBe('late supplier invoice');
    });
});
