<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Domain\Models\AccountingEvent;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Infrastructure\Jobs\OutboxRelayJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Design §8.2 + D-07: the outbox is the delivery guarantee. When the afterCommit dispatch is lost,
 * the relay (a per-tenant loop over the platform `tenants` table, no RLS bypass) posts the event,
 * and relaying again never posts twice.
 */
/** @param array{tenant_id: string, entity_id: string, branch_id: string, book_id: string, accounts: array<string, string>} $ctx */
function submitWithLostDispatch(array $ctx, string $key): AccountingEvent
{
    return asTenant($ctx['tenant_id'], fn (): AccountingEvent => DB::transaction(fn () => app(SubmitAccountingEvent::class)(
        $ctx['entity_id'], 'PREMIUM_RECEIVED', 'test', (string) Str::uuid7(), $key,
        CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'BDT', ['amount' => 1_000],
        ['branch' => $ctx['branch_id'], 'policy' => (string) Str::uuid7(), 'customer' => (string) Str::uuid7()])));
}

it('posts events whose dispatch was lost, for every tenant, exactly once', function (): void {
    $realQueue = app('queue');
    Queue::fake(); // the fast-path dispatch is "lost"
    $tenantA = seedDemoTenant('relay-a');
    $tenantB = seedDemoTenant('relay-b');
    $eventA = submitWithLostDispatch($tenantA, 'relay-a-1');
    $eventB = submitWithLostDispatch($tenantB, 'relay-b-1');
    Queue::swap($realQueue); // relay dispatches for real (sync queue in tests)

    app()->call([new OutboxRelayJob(), 'handle']);
    app()->call([new OutboxRelayJob(), 'handle']);

    foreach ([[$tenantA, $eventA], [$tenantB, $eventB]] as [$ctx, $event]) {
        asTenant($ctx['tenant_id'], function () use ($event): void {
            expect(Journal::query()->count())->toBe(1)
                ->and(AccountingEvent::query()->findOrFail($event->id)->status->value)->toBe('posted')
                ->and(DB::table('outbox')->where('message_type', 'PostAccountingEvent')->whereNull('relayed_at')->count())->toBe(0);
        });
    }
});

it('does not post twice when the relay re-delivers an event that was already posted', function (): void {
    $realQueue = app('queue');
    Queue::fake();
    $ctx = seedDemoTenant();
    $event = submitWithLostDispatch($ctx, 'relay-redeliver');
    Queue::swap($realQueue);

    app()->call([new OutboxRelayJob(), 'handle']);
    asTenant($ctx['tenant_id'], fn () => DB::table('outbox')->update(['relayed_at' => null])); // simulate a crash before the relayed mark stuck
    app()->call([new OutboxRelayJob(), 'handle']);

    asTenant($ctx['tenant_id'], function () use ($event): void {
        expect(Journal::query()->count())->toBe(1)
            ->and(AccountingEvent::query()->findOrFail($event->id)->status->value)->toBe('posted');
    });
});
