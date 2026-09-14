<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications;

use App\Modules\Platform\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Sends a message through every enabled channel (`erp.notifications.channels`) and records it in `notifications` (slice R9, D-41). At most once per
 * channel for an idempotency key: a key already recorded is not sent again and its existing row is returned. A channel that throws is recorded as
 * `failed` with the message, so the caller's run goes on.
 */
final class Notifier
{
    /** Adapter name → class. Gateway adapters register here when they exist (LATER). */
    private const ADAPTERS = ['log' => LogNotificationChannel::class];

    public function __construct(private readonly Container $container) {}

    /** @return list<string> the notification ids, one per enabled channel (existing ones when already sent) */
    public function send(OutgoingNotification $notification): array
    {
        $ids = [];
        foreach (self::channels() as $channel => $adapterName) {
            $existing = DB::table('notifications')->where('idempotency_key', $notification->idempotencyKey)->where('channel', $channel)->value('id');
            if ($existing !== null) {
                $ids[] = (string) $existing;

                continue;
            }
            $adapter = $this->adapter($adapterName);
            $failure = null;
            try {
                $adapter->deliver($channel, $notification);
            } catch (\Throwable $e) {
                $failure = mb_substr($e->getMessage(), 0, 1000);
            }
            $id = (string) Str::uuid7();
            $now = CarbonImmutable::now();
            $inserted = DB::table('notifications')->insertOrIgnore([
                'id' => $id, 'tenant_id' => TenantContext::id(), 'channel' => $channel, 'adapter' => $adapter->adapter(),
                'recipient_type' => $notification->recipientType, 'recipient_id' => $notification->recipientId, 'recipient_name' => mb_substr($notification->recipientName, 0, 255),
                'recipient_address' => $notification->recipientAddress, 'subject_type' => $notification->subjectType, 'subject_id' => $notification->subjectId,
                'template_code' => $notification->templateCode, 'title' => mb_substr($notification->title, 0, 255), 'body' => $notification->body,
                'stored_document_id' => $notification->storedDocumentId, 'idempotency_key' => $notification->idempotencyKey,
                'status' => $failure === null ? 'sent' : 'failed', 'failure' => $failure, 'sent_at' => $failure === null ? $now : null, 'created_at' => $now,
            ]);
            $ids[] = $inserted === 1 ? $id : (string) DB::table('notifications')->where('idempotency_key', $notification->idempotencyKey)->where('channel', $channel)->value('id');
        }

        return $ids;
    }

    /** @return array<string, string> enabled channel → adapter name */
    public static function channels(): array
    {
        $channels = [];
        foreach ((array) config('erp.notifications.channels', []) as $channel => $settings) {
            if (in_array($channel, ['email', 'sms'], true) && is_array($settings) && (bool) ($settings['enabled'] ?? false)) {
                $channels[(string) $channel] = (string) ($settings['adapter'] ?? 'log');
            }
        }

        return $channels;
    }

    private function adapter(string $name): NotificationChannel
    {
        $class = self::ADAPTERS[$name] ?? throw new InvalidArgumentException("There is no notification adapter named {$name}; available: ".implode(', ', array_keys(self::ADAPTERS)).'.');

        return $this->container->make($class);
    }
}
