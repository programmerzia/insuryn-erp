<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\SubmitAccountingEvent;
use App\Modules\Accounting\Infrastructure\Jobs\PostAccountingEventJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** Design §8.2: the event, its outbox row and the fast-path posting dispatch come from one submission. */
it('records an outbox row and queues posting on the posting queue', function (): void {
    Queue::fake();
    $ctx = seedDemoTenant();

    asTenant($ctx['tenant_id'], function () use ($ctx): void {
        $event = DB::transaction(fn () => app(SubmitAccountingEvent::class)(
            $ctx['entity_id'], 'PREMIUM_RECEIVED', 'test', (string) Str::uuid7(), 'submit-1',
            CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-15'), 'BDT', ['amount' => 1000], ['branch' => $ctx['branch_id']]));

        $outboxEventIds = DB::table('outbox')->where('message_type', 'PostAccountingEvent')->pluck('payload')
            ->map(fn (string $payload): mixed => json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['event_id'])->all();

        expect($outboxEventIds)->toBe([$event->id]);
        Queue::assertPushedOn('posting', PostAccountingEventJob::class,
            fn (PostAccountingEventJob $job): bool => $job->eventId === $event->id && $job->tenantId === $ctx['tenant_id']);
    });
});
