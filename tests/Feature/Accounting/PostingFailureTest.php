<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\PostingRuleRepository;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Domain\Models\AccountingEvent;
use App\Modules\Accounting\Exceptions\UnexpectedPostingException;
use App\Modules\Accounting\Infrastructure\Jobs\PostAccountingEventJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Design §8.3/§8.4 failure taxonomy. Business failures mark the event failed and the job succeeds;
 * transient database errors leave the event queued so the job retries; anything unexpected marks the
 * event failed and fails the job without retries. No path leaves an event in 'posting'.
 */
beforeEach(function (): void {
    Queue::fake();
    $this->ctx = seedDemoTenant();
    $this->dims = ['branch' => $this->ctx['branch_id'], 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7(), 'product_code' => 'MOTOR', 'lob' => 'motor', 'channel' => 'direct'];
    $this->submit = function (string $type, array $payload, string $key): AccountingEvent {
        return DB::transaction(fn () => app(SubmitAccountingEvent::class)(
            $this->ctx['entity_id'], $type, 'test', (string) Str::uuid7(), $key,
            CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'BDT', $payload, $this->dims));
    };
    $this->rulesDir = sys_get_temp_dir().'/posting-rules-'.Str::random(8);
});

afterEach(function (): void {
    foreach (glob($this->rulesDir.'/*.json') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($this->rulesDir)) {
        rmdir($this->rulesDir);
    }
});

/** A rule whose amount expression passes a string into an int-typed function: a TypeError, not a business failure. */
function useRuleThatCrashesOnStringAmounts(string $dir): void
{
    mkdir($dir);
    file_put_contents($dir.'/TYPE_ERROR.default.json', json_encode([
        'code' => 'TYPE_ERROR.default', 'version' => 1, 'event_type' => 'TYPE_ERROR', 'effective_from' => '2026-01-01', 'books' => ['LOCAL'],
        'lines' => [
            ['role' => 'bank_main', 'side' => 'debit', 'amount' => 'pct(payload.amount, 1000)'],
            ['role' => 'premium_receivable', 'side' => 'credit', 'amount' => 'pct(payload.amount, 1000)'],
        ],
    ], JSON_THROW_ON_ERROR));
    app()->instance(PostingRuleRepository::class, new PostingRuleRepository($dir, app(ExpressionLanguage::class)));
}

function assertNothingWasPosted(): void
{
    expect(DB::table('journals')->count())->toBe(0)
        ->and(DB::table('journal_batches')->count())->toBe(0)
        ->and(DB::table('outbox')->where('message_type', 'JournalPosted')->count())->toBe(0);
}

it('marks a business failure as failed with its reason and rolls back everything else', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        DB::table('account_role_mappings')->where('role_code', 'premium_receivable')->delete();
        $event = ($this->submit)('PREMIUM_RECEIVED', ['amount' => 1000], 'business');

        expect(app(PostingEngine::class)->post($event->id))->toBe([]);

        $event->refresh();
        expect($event->status->value)->toBe('failed')->and($event->failure_reason)->toStartWith('UNMAPPED_ROLE');
        assertNothingWasPosted();
    });
});

it('marks an unexpected error as failed, reports the exception type and fails loudly', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        useRuleThatCrashesOnStringAmounts($this->rulesDir);
        $event = ($this->submit)('TYPE_ERROR', ['amount' => 'x'], 'unexpected');

        $unexpected = thrownBy(fn () => app(PostingEngine::class)->post($event->id), UnexpectedPostingException::class);
        expect($unexpected->getPrevious())->toBeInstanceOf(TypeError::class);

        $event->refresh();
        expect($event->status->value)->toBe('failed')->and($event->failure_reason)->toStartWith('UNEXPECTED: TypeError');
        assertNothingWasPosted();
    });
});

it('does nothing when asked to post an event that already failed', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        useRuleThatCrashesOnStringAmounts($this->rulesDir);
        $event = ($this->submit)('TYPE_ERROR', ['amount' => 'x'], 'failed-twice');
        try {
            app(PostingEngine::class)->post($event->id);
        } catch (UnexpectedPostingException) {
        }
        $reason = $event->fresh()?->failure_reason;

        expect(app(PostingEngine::class)->post($event->id))->toBe([]);

        $event->refresh();
        expect($event->status->value)->toBe('failed')->and($event->failure_reason)->toBe($reason);
        assertNothingWasPosted();
    });
});

it('leaves the event queued and rethrows when the claim hits a lock timeout, so the job retries', function (): void {
    $event = asTenant($this->ctx['tenant_id'], fn () => ($this->submit)('PREMIUM_RECEIVED', ['amount' => 1000], 'transient'));

    // A second session holds the event row locked; the claim then times out with SQLSTATE 55P03.
    config(['database.connections.blocker' => config('database.connections.pgsql')]);
    $blocker = DB::connection('blocker');
    $blocker->statement("select set_config('app.tenant_id', ?, false)", [$this->ctx['tenant_id']]);
    $blocker->beginTransaction();
    $blocker->select('select id from accounting_events where id = ? for update', [$event->id]);
    DB::statement("set lock_timeout = '200ms'");
    try {
        asTenant($this->ctx['tenant_id'], function () use ($event): void {
            $lockTimeout = thrownBy(fn () => app(PostingEngine::class)->post($event->id), QueryException::class);
            expect($lockTimeout->errorInfo[0] ?? null)->toBe('55P03');
        });
    } finally {
        DB::statement('reset lock_timeout');
        $blocker->rollBack();
        DB::purge('blocker');
    }

    asTenant($this->ctx['tenant_id'], function () use ($event): void {
        $event->refresh();
        expect($event->status->value)->toBe('queued')->and($event->failure_reason)->toBeNull();
        assertNothingWasPosted();

        expect(app(PostingEngine::class)->post($event->id))->toHaveCount(1);
        expect($event->fresh()?->status->value)->toBe('posted');
    });
});

it('lets the job succeed on a business failure and dead-letters it on an unexpected error', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        DB::table('account_role_mappings')->where('role_code', 'premium_receivable')->delete();
        $business = ($this->submit)('PREMIUM_RECEIVED', ['amount' => 1000], 'job-business');
        useRuleThatCrashesOnStringAmounts($this->rulesDir);
        $unexpected = ($this->submit)('TYPE_ERROR', ['amount' => 'x'], 'job-unexpected');
        $engine = app(PostingEngine::class);

        $businessJob = (new PostAccountingEventJob($this->ctx['tenant_id'], $business->id))->withFakeQueueInteractions();
        $businessJob->handle($engine);
        $businessJob->assertNotFailed();

        $unexpectedJob = (new PostAccountingEventJob($this->ctx['tenant_id'], $unexpected->id))->withFakeQueueInteractions();
        $unexpectedJob->handle($engine);
        $unexpectedJob->assertFailedWith(UnexpectedPostingException::class);

        expect(AccountingEvent::query()->where('status', 'posting')->count())->toBe(0);
    });
});
