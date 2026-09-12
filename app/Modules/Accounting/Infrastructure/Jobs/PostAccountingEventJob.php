<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Jobs;

use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Exceptions\UnexpectedPostingException;
use App\Modules\Platform\Jobs\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Design §8.4: business failures complete the job (the event carries the reason); transient
 * database errors propagate so the queue retries with backoff; unexpected errors dead-letter
 * immediately into failed_jobs, since a retry would only repeat the same crash.
 *
 * §8.3(d) "concurrent workers never post the same event": WithoutOverlapping keyed by event id, taken
 * when a worker starts the job. Not ShouldBeUnique: that lock is taken at dispatch, so a dispatch lost
 * after locking (the case the outbox relay exists for) would block every re-delivery. The status CAS
 * inside PostingEngine remains the final guard.
 */
final class PostAccountingEventJob implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 5;

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120, 300, 300];
    }

    public function __construct(string $tenantId, public readonly string $eventId)
    {
        $this->tenantId = $tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('posting-event:'.$this->eventId))->dontRelease()->expireAfter(600)];
    }

    public function handle(PostingEngine $engine): void
    {
        try {
            $this->withTenant(fn () => $engine->post($this->eventId));
        } catch (UnexpectedPostingException $e) {
            $this->fail($e);
        }
    }
}
