<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\ReversalService;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Exceptions\PostingFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Design §2.3: a journal is reversed at most once. Two requests holding the same posted journal (a
 * double submit, two approvers) must not both reverse it; the second sees the committed status.
 */
it('reverses a journal only once even when a second caller holds a stale copy', function (): void {
    Queue::fake();
    $ctx = seedDemoTenant();

    asTenant($ctx['tenant_id'], function () use ($ctx): void {
        $dims = ['branch' => $ctx['branch_id'], 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7()];
        $event = DB::transaction(fn () => app(SubmitAccountingEvent::class)(
            $ctx['entity_id'], 'PREMIUM_RECEIVED', 'test', (string) Str::uuid7(), 'race-1',
            CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'BDT', ['amount' => 1_000], $dims));
        [$posted] = app(PostingEngine::class)->post($event->id);

        $firstCopy = Journal::query()->findOrFail($posted->id);
        $staleCopy = Journal::query()->findOrFail($posted->id);
        $reversal = app(ReversalService::class)->reverse($firstCopy, CarbonImmutable::parse('2026-09-20'), 'first request', (string) Str::uuid7());

        $failure = thrownBy(fn () => app(ReversalService::class)->reverse($staleCopy, CarbonImmutable::parse('2026-09-20'), 'second request', (string) Str::uuid7()), PostingFailedException::class);

        expect($failure->reasonCode)->toBe('NOT_POSTED')
            ->and(Journal::query()->where('reverses_journal_id', $posted->id)->count())->toBe(1)
            ->and(Journal::query()->findOrFail($posted->id)->reversed_by_journal_id)->toBe($reversal->id);
    });
});
