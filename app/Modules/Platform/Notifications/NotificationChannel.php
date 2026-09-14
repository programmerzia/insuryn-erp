<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications;

/**
 * A way to reach a customer (Phase 3 design §4 "email/SMS gateway (provider abstraction)", slice R9, DECISION D-41). The MVP adapter only logs
 * (`LogNotificationChannel`); gateway adapters (SSL Wireless for SMS, Twilio, an email provider) are LATER and implement this interface. Channels are
 * configured in `erp.notifications.channels` (`email`, `sms`: enabled flag and adapter name). `Notifier` records every message in `notifications`.
 */
interface NotificationChannel
{
    /** The adapter name used in config and stored on each notification row (e.g. `log`). */
    public function adapter(): string;

    /**
     * Hands the message to the channel. Returns normally when accepted; throws when the gateway refuses (the failure is recorded, not retried here).
     *
     * @param string $channel email | sms
     */
    public function deliver(string $channel, OutgoingNotification $notification): void;
}
