<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Jobs;

use App\Modules\Accounting\Application\PostingEngine;
use App\Modules\Accounting\Exceptions\UnexpectedPostingException;
use App\Modules\Platform\Jobs\TenantAware;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Design §8.4: business failures complete the job (the event carries the reason); transient
 * database errors propagate so the queue retries with backoff; unexpected errors dead-letter
 * immediately into failed_jobs, since a retry would only repeat the same crash.
 */
final class PostAccountingEventJob implements ShouldQueue, ShouldBeUnique
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

    public function uniqueId(): string
    {
        return $this->eventId;
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
