<?php

declare(strict_types=1);

namespace App\Modules\Platform\Messaging;

/**
 * Design addendum §B.2.6 (PD-7): a handler for one outbox message type, container-tagged with this interface. OutboxConsumers delivers unrelayed messages
 * of the type tenant by tenant and marks them relayed in the handler's transaction. Handlers must be idempotent by the source id in the payload
 * (a unique constraint), because delivery is at least once.
 */
interface OutboxConsumer
{
    public function messageType(): string;

    /** @param array<string, mixed> $payload */
    public function handle(array $payload, string $messageId): void;
}
