<?php

declare(strict_types=1);

namespace App\Modules\Platform\Messaging;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Design addendum §B.2.6: runs the outbox consumers for every tenant (scheduled every minute next to the accounting relay). */
final class OutboxConsumerRelayJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function handle(OutboxConsumers $consumers): void
    {
        $consumers->deliverAll();
    }
}
