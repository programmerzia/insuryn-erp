<?php

declare(strict_types=1);

namespace App\Http\Preview;

use App\Modules\Accounting\Application\Contracts\PostingDispatcher;

/** Collects the events a previewed action submits instead of queueing them; the preview posts them itself and rolls back. */
final class RecordingPostingDispatcher implements PostingDispatcher
{
    /** @var list<string> */
    public array $eventIds = [];

    public function dispatchAfterCommit(string $tenantId, string $eventId): void
    {
        $this->eventIds[] = $eventId;
    }
}
