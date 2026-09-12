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
 * CONTEXT.md non-negotiable #3 applies to reversals too: a reversal is a posting into the period of
 * its date, so a locked period rejects it and a soft-locked period needs accounting.post_in_soft_locked.
 */
beforeEach(function (): void {
    Queue::fake();
    $this->ctx = seedDemoTenant();
    $this->original = asTenant($this->ctx['tenant_id'], function (): Journal {
        $dims = ['branch' => $this->ctx['branch_id'], 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7(), 'product_code' => 'MOTOR', 'lob' => 'motor', 'channel' => 'direct'];
        $event = DB::transaction(fn () => app(SubmitAccountingEvent::class)(
            $this->ctx['entity_id'], 'PREMIUM_RECEIVED', 'test', (string) Str::uuid7(), 'soft-lock',
            CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'BDT', ['amount' => 1000], $dims));
        [$journal] = app(PostingEngine::class)->post($event->id);

        return $journal;
    });
});

/** @param 'soft_locked'|'locked' $status */
function lockSeptember(string $status): void
{
    DB::table('fiscal_periods')->where('period', 3)->update(['status' => $status]);
}

it('rejects a reversal into a soft-locked period without the permission and leaves the original untouched', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        lockSeptember('soft_locked');

        expect(fn () => app(ReversalService::class)->reverse($this->original, CarbonImmutable::parse('2026-09-20'), 'soft lock check', (string) Str::uuid7()))
            ->toThrow(fn (PostingFailedException $e) => expect($e->reasonCode)->toBe('PERIOD_SOFT_LOCKED'));

        $original = Journal::query()->findOrFail($this->original->id);
        expect($original->status->value)->toBe('posted')->and($original->reversed_by_journal_id)->toBeNull()
            ->and(Journal::query()->count())->toBe(1);
    });
});

it('reverses into a soft-locked period when the actor may post there', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        lockSeptember('soft_locked');

        $reversal = app(ReversalService::class)->reverse($this->original, CarbonImmutable::parse('2026-09-20'), 'soft lock check', (string) Str::uuid7(), true);

        expect($reversal->status->value)->toBe('posted')->and($reversal->reverses_journal_id)->toBe($this->original->id)
            ->and(Journal::query()->findOrFail($this->original->id)->status->value)->toBe('reversed');
    });
});

it('rejects a reversal into a locked period even with the soft-lock permission', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        lockSeptember('locked');

        expect(fn () => app(ReversalService::class)->reverse($this->original, CarbonImmutable::parse('2026-09-20'), 'locked check', (string) Str::uuid7(), true))
            ->toThrow(fn (PostingFailedException $e) => expect($e->reasonCode)->toBe('PERIOD_CLOSED'));

        expect(Journal::query()->findOrFail($this->original->id)->status->value)->toBe('posted')->and(Journal::query()->count())->toBe(1);
    });
});
