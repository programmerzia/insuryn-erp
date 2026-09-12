<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Domain\Models\AccountingEvent;
use App\Modules\Accounting\Domain\Models\Journal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * D-10: a database rule that rejects a posting is a business failure with a precise reason code,
 * not an UNEXPECTED dead letter. Race: the period is locked after the application checked it.
 */
beforeEach(function (): void {
    Queue::fake();
    $this->ctx = seedDemoTenant();
});

/**
 * @param array{tenant_id: string, entity_id: string, branch_id: string, book_id: string, accounts: array<string, string>} $ctx
 * @param array<string, int> $payload
 */
function submitPremiumReceived(array $ctx, array $payload, string $key, string $eventType = 'PREMIUM_RECEIVED'): AccountingEvent
{
    return DB::transaction(fn () => app(SubmitAccountingEvent::class)(
        $ctx['entity_id'], $eventType, 'test', (string) Str::uuid7(), $key,
        CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'BDT', $payload,
        ['branch' => $ctx['branch_id'], 'product' => (string) Str::uuid7(), 'policy' => (string) Str::uuid7(),
            'customer' => (string) Str::uuid7(), 'claim' => (string) Str::uuid7()]));
}

it('fails the event with PERIOD_CLOSED when the period is locked between the check and the posting', function (): void {
    asTenant($this->ctx['tenant_id'], function (): void {
        $event = submitPremiumReceived($this->ctx, ['amount' => 1_000], 'race-period');

        $locked = false;
        DB::listen(function (QueryExecuted $query) use (&$locked): void {
            if (! $locked && str_starts_with($query->sql, 'insert into "journal_lines"')) {
                $locked = true; // after the application's period check, before status → posted
                DB::table('fiscal_periods')->where('period', 3)->update(['status' => 'locked']);
            }
        });

        expect(app(PostingEngine::class)->post($event->id))->toBe([])
            ->and($locked)->toBeTrue();

        $event->refresh();
        expect($event->status->value)->toBe('failed')
            ->and($event->failure_reason)->toStartWith('PERIOD_CLOSED:')
            ->and(Journal::query()->count())->toBe(0);
    });
});

it('rejects CLAIM_RESERVED amounts that are not positive (reserve decreases use CLAIM_RESERVE_ADJUSTED)', function (int $amount): void {
    asTenant($this->ctx['tenant_id'], function () use ($amount): void {
        $event = submitPremiumReceived($this->ctx, ['amount' => $amount], 'reserve-'.$amount, 'CLAIM_RESERVED');

        expect(app(PostingEngine::class)->post($event->id))->toBe([]);

        $event->refresh();
        expect($event->status->value)->toBe('failed')
            ->and($event->failure_reason)->toStartWith('AMOUNT_NOT_POSITIVE:')
            ->and(Journal::query()->count())->toBe(0);
    });
})->with(['negative' => -5_000_000, 'zero' => 0]);
