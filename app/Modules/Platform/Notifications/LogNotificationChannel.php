<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications;

use App\Modules\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/** The MVP notification adapter (design §4 "log-only adapter MVP"): the message is written to the application log; nothing leaves the system. */
final class LogNotificationChannel implements NotificationChannel
{
    public function adapter(): string
    {
        return 'log';
    }

    public function deliver(string $channel, OutgoingNotification $notification): void
    {
        Log::info("Notification ({$channel}, log only): {$notification->title}", [
            'tenant_id' => TenantContext::id(), 'channel' => $channel, 'recipient' => $notification->recipientName, 'recipient_id' => $notification->recipientId,
            'subject' => "{$notification->subjectType}:{$notification->subjectId}", 'template' => $notification->templateCode, 'document' => $notification->storedDocumentId,
            'body' => $notification->body,
        ]);
    }
}
