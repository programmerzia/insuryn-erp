<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Contracts;

/**
 * Fast-path hand-off of a submitted accounting event to the posting worker (design §8.2).
 * Delivery is not guaranteed by this port: the outbox row written in the same transaction is.
 */
interface PostingDispatcher
{
    /** Dispatch posting of the event once the caller's transaction commits. */
    public function dispatchAfterCommit(string $tenantId, string $eventId): void;
}
