<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\ReversalService;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Platform\Audit\Actor;
use App\Modules\Platform\Audit\Audit;
use App\Modules\Platform\Audit\AuditSubject;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * Design §2.1 audit_events (append-only) and spec §7 business audit: who did what to which object, with
 * before/after and reason. Every posted and reversed journal is audited in its posting transaction.
 */
beforeEach(function (): void {
    Queue::fake();
    $this->ctx = seedDemoTenant();
});

/** @param array{tenant_id: string, entity_id: string, branch_id: string, book_id: string, accounts: array<string, string>} $ctx */
function postPremiumReceived(array $ctx, string $key, int $amount = 1_000): Journal
{
    $event = DB::transaction(fn () => app(SubmitAccountingEvent::class)(
        $ctx['entity_id'], 'PREMIUM_RECEIVED', 'test', (string) Str::uuid7(), $key,
        CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'BDT', ['amount' => $amount],
        ['branch' => $ctx['branch_id'], 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7()]));
    [$journal] = app(PostingEngine::class)->post($event->id);

    return $journal;
}

it('records actor, object, before and after, reason and permission', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $objectId = (string) Str::uuid7();
        $actorId = (string) Str::uuid7();

        app(Audit::class)->record('period.reopened', AuditSubject::of('fiscal_period', $objectId), ['status' => 'locked'], ['status' => 'open'],
            'late supplier invoice', 'periods.reopen', Actor::user($actorId));

        $row = DB::table('audit_events')->where('object_id', $objectId)->first();
        expect($row)->not->toBeNull()
            ->and($row?->action)->toBe('period.reopened')
            ->and($row?->object_type)->toBe('fiscal_period')
            ->and($row?->actor_user_id)->toBe($actorId)
            ->and($row?->actor_type)->toBe('user')
            ->and($row?->permission)->toBe('periods.reopen')
            ->and($row?->reason)->toBe('late supplier invoice')
            ->and(json_decode((string) $row?->before, true))->toBe(['status' => 'locked'])
            ->and(json_decode((string) $row?->after, true))->toBe(['status' => 'open']);
    });
});

it('attributes audit rows to the authenticated user with request details', function (): void {
    $user = asTenant($this->ctx['tenant_id'], fn (): User => User::factory()->create());
    Route::middleware(['web', 'auth'])->post('/_test/audit', function () {
        app(Audit::class)->record('test.touched', AuditSubject::of('thing', '01a09780-0000-7000-8000-000000000001'), null, ['ok' => true]);

        return ['ok' => true];
    });

    actingAs($user)->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
        ->postJson('/_test/audit', [], ['X-Tenant' => $this->ctx['tenant_id'], 'User-Agent' => 'audit-test'])->assertOk();

    $row = asTenant($this->ctx['tenant_id'], fn () => DB::table('audit_events')->where('action', 'test.touched')->first());
    expect($row?->actor_user_id)->toBe($user->id)
        ->and($row?->actor_type)->toBe('user')
        ->and($row?->user_agent)->toBe('audit-test')
        ->and($row?->ip)->toBe('127.0.0.1')
        ->and(Str::isUuid((string) $row?->request_id))->toBeTrue();
});

it('records system actions without a user', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        app(Audit::class)->record('batch.ran', AuditSubject::of('batch', (string) Str::uuid7()), null, null);

        $row = DB::table('audit_events')->where('action', 'batch.ran')->first();
        expect($row?->actor_type)->toBe('system')->and($row?->actor_user_id)->toBeNull();
    });
});

it('keeps the audit trail append-only', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $objectId = (string) Str::uuid7();
        app(Audit::class)->record('thing.done', AuditSubject::of('thing', $objectId), null, ['v' => 1]);

        expect(fn () => DB::table('audit_events')->where('object_id', $objectId)->update(['reason' => 'rewritten']))->toThrow(QueryException::class, 'AUDIT_APPEND_ONLY')
            ->and(fn () => DB::table('audit_events')->where('object_id', $objectId)->delete())->toThrow(QueryException::class, 'AUDIT_APPEND_ONLY');
    });
});

it('audits every posted and every reversed journal', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $first = postPremiumReceived($this->ctx, 'audit-1');
        postPremiumReceived($this->ctx, 'audit-2', 2_000);
        $actorId = (string) Str::uuid7();
        $reversal = app(ReversalService::class)->reverse(Journal::query()->findOrFail($first->id), CarbonImmutable::parse('2026-09-20'), 'duplicate receipt', $actorId);

        $journalIds = Journal::query()->whereIn('status', ['posted', 'reversed'])->pluck('id')->all();
        $postedAudits = DB::table('audit_events')->where('action', 'journal.posted')->where('object_type', 'journal')->pluck('object_id')->all();
        $reversedAudit = DB::table('audit_events')->where('action', 'journal.reversed')->where('object_id', $first->id)->first();

        expect($journalIds)->toHaveCount(3)
            ->and(collect($postedAudits)->sort()->values()->all())->toBe(collect($journalIds)->sort()->values()->all())
            ->and($reversedAudit?->reason)->toBe('duplicate receipt')
            ->and($reversedAudit?->actor_user_id)->toBe($actorId)
            ->and($reversedAudit?->permission)->toBe('accounting.reverse_journal')
            ->and(json_decode((string) $reversedAudit?->after, true))->toMatchArray(['status' => 'reversed', 'reversed_by_journal_id' => $reversal->id]);
    });
});

it('writes no audit row when the posting fails', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        DB::table('fiscal_periods')->where('period', 3)->update(['status' => 'locked']);
        postPremiumReceivedIgnoringResult($this->ctx);

        expect(DB::table('audit_events')->where('action', 'journal.posted')->count())->toBe(0);
    });
});

/** @param array{tenant_id: string, entity_id: string, branch_id: string, book_id: string, accounts: array<string, string>} $ctx */
function postPremiumReceivedIgnoringResult(array $ctx): void
{
    $event = DB::transaction(fn () => app(SubmitAccountingEvent::class)(
        $ctx['entity_id'], 'PREMIUM_RECEIVED', 'test', (string) Str::uuid7(), 'audit-failed',
        CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'BDT', ['amount' => 1_000],
        ['branch' => $ctx['branch_id'], 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7()]));
    app(PostingEngine::class)->post($event->id);
}
