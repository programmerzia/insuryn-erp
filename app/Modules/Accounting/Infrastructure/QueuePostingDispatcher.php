<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure;

use App\Modules\Accounting\Application\Contracts\PostingDispatcher;
use App\Modules\Accounting\Infrastructure\Jobs\PostAccountingEventJob;

final class QueuePostingDispatcher implements PostingDispatcher
{
    public function dispatchAfterCommit(string $tenantId, string $eventId): void
    {
        PostAccountingEventJob::dispatch($tenantId, $eventId)->afterCommit()->onQueue('posting');
    }
}
